<?php

namespace SatApi\Api;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Config;
use SatApi\Logger;
use SatApi\Support\Consent;
use SatApi\Support\Metrics;
use SatApi\Support\Problem;
use SatApi\Support\RateLimit;

/**
 * Front controller router.
 *
 * Two route families are served from the same code base:
 *   - /v1/...  : the stable, documented API (OpenAPI 3.1),
 *   - legacy   : /tle, /tle/weather, /health, ... plus the historical .php
 *                file names, kept byte-compatible and marked deprecated.
 */
final class Router
{
    /** @var array<int,array> */
    private static $routes = array();

    /** @var array<string,array> */
    private static $legacyMap = array();

    public static function dispatch(Request $request, $legacyRoute = null)
    {
        Response::bind($request);
        Problem::bind($request);
        self::registerRoutes();

        if ($request->method() === 'OPTIONS') {
            Response::noContent(204);

            return;
        }

        if (!self::enforceConsent($request)) {
            return;
        }

        if ($legacyRoute === null) {
            // The historical .php shims require index.php, which dispatches
            // without an explicit route id; recover it from the request path.
            $legacyRoute = self::shellRoute($request);
        }

        if ($legacyRoute !== null) {
            if (!isset(self::$legacyMap[$legacyRoute])) {
                Problem::send(404, 'Not Found', 'Unknown legacy route.', 'not_found');
            }
            // legacyMap entries are {route, params} envelopes, NOT bare route
            // descriptors. Passing the envelope straight to run() left
            // $route['handler'] undefined, which surfaced as
            //   500 {"detail":"Route handler is not callable."}
            // plus a php_error warning ("Undefined array key \"handler\"",
            // Router.php:92) on every legacy .php shim request.
            $match = self::$legacyMap[$legacyRoute];
            self::run($match['route'], $match['params'], $request);

            return;
        }

        $match = self::match($request);
        if ($match === null) {
            Problem::send(404, 'Not Found', 'No route matches ' . $request->path() . '. See /v1/meta for the endpoint index.', 'not_found');
        }
        if (isset($match['allow'])) {
            Problem::send(
                405,
                'Method Not Allowed',
                'The ' . $request->method() . ' method is not supported on ' . $request->path() . '.',
                'method_not_allowed',
                array('allow' => explode(', ', $match['allow'])),
                array('Allow' => $match['allow'], 'Cache-Control' => 'no-store')
            );

            return;
        }

        self::run($match['route'], $match['params'], $request);
    }

    /**
     * Evaluate the privacy/terms acknowledgement once per request.
     *
     * Returns false when the request has been answered with 451 and dispatch must
     * stop. The verdict is stashed so `meta.consent` and `X-Consent-Required` are
     * rendered from the same evaluation instead of each endpoint re-parsing.
     *
     * Enforcement is off by default: publishing a legal notice must not break every
     * existing integration on its own. `consent.required` (or
     * SATAPI_CONSENT_REQUIRED=1) turns it on.
     *
     * @return bool
     */
    private static function enforceConsent(Request $request)
    {
        $verdict = Consent::describe($request);
        Consent::setCurrent($verdict);

        if ($verdict['accepted'] || Consent::isExemptPath($request->path())) {
            return true;
        }

        if (!$verdict['required']) {
            // Lenient mode: the response proceeds and advertises the requirement
            // through meta.consent and X-Consent-Required. Nothing is blocked.
            return true;
        }

        if ($verdict['state'] === Consent::STATE_INVALID) {
            Problem::send(
                400,
                'Bad Request',
                (string) $verdict['error'],
                'bad_request',
                array('parameter' => 'consent', 'allow' => (array) Config::get('consent.items', array())),
                array('Cache-Control' => 'no-store')
            );

            return false;
        }

        Problem::send(
            451,
            'Unavailable For Legal Reasons',
            'This API requires acknowledgement of its privacy policy and terms of service. '
                . 'Add ?consent=' . implode(',', (array) Config::get('consent.items', array()))
                . '&consent_version=' . (int) $verdict['version'] . ' to your requests.',
            'consent_required',
            Consent::problemExtra($verdict),
            array('Cache-Control' => 'no-store')
        );

        return false;
    }

