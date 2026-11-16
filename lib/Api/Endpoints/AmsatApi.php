<?php

namespace SatApi\Api\Endpoints;

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Http\Client;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Maintenance;
use SatApi\Support\Metrics;
use SatApi\Support\Problem;

/**
 * Transparent proxy for the AMSAT Satellite Status API (v1).
 *
 *   GET /v1/amsat/catalog?name=&include_stats=
 *   GET /v1/amsat/reports?name=&hours=&since=&limit=&callsign=&grid_square=&status=
 *   GET /v1/amsat/summary?hours=
 *   GET /v1/amsat/statuses
 *   GET /v1/amsat/health
 *   GET /v1/amsat/satellites            (upstream legacy: plain array)
 *   GET /v1/amsat/sat-info?name=&hours= (upstream legacy: plain array)
 *
 * WHY A PROXY AND NOT A PARSER
 *
 * The other AMSAT route in this application (/v1/amsat/status) scrapes
 * amsat.org/status, an HTML page, and normalises it into our own envelope. This
 * one is the opposite: AMSAT now publishes a documented JSON API, so the useful
 * thing is to relay it faithfully -- same shapes, same field names, same query
 * parameters -- and let a client read AMSAT's own documentation. Wrapping it in our
 * envelope would only add a second thing to keep in sync.
 *
 * That is why the body is returned BYTE-FOR-BYTE. The only things this class adds
 * are the cache headers this service adds to every response and one extra header
 * naming the upstream source.
 *
 * WHAT IS DELIBERATELY NOT PROXIED
 *
 * `POST /reports.php` (submit an observation). This deployment is a read-only
 * mirror, and relaying writes would make it an anonymous relay into AMSAT's
 * database. AMSAT's own guidelines require that a report be a real observation made
 * by the operator whose callsign it carries; a proxy cannot verify that, so the
 * route answers 405 and the documentation says where to POST instead.
 *
 * POLLING DISCIPLINE
 *
 * AMSAT's terms ask clients not to poll more often than every 5 minutes. The cache
 * TTL defaults to 300s and is applied per canonical URL, so a repeated request
 * never reaches AMSAT more than once per window. Query parameters are normalised
 * first (see canonicalQuery): only documented parameters survive, numbers are
 * clamped to the documented range, and the result is sorted -- so `?hours=6&name=ao-91`
 * and `?name=AO-91&hours=6` share one entry instead of polling twice. Unknown
 * parameters are dropped rather than forwarded, which also stops arbitrary query
 * strings from turning the cache into an unbounded set of upstream polls.
 */
final class AmsatApi extends Base
{
    /**
     * The proxied endpoints, keyed by the name that appears in OUR path.
     *
     * `params` is the whole of the input surface: it is both the whitelist and the
     * canonicalisation rule. Ranges are the ones AMSAT's own OpenAPI document
     * publishes (hours 1-720, limit 1-500, sat_info hours 1-96), so a value outside
     * them is corrected here rather than rejected by the upstream with a 400 we
     * could have predicted.
     */
    const SOURCES = array(
        'catalog' => array(
            'file'   => 'catalog.php',
            'label'  => 'AMSAT satellite catalog',
            'params' => array('name' => 'string', 'include_stats' => 'bool'),
        ),
        'reports' => array(
            'file'   => 'reports.php',
            'label'  => 'AMSAT status reports',
            'params' => array(
                'name'        => 'string',
                'hours'       => 'int:1:720',
                'since'       => 'datetime',
                'limit'       => 'int:1:500',
                'callsign'    => 'string',
                'grid_square' => 'string',
                'status'      => 'enum:Heard,Telemetry Only,Not Heard,Crew Active',
            ),
        ),
        'summary' => array(
            'file'   => 'summary.php',
            'label'  => 'AMSAT report summary',
            'params' => array('hours' => 'int:1:720'),
        ),
        'statuses' => array(
            'file'   => 'statuses.php',
            'label'  => 'AMSAT canonical report statuses',
            'params' => array(),
        ),
        'health' => array(
            'file'   => 'health.php',
            'label'  => 'AMSAT API health',
            'params' => array(),
        ),
        'satellites' => array(
            'file'   => 'satellites.php',
            'label'  => 'AMSAT legacy catalog',
            'params' => array(),
        ),
        'sat-info' => array(
            'file'   => 'sat_info.php',
            'label'  => 'AMSAT legacy report list',
            'params' => array('name' => 'string', 'hours' => 'int:1:96'),
        ),
    );

