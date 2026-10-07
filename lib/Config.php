<?php

namespace SatApi;

/**
 * Central configuration.
 *
 * Resolution order (later wins):
 *   1. built-in defaults (below)
 *   2. conf/config.php  (optional, not web reachable)
 *   3. environment variables (SATAPI_*, plus a few legacy names)
 *
 * Values are addressed with dot paths: Config::get('cache.redis.host').
 */
final class Config
{
    /** @var array<string,mixed> */
    private static $values = array();

    private static $root = '';
    private static $loaded = false;

    /**
     * Initialise the configuration. Safe to call more than once.
     */
    public static function init($root)
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        self::$root = rtrim((string) $root, "/\\");

        self::$values = array(
            'root' => self::$root,

            'service' => array(
                'name'        => 'Satellite Data API',
                'slug'        => 'satellite-api',
                'version'     => '2.2.0',
                'api_version' => 'v1',
                'description' => 'Unified TLE and satellite metadata proxy (CelesTrak, AMSAT, SatNOGS).',
                'homepage'    => 'https://satellite.api.authskin.xyz',
                'docs'        => '/docs.html',
                'license'     => 'Data: public sources. API code: MIT.',
                'contact'     => 'https://community.libre.space/',
                'build'       => 'local',
            ),

            'cache' => array(
                'dir'   => self::$root . '/data/cache',
                'redis' => array(
                    'enabled' => true,
                    'host'    => '127.0.0.1',
                    'port'    => 6379,
                    'db'      => 8,
                    'timeout' => 1.0,
                    'prefix'  => 'satapi:',
                ),
                // Hard ceiling for a single cached payload (bytes).
                'max_payload' => 33554432,
            ),

            'http' => array(
                'user_agent'  => 'SatelliteDataAPI/2.2 (+https://satellite.api.authskin.xyz)',
                'verify_tls'  => true,
                'ca_bundle'   => '',
                'retries'     => 2,
                'retry_delay' => 250, // milliseconds
                'max_bytes'   => 33554432,
                'timeout'     => 20,
                'connect_timeout' => 8,
            ),

            'cors' => array(
                'enabled'        => true,
                'allow_origin'   => '*',
                'allow_methods'  => 'GET, HEAD, OPTIONS',
                'allow_headers'  => 'Content-Type, Accept, If-None-Match, If-Modified-Since, X-Requested-With, Authorization',
                'expose_headers' => 'ETag, X-Request-Id, X-Cache-Status, X-Cache-Age, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Deprecation, Sunset, Link',
                'max_age'        => 86400,
            ),

            'ratelimit' => array(
                'enabled'     => false,
                'per_minute'  => 120,
                'burst'       => 40,
                'dir'         => self::$root . '/data/ratelimit',
                'trust_proxy' => false,
            ),

            'compression' => array(
                'enabled'     => true,
                'min_bytes'   => 1024,
                'level'       => 6,
            ),

            'maintenance' => array(
                // Directory holding the `.maintenance_<endpoint>` flag files.
                'dir' => self::$root . '/data',
            ),

            'metrics' => array(
                'file' => self::$root . '/data/metrics.json',
            ),

            'admin' => array(
                // Empty token => maintenance writes are disabled (read-only mode).
                'token' => '',
            ),

            'legacy' => array(
                // Emit Deprecation/Sunset headers on the pre-v1 routes.
                'warn'  => true,
                'sunset' => '',
            ),

            'logs' => array(
                'dir'   => self::$root . '/logs',
                'file'  => 'app.log',
                'level' => 'warning',
            ),

            // Upstream data sources, shared by every endpoint.
            'sources' => array(
                'weather' => array(
                    'label'   => 'CelesTrak Weather',
                    'url'     => 'https://celestrak.org/NORAD/elements/gp.php?GROUP=weather&FORMAT=tle',
                    'ttl'     => 10800,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'CelesTrak (Dr. T.S. Kelso)',
                ),
                'amateur' => array(
                    'label'   => 'CelesTrak Amateur',
                    'url'     => 'https://celestrak.org/NORAD/elements/gp.php?GROUP=amateur&FORMAT=tle',
                    'ttl'     => 10800,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'CelesTrak (Dr. T.S. Kelso)',
                ),
                'amsat' => array(
                    'label'   => 'AMSAT Daily TLE',
                    'url'     => 'https://www.amsat.org/tle/dailytle.txt',
                    'ttl'     => 43200,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'AMSAT',
                ),
                'satnogs' => array(
                    'label'   => 'SatNOGS DB TLE',
                    'url'     => 'https://db.satnogs.org/api/tle/?format=json',
                    'ttl'     => 10800,
                    'parser'  => 'satnogs-json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'satnogs_satellites' => array(
                    'label'   => 'SatNOGS Satellites',
                    'url'     => 'https://db.satnogs.org/api/satellites/',
                    'ttl'     => 21600,
                    'parser'  => 'json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'satnogs_transmitters' => array(
                    'label'   => 'SatNOGS Transmitters',
                    'url'     => 'https://db.satnogs.org/api/transmitters/',
                    'ttl'     => 21600,
                    'parser'  => 'json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'amsat_status' => array(
                    'label'   => 'AMSAT Status Reports',
                    'url'     => 'https://www.amsat.org/status/',
                    'ttl'     => 3600,
                    'parser'  => 'amsat-status-html',
                    'accept'  => 'text/html',
                    'attribution' => 'AMSAT',
                ),
            ),

            // Static NORAD fallback map for AMSAT status parsing.
            // Verified against CelesTrak where possible (see OPTIMIZATION-REPORT.md).
            'norad_fallback' => array(
                'AO-7'   => '07530',
                'AO-73'  => '39444',
                'AO-85'  => '40967',
                'AO-91'  => '43017',
                'AO-92'  => '43137',
                'AO-95'  => '43770',
                'AO-109' => '46325',
                'FO-29'  => '24278',
                'SO-50'  => '27607',
                'EO-88'  => '42017',
                'PO-101' => '43678',
                'CAS-4A' => '43855',
                'CAS-4B' => '43856',
                'CO-128' => '45785',
                'ISS'    => '25544',
                'UO-11'  => '14781',
                'AO-27'  => '22825',
                'AO-51'  => '28375',
                'JO-97'  => '43803',
                'RS-44'  => '44909',
            ),
        );