    /**
     * Fallback used by the historical .php shims.
     *
     * Those files require index.php, which calls dispatch($request) WITHOUT an
     * explicit route id, so the id has to be recovered from the request path.
     * Without this every shell fell through to normal matching, which has no
     * pattern for "/tle_weather.php", and the shim answered 500.
     *
     * Accepts both the direct path and the PATH_INFO form so the shims work
     * under nginx (SCRIPT_NAME=index.php, PATH_INFO=/tle_weather.php), under
     * Apache (which may prepend the script name) and through tests/request.php.
     *
     * @return string|null
     */
    private static function shellRoute(Request $request)
    {
        self::registerRoutes();

        // Request::path() is derived from REQUEST_URI, so under nginx
        // (SCRIPT_NAME=/index.php, PATH_INFO=/tle_weather.php) and through
        // tests/request.php it is already the public path. The index.php prefix
        // is stripped defensively for Apache-style setups that include it.
        $candidate = preg_replace('#^/+index\.php#', '', (string) $request->path());

        return self::phpShell($candidate === '' ? '/' : $candidate);
    }

    private static function run(array $route, array $params, Request $request)
    {
        if (!isset($route['handler'])) {
            Problem::send(500, 'Internal Server Error', 'Route descriptor is missing its handler.', 'internal_error');
        }

        Metrics::setEndpoint(isset($route['endpoint']) ? $route['endpoint'] : 'unknown');
        Problem::setTextPreferred(!empty($route['text']));

        if (!empty($route['bucket'])) {
            $policy = RateLimit::check($request, $route['bucket']);
            if (!$policy['allowed']) {
                Problem::send(
                    429,
                    'Too Many Requests',
                    'The request budget for this endpoint has been exhausted. Retry after the reset window.',
                    'rate_limited',
                    array('rate_limit' => array(
                        'limit'     => $policy['limit'],
                        'remaining' => $policy['remaining'],
                        'reset'     => gmdate('Y-m-d\TH:i:s\Z', $policy['reset']),
                    )),
                    RateLimit::headers($policy)
                );
            }
        }

        $handler = $route['handler'];
        if (!is_callable($handler)) {
            Problem::send(500, 'Internal Server Error', 'Route handler is not callable.', 'internal_error');
        }

        call_user_func($handler, $request, $params, $route);
    }

    /**
     * @return array|null
     */
    private static function match(Request $request)
    {
        $path = $request->path();
        $method = $request->method();
        $allow = array();

        foreach (self::$routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }
            $allow = array_merge($allow, $route['methods']);
            if (in_array($method, $route['methods'], true)) {
                $params = array();
                foreach ($matches as $index => $value) {
                    if (is_string($index)) {
                        $params[$index] = $value;
                    }
                }

                return array('route' => $route, 'params' => $params);
            }
        }

        if (!empty($allow)) {
            $allow[] = 'OPTIONS';

            return array('allow' => implode(', ', array_unique($allow)));
        }

