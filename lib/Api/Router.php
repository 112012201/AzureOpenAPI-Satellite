<?php

namespace SatApi\Api;

use SatApi\Http\Request;
use SatApi\Http\Response;
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

        if ($legacyRoute !== null) {
            if (!isset(self::$legacyMap[$legacyRoute])) {
                Problem::send(404, 'Not Found', 'Unknown legacy route.', 'not_found');
            }
            $route = self::$legacyMap[$legacyRoute];
            self::run($route, array(), $request);

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

    private static function run(array $route, array $params, Request $request)
    {
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
        $system = 'SatApi\\Api\\Endpoints\\System';

        self::$routes = array(
            // ---------- v1 ----------
            array('pattern' => '#^/v1/tle$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'merged'), 'endpoint' => 'tle_merge', 'text' => true, 'bucket' => 'tle'),
            array('pattern' => '#^/v1/tle/(?<source>weather|amateur|amsat|satnogs)$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($tle, 'source'), 'endpoint' => 'tle_source', 'text' => true, 'bucket' => 'tle'),
            array('pattern' => '#^/v1/satellites$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'satellites'), 'endpoint' => 'satnogs_satellites', 'bucket' => 'catalog'),
            array('pattern' => '#^/v1/transmitters$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($catalog, 'transmitters'), 'endpoint' => 'satnogs_transmitters', 'bucket' => 'catalog'),
            array('pattern' => '#^/v1/amsat/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($amsat, 'handle'), 'endpoint' => 'amsat_status', 'bucket' => 'amsat'),
            array('pattern' => '#^/v1/meta$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'meta'), 'endpoint' => 'meta'),
            array('pattern' => '#^/v1/health$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'health'), 'endpoint' => 'health'),
            array('pattern' => '#^/v1/health/live$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'live'), 'endpoint' => 'health_live'),
            array('pattern' => '#^/v1/health/ready$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'ready'), 'endpoint' => 'health_ready'),
            array('pattern' => '#^/v1/status$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'status'), 'endpoint' => 'status'),
            array('pattern' => '#^/v1/maintenance$#', 'methods' => array('GET', 'HEAD'), 'handler' => array($system, 'maintenanceRead'), 'endpoint' => 'maintenance'),
            array('pattern' => '#^/v1/maintenance$#', 'methods' => array('POST'), 'handler' => array($system, 'maintenanceWrite'), 'endpoint' => 'maintenance_write'),

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
        );
    }

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

        return array('route' => array(
            'pattern' => $pattern,
            'methods' => array('GET', 'HEAD'),
            'handler' => array('SatApi\\Api\\Endpoints\\System', 'meta'),
            'endpoint' => $endpoint,
            'legacy' => true,
            'successor' => $successor,
        ), 'params' => $params);
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
        return self::$legacyMap;
    }
}
