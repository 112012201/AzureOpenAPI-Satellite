<?php

namespace SatApi\Api\Endpoints;

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Data\Repository;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Maintenance;
use SatApi\Support\Metrics;
use SatApi\Support\Problem;

/**
 * Operational endpoints.
 *
 * GET  /v1/health             aggregate health (JSON) or dashboard (?ui=1)
 * GET  /v1/health/live        liveness  - is the process serving requests?
 * GET  /v1/health/ready       readiness - can we serve data right now?
 * GET  /v1/status             HTML dashboard
 * GET  /v1/meta               service + endpoint metadata
 * GET  /v1/maintenance        per-endpoint maintenance state
 * POST /v1/maintenance        toggle maintenance (admin token required)
 */
final class System extends Base
{
    public static function health(Request $request, array $params, array $route)
    {
        $health = self::collect();

        if ($request->bool('ui', false) || ($route['endpoint'] === 'health' && $request->isBrowser() && !$request->wantsJson(false))) {
            self::dashboard($request, $health);

            return;
        }

        $status = $health['overall'] === 'critical' ? 503 : 200;
        $headers = array(
            'Cache-Control' => 'no-store',
            'X-Health'      => $health['overall'],
        );

        Response::json($health, $status, $headers);
    }

    public static function live(Request $request, array $params, array $route)
    {
        Response::json(array(
            'status'    => 'ok',
            'check'     => 'liveness',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'uptime_seconds' => max(0, time() - (int) (Metrics::snapshot()['started_at'])),
        ), 200, array('Cache-Control' => 'no-store'));
    }

    public static function ready(Request $request, array $params, array $route)
    {
        $health = self::collect();
        $ready = in_array($health['overall'], array('ok', 'degraded'), true);

        Response::json(array(
            'status'     => $ready ? 'ok' : 'unavailable',
            'check'      => 'readiness',
            'timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
            'overall'    => $health['overall'],
            'redis'      => $health['checks']['redis'],
            'caches'     => array_map(static function ($entry) {
                return $entry['exists'] ? ($entry['fresh'] ? 'fresh' : 'stale') : 'missing';
            }, $health['checks']['cache_files']),
        ), $ready ? 200 : 503, array('Cache-Control' => 'no-store'));
    }

    public static function status(Request $request, array $params, array $route)
    {
        self::dashboard($request, self::collect());
    }

    public static function meta(Request $request, array $params, array $route)
    {
        $sources = array();
        foreach (Config::sources() as $id => $source) {
            // Defensive per field: this is a public document, and one malformed
            // source entry must not turn the whole response into a 500 or emit a
            // warning on every request. A missing field renders as null rather than
            // as an "undefined array key" notice.
            $sources[] = array(
                'id'          => $id,
                'label'       => isset($source['label']) ? $source['label'] : $id,
                'url'         => isset($source['url']) ? $source['url'] : '',
                'cache_ttl'   => isset($source['ttl']) ? (int) $source['ttl'] : 0,
                'parser'      => isset($source['parser']) ? $source['parser'] : '',
                'attribution' => isset($source['attribution']) ? $source['attribution'] : '',
            );
        }

        $endpoints = array();
        foreach (\SatApi\Api\Router::index() as $routeInfo) {
            $endpoints[] = $routeInfo;
        }

        $service = array(
            'name'        => Config::get('service.name'),
            'version'     => Config::get('service.version'),
            'api_version' => 'v1',
            'description' => Config::get('service.description'),
            'homepage'    => Config::get('service.homepage'),
            'docs'        => Config::get('service.docs'),
            'openapi'     => '/openapi.yaml',
            'status_page' => '/v1/status',
            'license'     => Config::get('service.license'),
            'contact'     => Config::get('service.contact'),
            'build'       => Config::get('service.build'),
        );

        $limits = array(
            'rate_limit_enabled'    => (bool) Config::get('ratelimit.enabled'),
            'rate_limit_per_minute' => (int) Config::get('ratelimit.per_minute'),
            'max_per_page'          => Catalog::MAX_PER_PAGE,
            'compression'           => (bool) Config::get('compression.enabled'),
            'max_request_bytes'     => (int) Config::get('http.max_bytes'),
        );

        // Same {meta, data} envelope as every other JSON endpoint so clients
        // only ever have to implement one parser.
        $data = array(
            'service'       => $service,
            'endpoints'     => $endpoints,
            'legacy_routes' => array_keys(\SatApi\Api\Router::legacyRoutes()),
            'sources'       => $sources,
            'limits'        => $limits,
            'errors'        => \SatApi\Support\Problem::catalog(),
            'cache_backend' => Store::backendName(),
            'maintenance'   => self::maintenanceSummary(),
            'links'         => array(
                'self'     => '/v1/meta',
                'docs'     => (string) Config::get('service.docs', '/docs.html'),
                'openapi'  => '/openapi.yaml',
                'status'   => '/v1/status',
                'health'   => '/v1/health',
                'ready'    => '/v1/health/ready',
                'sources'  => '/v1/tle/{source}',
            ),
        );

        $payload = self::envelope($data, self::envelopeMeta($request, array(
            'count' => count($sources),
        )));

        Response::json($payload, 200, array(
            'Cache-Control' => 'public, max-age=300, s-maxage=600',
            'ETag'          => self::etag(array(
                'v'       => Config::get('service.version'),
                'sources' => $sources,
                'errors'  => \SatApi\Support\Problem::catalog(),
            )),
        ));
    }

