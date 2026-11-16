<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Data\Collection;
use SatApi\Data\Repository;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Maintenance;
use SatApi\Support\Problem;
use SatApi\Tle\Parser;

/**
 * TLE endpoints.
 *
 * GET /v1/tle                 merged, de-duplicated feed
 * GET /v1/tle/{source}        single upstream (weather|amateur|amsat|satnogs)
 *
 * Formats: text/plain (default, classical 20-column TLE) and application/json.
 * The legacy /tle routes reuse the same handlers with legacy metadata headers.
 */
final class Tle extends Base
{
    public static function merged(Request $request, array $params, array $route)
    {
        $group = strtolower(trim((string) $request->param('group', 'all')));
        if ($group !== '' && $group !== 'all') {
            if (!in_array($group, Repository::TLE_SOURCES, true)) {
                Problem::send(
                    400,
                    'Bad Request',
                    'Unknown group "' . $group . '". Valid values: all, ' . implode(', ', Repository::TLE_SOURCES) . '.',
                    'bad_request',
                    array('parameter' => 'group')
                );
            }
            $params['source'] = $group;

            self::source($request, $params, $route);

            return;
        }

        Maintenance::enforce('tle_merge', $request);

        $format = self::resolveFormat($request);
        $result = Repository::merged();

        if (empty($result['data'])) {
            self::upstreamFailure('No TLE data could be assembled from any upstream provider.', $result['sources']);
        }

        $records = Collection::onlyNorad($result['data'], $request->list('norad'));
        if (empty($records) && !empty($request->list('norad'))) {
            Problem::send(404, 'Not Found', 'None of the requested NORAD identifiers are present in the feed.', 'not_found', array('parameter' => 'norad'));
        }

        $limit = $request->int('limit', 0, 0, 20000);
        if ($limit > 0 && count($records) > $limit) {
            $records = array_slice($records, 0, $limit, true);
        }

        $ttl = self::minTtl($result['sources']);
        $lastModified = self::lastModified($result['sources']);
        if ($lastModified <= 0) {
            // Only reachable when a source reported data without a cache
            // stamp; keeps Last-Modified sane instead of emitting 1970.
            $lastModified = time();
        }
        $cacheStatus = self::aggregateCacheStatus($result['sources']);
        $cache = array(
            'status'    => $cacheStatus,
            'age'       => max(0, time() - $lastModified),
            'ttl'       => $ttl,
            'backend'   => \SatApi\Cache\Store::backendName(),
            'stored_at' => $lastModified,
        );

        $headers = self::cacheHeaders($cache, $ttl, $route);
        $headers['Last-Modified'] = $lastModified;

        $deferred = self::deferred(isset($result['refresh']) ? $result['refresh'] : array());

        if ($format === 'json') {
            $meta = self::envelopeMeta($request, array(
                'count'    => count($records),
                'sources'  => self::publicSources($result['sources']),
                'cache'    => array('status' => $cacheStatus, 'age_seconds' => $cache['age'], 'ttl' => $ttl),
                'warnings' => $result['warnings'],
                'license'  => 'Upstream data: see /v1/meta#attribution',
            ));
            $headers['ETag'] = self::etag(array(
                'v'      => Config::get('service.version'),
                'format' => 'json',
                'stamp'  => $lastModified,
                'data'   => Parser::toJson($records),
            ));
            self::respondData($request, Parser::toJson($records), $meta, 200, $headers, $deferred);

            return;
        }

        $body = Parser::toTleText($records, self::textHeader($request, $route, $result, $ttl, $lastModified));
        Response::raw($body, 'text/plain; charset=utf-8', 200, $headers, $deferred);
    }