        self::loadFile();
        self::applyEnvironment();
    }

    private static function loadFile()
    {
        $file = self::$root . '/conf/config.php';
        if (!is_file($file)) {
            return;
        }
        /** @noinspection PhpIncludeInspection */
        $overrides = require $file;
        if (is_array($overrides)) {
            self::$values = self::merge(self::$values, $overrides);
        }
    }

    private static function applyEnvironment()
    {
        $map = array(
            'SATAPI_SERVICE_VERSION'      => 'service.version',
            'SATAPI_SERVICE_HOMEPAGE'     => 'service.homepage',
            'SATAPI_SERVICE_BUILD'        => 'service.build',
            'SATAPI_REDIS_ENABLED'        => 'cache.redis.enabled',
            'SATAPI_REDIS_HOST'           => 'cache.redis.host',
            'SATAPI_REDIS_PORT'           => 'cache.redis.port',
            'SATAPI_REDIS_DB'             => 'cache.redis.db',
            'SATAPI_REDIS_PREFIX'         => 'cache.redis.prefix',
            'SATAPI_CACHE_DIR'            => 'cache.dir',
            'SATAPI_CORS_ORIGIN'          => 'cors.allow_origin',
            'SATAPI_RATELIMIT_ENABLED'    => 'ratelimit.enabled',
            'SATAPI_RATELIMIT_PER_MINUTE' => 'ratelimit.per_minute',
            'SATAPI_ADMIN_TOKEN'          => 'admin.token',
            'SATAPI_TRUST_PROXY'          => 'ratelimit.trust_proxy',
            'SATAPI_LOG_LEVEL'            => 'logs.level',
            'SATAPI_LOG_DIR'              => 'logs.dir',
            'SATAPI_VERIFY_TLS'           => 'http.verify_tls',
            'SATAPI_CA_BUNDLE'            => 'http.ca_bundle',
        );

        foreach ($map as $env => $path) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                continue;
            }
            if (in_array($value, array('0', 'false', 'no', 'off'), true)) {
                $value = false;
            } elseif (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
                $value = true;
            } elseif (is_numeric($value)) {
                $value = $value + 0;
            }
            self::set($path, $value);
        }
    }

    private static function merge(array $base, array $overrides)
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Read a configuration value using a dot path.
     *
     * @param string $path
     * @param mixed  $default
     * @return mixed
     */
    public static function get($path, $default = null)
    {
        $node = self::$values;
        foreach (explode('.', (string) $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Override a configuration value at runtime (tests / CLI flags).
     *
     * @param string $path
     * @param mixed  $value
     */
    public static function set($path, $value)
    {
        $segments = explode('.', (string) $path);
        $node = &self::$values;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = array();
            }
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);
    }

    public static function root()
    {
        return self::$root;
    }

    public static function path($relative)
    {
        return self::$root . '/' . ltrim((string) $relative, '/');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function sources()
    {
        return (array) self::get('sources', array());
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function source($key)
    {
        $sources = self::sources();

        return isset($sources[$key]) ? $sources[$key] : null;
    }
}