        return null;
    }

    private static function registerRoutes()
    {
        if (!empty(self::$routes)) {
            return;
        }

        $tle = 'SatApi\\Api\\Endpoints\\Tle';
        $catalog = 'SatApi\\Api\\Endpoints\\Catalog';
        $amsat = 'SatApi\\Api\\Endpoints\\AmsatStatus';
        $amsatApi = 'SatApi\\Api\\Endpoints\\AmsatApi';
        $system = 'SatApi\\Api\\Endpoints\\System';
        $consent = 'SatApi\\Api\\Endpoints\\Consent';
        $admin = 'SatApi\\Api\\Endpoints\\Admin';

        self::$routes = array(
            // ---------- v1 ----------
            array('pattern' => '#^/v1/consent/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($consent, 'status'), 'endpoint' => 'consent_status'),
            array('pattern' => '#^/v1/consent/verify-pow$#', 'methods' => array('POST'), 'handler' => array($consent, 'verifyPow'), 'endpoint' => 'consent_verify_pow'),
            array('pattern' => '#^/v1/tle$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'merged'), 'endpoint' => 'tle_merge', 'text' => true, 'bucket' => 'tle'),
            array('pattern' => '#^/v1/tle/(?<source>weather|amateur|amsat|satnogs)$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'source'), 'endpoint' => 'tle_source', 'text' => true, 'bucket' => 'tle'),
            array('pattern' => '#^/v1/satellites$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'satellites'), 'endpoint' => 'satnogs_satellites', 'bucket' => 'catalog'),
            array('pattern' => '#^/v1/transmitters$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'transmitters'), 'endpoint' => 'satnogs_transmitters', 'bucket' => 'catalog'),
            array('pattern' => '#^/v1/amsat/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsat, 'handle'), 'endpoint' => 'amsat_status', 'bucket' => 'amsat'),
            // AMSAT's own v1 API, relayed as-is (see Api\Endpoints\AmsatApi for why it
            // is a proxy rather than a parser, and why POST /reports is absent).
            //
            // The upstream names are kept, including the two legacy shapes, because a
            // client migrating from status.amsat.org should be able to swap the host
            // and nothing else.
            array('pattern' => '#^/v1/amsat/catalog$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_catalog', 'bucket' => 'amsat', 'amsat_source' => 'catalog'),
            array('pattern' => '#^/v1/amsat/reports$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_reports', 'bucket' => 'amsat', 'amsat_source' => 'reports'),
            array('pattern' => '#^/v1/amsat/summary$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_summary', 'bucket' => 'amsat', 'amsat_source' => 'summary'),
            array('pattern' => '#^/v1/amsat/statuses$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_statuses', 'bucket' => 'amsat', 'amsat_source' => 'statuses'),
            array('pattern' => '#^/v1/amsat/health$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_health', 'bucket' => 'amsat', 'amsat_source' => 'health'),
            array('pattern' => '#^/v1/amsat/satellites$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_satellites', 'bucket' => 'amsat', 'amsat_source' => 'satellites'),
            array('pattern' => '#^/v1/amsat/sat-info$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsatApi, 'handle'), 'endpoint' => 'amsat_sat_info', 'bucket' => 'amsat', 'amsat_source' => 'sat-info'),
            array('pattern' => '#^/v1/meta$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'meta'), 'endpoint' => 'meta'),
            array('pattern' => '#^/v1/health$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'health'), 'endpoint' => 'health'),
            array('pattern' => '#^/v1/health/live$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'live'), 'endpoint' => 'health_live'),
            array('pattern' => '#^/v1/health/ready$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'ready'), 'endpoint' => 'health_ready'),
            array('pattern' => '#^/v1/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'status'), 'endpoint' => 'status'),
            array('pattern' => '#^/v1/maintenance$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'maintenanceRead'), 'endpoint' => 'maintenance'),
            array('pattern' => '#^/v1/maintenance$#', 'methods' => array('POST'), 'handler' => array($system, 'maintenanceWrite'), 'endpoint' => 'maintenance_write'),

            // Operator surface. Registered in the normal table so it answers 405 for
            // a GET instead of 404; it 404s on its own when no admin token is
            // configured, which is the default deployment.
            array('pattern' => '#^/v1/admin/reload$#', 'methods' => array('POST'), 'handler' => array($admin, 'reload'), 'endpoint' => 'admin_reload', 'admin' => true),

            // ---------- legacy (deprecated, kept compatible) ----------
            array('pattern' => '#^/tle$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'merged'), 'endpoint' => 'tle_merge', 'text' => true, 'legacy' => true, 'successor' => '/v1/tle', 'bucket' => 'tle'),
            array('pattern' => '#^/tle/(?<source>weather|amateur|amsat|satnogs)$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'source'), 'endpoint' => 'tle_source', 'text' => true, 'legacy' => true, 'successor' => '/v1/tle/{source}', 'bucket' => 'tle'),
            array('pattern' => '#^/satnogs/satellites$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'satellites'), 'endpoint' => 'satnogs_satellites', 'legacy' => true, 'successor' => '/v1/satellites', 'bucket' => 'catalog'),
            array('pattern' => '#^/satnogs/transmitters$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'transmitters'), 'endpoint' => 'satnogs_transmitters', 'legacy' => true, 'successor' => '/v1/transmitters', 'bucket' => 'catalog'),
            array('pattern' => '#^/amsat/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsat, 'handle'), 'endpoint' => 'amsat_status', 'legacy' => true, 'successor' => '/v1/amsat/status', 'bucket' => 'amsat'),
            array('pattern' => '#^/health$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'health'), 'endpoint' => 'health', 'legacy' => true, 'successor' => '/v1/health'),
            array('pattern' => '#^/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'status'), 'endpoint' => 'status', 'legacy' => true, 'successor' => '/v1/status'),
        );

        self::$legacyMap = array(
            'tle_merge'             => self::legacyRoute(self::$routes, '#^/tle$#', 'tle_merge', '/v1/tle'),
            'tle_weather'           => self::legacyRoute(self::$routes, '#^/tle/weather$#', 'tle_source', '/v1/tle/weather', array('source' => 'weather')),
            'tle_amateur'           => self::legacyRoute(self::$routes, '#^/tle/amateur$#', 'tle_source', '/v1/tle/amateur', array('source' => 'amateur')),
            'tle_amsat'             => self::legacyRoute(self::$routes, '#^/tle/amsat$#', 'tle_source', '/v1/tle/amsat', array('source' => 'amsat')),
            'tle_satnogs'           => self::legacyRoute(self::$routes, '#^/tle/satnogs$#', 'tle_source', '/v1/tle/satnogs', array('source' => 'satnogs')),
            'satnogs_satellites'    => self::legacyRoute(self::$routes, '#^/satnogs/satellites$#', 'satnogs_satellites', '/v1/satellites'),
            'satnogs_transmitters'  => self::legacyRoute(self::$routes, '#^/satnogs/transmitters$#', 'satnogs_transmitters', '/v1/transmitters'),
            'amsat_status'          => self::legacyRoute(self::$routes, '#^/amsat/status$#', 'amsat_status', '/v1/amsat/status'),
            'health'                => self::legacyRoute(self::$routes, '#^/health$#', 'health', '/v1/health'),
            'status'                => self::legacyRoute(self::$routes, '#^/status$#', 'status', '/v1/status'),

            // ------------------------------------------------------------------
            // Historical .php file names.
            //
            // The five TLE/AMSAT/SatNOGS shims define SATAPI_LEGACY_ROUTE with
            // these exact ids, and README promises "all legacy URLs keep
            // working", so they are part of the public surface. health.php is the
            // historical entry point that served BOTH /health and /status by
            // inspecting the request path, which one id cannot express -- hence
            // health_php and status_php as separate ids resolved by phpShell().
            // ------------------------------------------------------------------
            'tle_merge_php'         => self::legacyRoute(self::$routes, '#^/tle$#', 'tle_merge', '/v1/tle'),
            'health_php'            => self::legacyRoute(self::$routes, '#^/health$#', 'health', '/v1/health'),
            'status_php'            => self::legacyRoute(self::$routes, '#^/status$#', 'status', '/v1/status'),
        );
    }

    /**
     * Route id for a request that entered through a historical .php file.
     *
     * Returns null when the path is not a known shell, so the caller can fall
     * back to normal routing.
     *
     * @return string|null
     */
    public static function phpShell($path)
    {
        $map = array(
            '/tle_merge.php'    => 'tle_merge_php',
            '/tle_weather.php'  => 'tle_weather',
            '/tle_amateur.php'  => 'tle_amateur',
            '/tle_amsat.php'    => 'tle_amsat',
            '/tle_satnogs.php'  => 'tle_satnogs',
            '/satnogs_satellites_proxy.php'   => 'satnogs_satellites',
            '/satnogs_transmitters_proxy.php' => 'satnogs_transmitters',
            '/amsat_status_proxy.php'         => 'amsat_status',
            // health.php historically answered BOTH /health and /status by
            // inspecting the request path; one id cannot express both, so the
            // two entry points get their own ids.
            '/health.php'       => 'health_php',
            '/status.php'       => 'status_php',
        );

        return isset($map[$path]) ? $map[$path] : null;
    }

    /**
     * Build a legacyMap entry.
     *
     * Resolution order matters: the mapped pattern is a LEGACY pattern
     * ("#^/tle/weather$#") while the route table only registers the v1 patterns
     * ("#^/v1/tle/(?<source>weather|amateur|amsat|satnogs)$#"). Matching on the
     * literal pattern alone therefore failed for every {source} alias and the
     * old code silently fell back to System::meta -- so /tle_weather.php answered
     * with the /v1/meta document and carried no Deprecation header.
     *
     * The fallback chain is now:
     *   1. exact legacy pattern present in the route table,
     *   2. equivalent v1 parameterised route matching the same {source},
     *   3. System::meta as the last resort for unknown aliases (kept so a future
     *      alias cannot 500), which phpShell() never reaches because it only
     *      maps paths it knows.
     *
     * @param array  $routes
     * @param string $pattern
     * @param string $endpoint
     * @param string $successor
     * @param array  $params
     * @return array{route:array,params:array}
     */
    private static function legacyRoute(array $routes, $pattern, $endpoint, $successor, array $params = array())
    {
        foreach ($routes as $route) {
            if ($route['pattern'] === $pattern) {
                $route['legacy'] = true;
                $route['successor'] = $successor;
                $route['endpoint'] = $endpoint;

                return array('route' => $route, 'params' => $params);
            }
        }

        // A parameterised alias such as "#^/tle/(?<source>weather|amateur|amsat|satnogs)$#"
        // reuses the registered v1 route for the same source, so the handler,
        // the text-negotiation flag and the cache bucket all stay single-sourced.
        if (isset($params['source'])) {
            foreach ($routes as $route) {
                if (strpos($route['pattern'], '/v1/tle/(?<source>') === false) {
                    continue;
                }
                if (strpos($route['pattern'], (string) $params['source']) === false) {
                    continue;
                }
                $route['legacy'] = true;
                $route['successor'] = $successor;
                $route['endpoint'] = $endpoint;

                return array('route' => $route, 'params' => $params);
            }
        }

        $fallback = array(
            'pattern' => $pattern,
            'methods' => array('GET', 'HEAD'),
            'handler' => array('SatApi\\Api\\Endpoints\\System', 'meta'),
            'endpoint' => $endpoint,
            'legacy' => true,
            'successor' => $successor,
        );

        // Only reachable for an alias with no equivalent route: make it loud in
        // the log instead of silently answering with the wrong document.
        Logger::warning('legacy_alias_without_route', array(
            'endpoint'  => $endpoint,
            'pattern'   => $pattern,
            'successor' => $successor,
        ));

        return array('route' => $fallback, 'params' => $params);
    }

    /**
     * Route descriptors for /v1/meta.
     *
     * @return array<int,array>
     */
    public static function index()
    {
        self::registerRoutes();
        $out = array();
        foreach (self::$routes as $route) {
            if (strpos($route['pattern'], '/v1') === false) {
                continue;
            }
            // The operator surface is not part of the published contract: /v1/meta is
            // a public document, and listing a route that answers 404 unless a token
            // is configured would advertise a door most deployments do not have.
            if (!empty($route['admin'])) {
                continue;
            }
            $out[] = array(
                'path'    => self::patternToPath($route['pattern']),
                'methods' => $route['methods'],
                'id'      => $route['endpoint'],
            );
        }

        return $out;
    }

    private static function patternToPath($pattern)
    {
        $path = trim($pattern, '#^$');
        $path = str_replace('(?<source>weather|amateur|amsat|satnogs)', '{source}', $path);

        return $path;
    }

    /**
     * @return array<string,array>
     */
    public static function legacyRoutes()
    {
        // Self-initialising: callers (introspection, tests, future tooling)
        // otherwise read an empty map and draw the wrong conclusion about the
        // frozen legacy surface.
        self::registerRoutes();

        return self::$legacyMap;
    }
}