    public static function handle(Request $request, array $params, array $route)
    {
        $source = isset($route['amsat_source']) ? (string) $route['amsat_source'] : '';
        if (!isset(self::SOURCES[$source])) {
            Problem::send(404, 'Not Found', 'Unknown AMSAT upstream.', 'not_found');

            return;
        }

        // Same provider as /v1/amsat/status, so it shares the maintenance switch:
        // taking AMSAT down for maintenance has to silence both, or a client gets a
        // 503 from one route and a working one from the other.
        Maintenance::enforce('amsat_api', $request);

        $spec = self::SOURCES[$source];
        $query = self::canonicalQuery($source, $request->all());
        $url = self::baseUrl() . $spec['file'] . ($query === '' ? '' : '?' . $query);
        $ttl = max(30, (int) Config::get('amsat_api.ttl', 300));
        $timeout = (int) Config::get('amsat_api.timeout', 8);

        try {
            $entry = Store::remember(
                'amsat-api:' . $source . ':' . sha1($url),
                $ttl,
                static function () use ($url, $timeout) {
                    $result = Client::get($url, array(
                        'accept'  => 'application/json',
                        'timeout' => $timeout,
                        // A 4xx from AMSAT carries the answer ("no such satellite"),
                        // so the body has to survive the client's usual discard.
                        'keep_error_body' => true,
                    ));
                    Metrics::upstream('amsat_api', $result['ok'], $result['ms'], $result['error']);

                    if ((int) $result['status'] === 0) {
                        throw new \RuntimeException('upstream unreachable: ' . $result['error']);
                    }

                    // Validate before caching: a captive portal or an error page that
                    // happens to be `text/html` would otherwise be stored for the whole
                    // TTL and served as JSON to everybody.
                    $decoded = json_decode($result['body'], true);
                    if (!is_array($decoded)) {
                        throw new \RuntimeException(
                            'upstream returned HTTP ' . $result['status'] . ' without a JSON body'
                        );
                    }

                    // A 5xx is a FAILURE and must not be cached: the caller gets our 503
                    // (or the stale copy), and the next request tries again.
                    if ((int) $result['status'] >= 500) {
                        throw new \RuntimeException('upstream error HTTP ' . $result['status']);
                    }

                    // A 2xx and a 4xx are both ANSWERS -- "here is the document" and "no
                    // such satellite" are equally cachable facts, and relaying the status
                    // is what a proxy is for. Measured 2026-11-03: the upstream answers
                    // `name=AO-91` with a 404 `satellite_not_found` document because its
                    // catalog is empty, and turning that into our 503 hid the real answer.
                    return array('status' => (int) $result['status'], 'body' => $result['body']);
                },
                array('codec' => 'serialize')
            );
        } catch (\Throwable $e) {
            Problem::send(
                503,
                'Service Unavailable',
                'The AMSAT status API is unavailable and no cached copy of "' . $source . '" exists.',
                'upstream_unavailable',
                array('source' => array(
                    'id'    => 'amsat_api',
                    'label' => $spec['label'] . ' (' . $spec['file'] . ')',
                    'error' => $e->getMessage(),
                )),
                array('Retry-After' => '120', 'Cache-Control' => 'no-store')
            );

            return;
        }

        $cache = array(
            'status'  => isset($entry['status']) ? $entry['status'] : 'MISS',
            'age'     => isset($entry['age']) ? (int) $entry['age'] : 0,
            'backend' => isset($entry['backend']) ? $entry['backend'] : '',
        );
        $headers = self::cacheHeaders($cache, $ttl, $route);
        // Which upstream document this is. Cheap, and it saves a client from
        // guessing after a copy/paste of the wrong URL.
        $headers['X-Amsat-Source'] = $source;
        if (!empty($entry['error'])) {
            $headers['Warning'] = '110 - "served stale after upstream error"';
        }

        $value = isset($entry['value']) ? $entry['value'] : array();
        $status = is_array($value) && isset($value['status']) ? (int) $value['status'] : 200;
        $body = is_array($value) && isset($value['body']) ? (string) $value['body'] : (string) $value;

        Response::raw(
            $body,
            'application/json; charset=utf-8',
            $status,
            $headers,
            self::deferred(isset($entry['refresh']) ? array($entry['refresh']) : array())
        );
    }