    public static function maintenanceRead(Request $request, array $params, array $route)
    {
        Response::json(self::envelope(
            array_values(self::maintenanceSummary()),
            self::envelopeMeta($request, array(
                'writable' => self::adminConfigured(),
                'hint'     => self::adminConfigured()
                    ? 'POST /v1/maintenance with a bearer token to toggle an endpoint.'
                    : 'Maintenance writes are disabled: set SATAPI_ADMIN_TOKEN (config admin.token) to enable them.',
            ))
        ), 200, array('Cache-Control' => 'no-store'));
    }

    public static function maintenanceWrite(Request $request, array $params, array $route)
    {
        if (!self::adminConfigured()) {
            Problem::send(503, 'Service Unavailable', 'Maintenance writes are disabled: no admin token is configured.', 'unauthorized');
        }
        if (!self::authorized($request)) {
            Problem::send(401, 'Unauthorized', 'A valid admin bearer token is required.', 'unauthorized', array(), array('WWW-Authenticate' => 'Bearer'));
        }

        $body = $request->jsonBody();
        $target = isset($body['endpoint']) ? (string) $body['endpoint'] : '';
        if ($target === '' || strtolower($target) === 'all') {
            $targets = Maintenance::ENDPOINTS;
        } else {
            if (!Maintenance::isKnown($target)) {
                Problem::send(400, 'Bad Request', 'Unknown endpoint "' . $target . '".', 'bad_request', array(
                    'parameter' => 'endpoint',
                    'allowed'   => Maintenance::ENDPOINTS,
                ));
            }
            $targets = array($target);
        }

        $enabled = true;
        if (array_key_exists('enabled', $body)) {
            $enabled = filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        $message = isset($body['message']) ? (string) $body['message'] : '';
        $ttl = isset($body['ttl']) ? (int) $body['ttl'] : 3600;
        if ($ttl < 60 || $ttl > 86400) {
            Problem::send(400, 'Bad Request', 'Parameter "ttl" must be between 60 and 86400 seconds.', 'bad_request', array('parameter' => 'ttl'));
        }

        $applied = array();
        foreach ($targets as $id) {
            Maintenance::set($id, $enabled, $message, $ttl);
            $applied[] = $id;
        }

        Response::json(self::envelope(
            array(
                'endpoints' => $applied,
                'enabled'   => $enabled,
                'ttl'       => $ttl,
                'until'     => $enabled ? gmdate('Y-m-d\TH:i:s\Z', time() + $ttl) : null,
                'state'     => array_values(self::maintenanceSummary()),
            ),
            self::envelopeMeta($request, array('applied_by' => $request->ip()))
        ), 200, array('Cache-Control' => 'no-store'));
    }

    // ------------------------------------------------------------------

    private static function adminConfigured()
    {
        $token = (string) Config::get('admin.token', '');

        return $token !== '';
    }

    private static function authorized(Request $request)
    {
        $expected = (string) Config::get('admin.token', '');
        if ($expected === '') {
            return false;
        }
        $provided = '';
        $authorization = (string) $request->header('authorization', '');
        if (stripos($authorization, 'bearer ') === 0) {
            $provided = trim(substr($authorization, 7));
        }
        if ($provided === '') {
            $provided = (string) $request->header('x-admin-token', '');
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * @return array<string,mixed>
     */
    public static function collect()
    {
        $redisStatus = 'connected';
        if (!class_exists('Redis')) {
            $redisStatus = 'extension_not_loaded';
        } elseif (!Store::redisAvailable()) {
            $redisStatus = 'connection_failed';
        }

        $inventory = Store::inventory(Repository::cacheKeys());
        $cacheFiles = array();
        $tleAggregate = array('exists' => false, 'age_seconds' => 0, 'age_human' => '', 'size' => 0, 'fresh' => false, 'sources' => 0);
        $allFresh = true;
        $anyExists = false;

        foreach ($inventory as $name => $entry) {
            $cacheFiles[$name] = array(
                'exists'      => (bool) $entry['exists'],
                'age_seconds' => isset($entry['age_seconds']) ? (int) $entry['age_seconds'] : 0,
                'age_human'   => isset($entry['age_human']) ? $entry['age_human'] : '',
                'size'        => isset($entry['size']) ? (int) $entry['size'] : 0,
                'fresh'       => isset($entry['fresh']) ? (bool) $entry['fresh'] : false,
                'ttl'         => isset($entry['ttl']) ? (int) $entry['ttl'] : 0,
                'backend'     => isset($entry['backend']) ? $entry['backend'] : '',
            );

            if (strpos($name, 'tle_') === 0 && $name !== 'tle_merge') {
                if ($entry['exists']) {
                    $tleAggregate['exists'] = true;
                    $tleAggregate['sources']++;
                    $tleAggregate['size'] += (int) $entry['size'];
                    $tleAggregate['age_seconds'] = max($tleAggregate['age_seconds'], (int) $entry['age_seconds']);
                }
            }

            if ($entry['exists']) {
                $anyExists = true;
                if (empty($entry['fresh'])) {
                    $allFresh = false;
                }
            } else {
                $allFresh = false;
            }
        }

        // Legacy dashboard consumers watch a "tle_merge" entry.
        $tleAggregate['age_human'] = Store::humanAge($tleAggregate['age_seconds']);
        $tleAggregate['fresh'] = $tleAggregate['exists'] && $tleAggregate['sources'] >= 3;
        $cacheFiles = array('tle_merge' => $tleAggregate) + self::legacyNames($cacheFiles);

        $upstreams = Metrics::summary();

        $overall = 'ok';
        if (!$anyExists && $redisStatus !== 'connected') {
            $overall = 'critical';
        } elseif (!$allFresh || $redisStatus !== 'connected') {
            $overall = 'degraded';
        }

        $maintenance = self::maintenanceSummary();

        return array(
            'status'    => $overall === 'ok' ? 'ok' : 'degraded',
            'overall'   => $overall,
            'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
            'service'   => Config::get('service.name', 'AzureOpenAPI for Satellite') . ' v' . Config::get('service.version', ''),
            'version'   => (string) Config::get('service.version', ''),
            'api_version' => 'v1',
            'uptime_seconds' => $upstreams['uptime_seconds'],
            'checks'    => array(
                'redis'       => $redisStatus,
                'storage'     => array(
                    'backend'      => Store::backendName(),
                    'cache_dir'    => Config::get('cache.dir'),
                    'cache_dir_writable' => is_writable((string) Config::get('cache.dir')),
                    'redis_ext'    => class_exists('Redis'),
                ),
                'cache_files' => $cacheFiles,
                'maintenance' => $maintenance,
            ),
            'metrics'   => array(
                'totals'    => $upstreams['totals'],
                'endpoints' => $upstreams['endpoints'],
                'upstream'  => $upstreams['upstream'],
            ),
        );
    }

    /**
     * Map internal cache names onto the legacy names used by older monitors.
     *
     * @return array<string,array>
     */
    private static function legacyNames(array $cacheFiles)
    {
        $aliases = array(
            'tle_weather'          => 'weather',
            'tle_amateur'          => 'amateur',
            'tle_amsat'            => 'amsat',
            'tle_satnogs'          => 'satnogs',
            'satnogs_satellites'   => 'satnogs_satellites',
            'satnogs_transmitters' => 'satnogs_transmitters',
            'amsat_status'         => 'amsat_status',
        );

        $out = array();
        foreach ($aliases as $internal => $legacy) {
            if (isset($cacheFiles[$internal])) {
                $out[$legacy] = $cacheFiles[$internal];
            }
        }
        foreach ($cacheFiles as $name => $entry) {
            if (!isset($out[$name])) {
                $out[$name] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<string,array>
     */
    private static function maintenanceSummary()
    {
        return Maintenance::all();
    }

    private static function dashboard(Request $request, array $health)
    {
        $html = \SatApi\Api\Endpoints\StatusPage::render($health, $request);
        Response::raw($html, 'text/html; charset=utf-8', $health['overall'] === 'critical' ? 503 : 200, array(
            'Cache-Control' => 'no-store',
            'X-Health'      => $health['overall'],
        ));
    }
}