    public static function source(Request $request, array $params, array $route)
    {
        $id = isset($params['source']) ? (string) $params['source'] : '';
        if (!in_array($id, Repository::TLE_SOURCES, true)) {
            Problem::send(404, 'Not Found', 'Unknown TLE source "' . $id . '".', 'not_found', array('parameter' => 'source'));
        }

        Maintenance::enforce('tle_' . $id, $request);

        $format = self::resolveFormat($request);
        $result = Repository::source($id);
        $source = Config::source($id);

        if (empty($result['data'])) {
            // Repository::source() returns {data, cache, error} -- it has no
            // count/ok/cache-status fields. publicSources() reads all of them, so
            // the full shape has to be built here. Without this the 503 path
            // logged "Undefined array key \"count\"" and "… \"cache\"" (Tle.php
            // 230/232) on every request during an upstream outage.
            $cacheStatus = isset($result['cache']['status']) ? (string) $result['cache']['status'] : 'MISS';
            $cacheAge = isset($result['cache']['age']) ? (int) $result['cache']['age'] : 0;
            self::upstreamFailure(
                'Upstream "' . $id . '" is unavailable and no cached copy exists.',
                array($id => array(
                    'id'          => $id,
                    'label'       => isset($source['label']) ? $source['label'] : $id,
                    'count'       => 0,
                    'ok'          => false,
                    'cache'       => $cacheStatus,
                    'age_seconds' => $cacheAge,
                    'ttl'         => isset($source['ttl']) ? (int) $source['ttl'] : 0,
                    'error'       => isset($result['error']) ? (string) $result['error'] : '',
                ))
            );
        }

        $records = Collection::onlyNorad($result['data'], $request->list('norad'));
        $ttl = (int) $source['ttl'];
        $lastModified = isset($result['cache']['stored_at']) ? (int) $result['cache']['stored_at'] : time() - (int) $result['cache']['age'];

        $headers = self::cacheHeaders($result['cache'], $ttl, $route);
        $headers['Last-Modified'] = $lastModified;
        $deferred = self::deferred(isset($result['refresh']) ? array($result['refresh']) : array());

        if ($format === 'json') {
            $meta = self::envelopeMeta($request, array(
                'count'   => count($records),
                'source'  => array(
                    'id'          => $id,
                    'label'       => isset($source['label']) ? $source['label'] : $id,
                    'url'         => $source['url'],
                    'attribution' => isset($source['attribution']) ? $source['attribution'] : '',
                    'cache'       => $result['cache']['status'],
                    'age_seconds' => $result['cache']['age'],
                    'ttl'         => $ttl,
                ),
            ));
            $headers['ETag'] = self::etag(array(
                'v'      => Config::get('service.version'),
                'format' => 'json',
                'source' => $id,
                'stamp'  => $lastModified,
                'data'   => Parser::toJson($records),
            ));
            self::respondData($request, Parser::toJson($records), $meta, 200, $headers, $deferred);

            return;
        }

        $comments = array();
        if (!empty($route['legacy'])) {
            if ($result['cache']['status'] === 'STALE' || $result['cache']['status'] === 'EXPIRED') {
                $comments[] = 'Remote source unavailable, serving cached data (generated '
                    . gmdate('Y-m-d H:i:s', $lastModified) . ' UTC)';
            }
        } else {
            $comments[] = Config::get('service.name', 'AzureOpenAPI for Satellite') . ' v' . Config::get('service.version', '') . ' - source: ' . (isset($source['label']) ? $source['label'] : $id);
            $comments[] = 'Generated: ' . gmdate('Y-m-d\TH:i:s\Z', $lastModified);
            $comments[] = 'Satellites: ' . count($records);
            $comments[] = 'Cache: ' . $result['cache']['status'] . ' (ttl ' . $ttl . 's)';
            $comments[] = 'Docs: ' . Config::get('service.docs', '/docs.html');
        }

        Response::raw(Parser::toTleText($records, $comments), 'text/plain; charset=utf-8', 200, $headers, $deferred);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function resolveFormat(Request $request)
    {
        // `false`: the representation is decided by the URL, never by Accept.
        //
        // Measured through the SCDN edge on 2026-10-28: the response carries
        // `vary: Accept-Encoding` only -- Accept is not in the Vary list -- so once a
        // client warmed `/v1/tle?limit=3` with `Accept: application/json`, the next
        // request for the SAME URL with `Accept: text/plain` received JSON at an edge
        // HIT. A representation that depends on a request header the cache does not
        // key on is the same failure this project already fixed for cookies.
        //
        // So a plain `/v1/tle` is standard TLE text for everyone, and JSON is asked
        // for with `?json=true` (or the older `?format=json`).
        $format = $request->format(array('tle', 'json'), false);
        if ($format === null) {
            Problem::send(
                400,
                'Bad Request',
                'Unsupported format. The default is standard TLE text; send format=tle, '
                    . 'or json=true (or format=json) for JSON. An unrecognised value is rejected '
                    . 'rather than silently falling back to the default.',
                'bad_request',
                array('parameter' => 'format')
            );
        }

        return $format;
    }

    private static function upstreamFailure($detail, array $sources)
    {
        $public = self::publicSources($sources);
        Problem::send(
            503,
            'Service Unavailable',
            $detail,
            'upstream_unavailable',
            array(
                'sources' => $public,
                'hint'    => 'Cached data is served automatically whenever it exists; this response means even the stale copy was unavailable.',
            ),
            array('Retry-After' => '120', 'Cache-Control' => 'no-store')
        );
    }

    /**
     * Normalise the per-source summary for the public payload.
     *
     * Callers feed this from two differently shaped producers
     * (Repository::merged() supplies count/ok/cache/age_seconds/ttl, while
     * Repository::source() supplies only data/cache/error), so every field is
     * read defensively. Requesting an absent key produced a PHP warning on the
     * 503 path and a null in the public document, both of which are failures the
     * caller should never see.
     *
     * @param array<string,array> $sources
     * @return array<int,array>
     */
    private static function publicSources(array $sources)
    {
        $out = array();
        foreach ($sources as $source) {
            $ok = !empty($source['ok']);
            $out[] = array(
                'id'          => isset($source['id']) ? (string) $source['id'] : '',
                'label'       => isset($source['label']) ? (string) $source['label'] : '',
                'count'       => isset($source['count']) ? (int) $source['count'] : 0,
                'ok'          => $ok,
                'cache'       => isset($source['cache']) ? (string) $source['cache'] : '',
                'age_seconds' => isset($source['age_seconds']) ? (int) $source['age_seconds'] : 0,
                'error'       => $ok ? null : (isset($source['error']) ? (string) $source['error'] : ''),
            );
        }

        return $out;
    }

    private static function minTtl(array $sources)
    {
        $ttl = null;
        foreach ($sources as $source) {
            if (empty($source['ok'])) {
                continue;
            }
            $ttl = $ttl === null ? (int) $source['ttl'] : min($ttl, (int) $source['ttl']);
        }

        return $ttl === null ? 900 : $ttl;
    }

    /**
     * Newest cache stamp across the sources, or 0 when nothing is cached.
     * Never returns the wall clock: a moving timestamp would defeat both
     * ETag revalidation and CDN caching.
     */
    private static function lastModified(array $sources)
    {
        $latest = 0;
        foreach ($sources as $source) {
            if (!empty($source['stored_at']) && $source['stored_at'] > $latest) {
                $latest = (int) $source['stored_at'];
            }
        }

        return $latest;
    }

    private static function aggregateCacheStatus(array $sources)
    {
        $statuses = array();
        foreach ($sources as $source) {
            $statuses[] = $source['cache'];
        }
        if (in_array('MISS', $statuses, true)) {
            return 'MISS';
        }
        if (in_array('STALE', $statuses, true) || in_array('EXPIRED', $statuses, true)) {
            return 'STALE';
        }

        return empty($statuses) ? 'MISS' : 'HIT';
    }

    /**
     * Comment block placed above the TLE payload.
     *
     * The "generated" stamp is derived from the cache entry, not from the wall
     * clock, so the same cached payload always renders byte-identically - which
     * is what makes ETag revalidation and CDN caching effective.
     *
     * @return string[]
     */
    private static function textHeader(Request $request, array $route, array $result, $ttl, $lastModified)
    {
        if (!$request->bool('meta', true)) {
            return array();
        }
        foreach (array('meta', 'include_meta') as $flag) {
            $value = $request->param($flag, null);
            if ($value !== null && in_array(strtolower((string) $value), array('0', 'false', 'no', 'off'), true)) {
                return array();
            }
        }

        $comments = array();
        $sources = $result['sources'];

        if (!empty($route['legacy'])) {
            $comments[] = 'Source status:';
            $order = array('weather', 'amateur', 'amsat', 'satnogs');
            foreach ($order as $id) {
                if (!isset($sources[$id])) {
                    continue;
                }
                $source = $sources[$id];
                $label = $source['label'];
                switch ($source['cache']) {
                    case 'MISS':
                        $state = 'remote ok';
                        break;
                    case 'HIT':
                    case 'STALE':
                        $state = 'cache (age: ' . self::formatAge($source['age_seconds']) . ')';
                        break;
                    default:
                        $state = $source['ok'] ? 'remote ok' : 'failed (no cache)';
                }
                $comments[] = '  ' . $label . ': ' . $state;
            }
            $comments[] = 'Merged satellites: ' . count($result['data']);
            $comments[] = 'Generated: ' . gmdate('Y-m-d H:i:s', $lastModified) . ' UTC';
        } else {
            $comments[] = Config::get('service.name', 'AzureOpenAPI for Satellite') . ' v' . Config::get('service.version', '');
            $comments[] = 'Generated: ' . gmdate('Y-m-d\TH:i:s\Z', $lastModified);
            $comments[] = 'Satellites: ' . count($result['data']);
            $comments[] = 'Cache TTL: ' . $ttl . 's';
            foreach ($sources as $source) {
                $comments[] = sprintf(
                    'Source %-9s %-22s %5d sats  cache=%s',
                    $source['id'],
                    $source['label'],
                    $source['count'],
                    $source['cache']
                );
            }
            $comments[] = 'Docs: ' . Config::get('service.docs', '/docs.html');
        }

        foreach ($result['warnings'] as $warning) {
            $comments[] = 'WARNING: ' . $warning;
        }

        return $comments;
    }

    private static function formatAge($seconds)
    {
        $seconds = (int) $seconds;
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;

        return sprintf('%dh %02dm %02ds', $h, $m, $s);
    }
}