    /** The upstream root, with a trailing slash. */
    private static function baseUrl()
    {
        return rtrim((string) Config::get('amsat_api.base', 'https://status.amsat.org/api/v1/'), '/') . '/';
    }

    /**
     * Whitelist, clamp, canonicalise.
     *
     * The output is what gets both forwarded AND hashed into the cache key, so this
     * function is the single place that decides how many upstream documents this
     * service can ever hold. Public so the test can assert it without a network.
     *
     * @param array<string,mixed> $input
     * @return string query string, '' when there are no parameters
     */
    public static function canonicalQuery($source, array $input)
    {
        if (!isset(self::SOURCES[$source])) {
            return '';
        }

        $out = array();
        foreach (self::SOURCES[$source]['params'] as $name => $rule) {
            if (!isset($input[$name]) || is_array($input[$name])) {
                continue;
            }
            $raw = trim((string) $input[$name]);
            if ($raw === '') {
                continue;
            }
            $value = self::normalise($rule, $raw);
            if ($value === null) {
                continue;
            }
            $out[$name] = $value;
        }

        // Sorted so parameter order cannot create a second cache entry for the same
        // request; RFC 3986 so a space in `status=Telemetry Only` is %20 rather than
        // a `+` that some proxies would re-encode differently.
        ksort($out);

        return http_build_query($out, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Apply one parameter rule. Returns null when the value is unusable, which
     * means "drop it" rather than "fail": a typo in an optional filter should
     * not take the endpoint down, and the upstream would ignore it anyway.
     */
    private static function normalise($rule, $raw)
    {
        if ($rule === 'string') {
            return strlen($raw) > 200 ? substr($raw, 0, 200) : $raw;
        }

        if ($rule === 'bool') {
            $lower = strtolower($raw);

            return in_array($lower, array('1', 'true', 'yes', 'on'), true) ? 'true'
                : (in_array($lower, array('0', 'false', 'no', 'off'), true) ? 'false' : null);
        }

        if ($rule === 'datetime') {
            $stamp = strtotime($raw);
            if ($stamp === false) {
                return null;
            }

            // One spelling reaches AMSAT and one reaches the cache key, so
            // `2026-11-03T13:00:00Z` and `2026-11-03 21:00:00+08:00` are the same
            // request instead of two upstream polls 5 minutes apart.
            return gmdate('Y-m-d\TH:i:s\Z', $stamp);
        }

        if (strpos($rule, 'enum:') === 0) {
            $allowed = explode(',', substr($rule, 5));
            foreach ($allowed as $candidate) {
                if (strcasecmp($candidate, $raw) === 0) {
                    return $candidate; // canonical spelling, not the client's casing
                }
            }

            return null;
        }

        if (strpos($rule, 'int:') === 0) {
            if (!preg_match('/^-?\d+$/', $raw)) {
                return null;
            }
            $parts = explode(':', $rule);
            $min = isset($parts[1]) ? (int) $parts[1] : PHP_INT_MIN;
            $max = isset($parts[2]) ? (int) $parts[2] : PHP_INT_MAX;

            return (string) max($min, min($max, (int) $raw));
        }

        return null;
    }
}
