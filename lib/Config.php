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
                'name'        => 'AzureOpenAPI for Satellite',
                'slug'        => 'azureopenapi-for-satellite',
                'version'     => '3.1.2789-beta',
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
                'user_agent'  => 'AzureOpenAPI-for-Satellite/3.1.2789-beta (+https://satellite.api.authskin.xyz)',
                'verify_tls'  => true,
                'ca_bundle'   => '',
                'retries'     => 2,
                'retry_delay' => 250, // milliseconds
                'max_bytes'   => 33554432,
                'timeout'     => 20,
                'connect_timeout' => 8,
                // Who emits the four security headers on dynamic responses.
                // The application owns them because it is the only source that
                // covers `php -S` and Apache as well as nginx, and because a
                // server-level nginx group is applied twice to fastcgi responses
                // (measured: panel vhost copy removed => count 2 -> 1).
                //   'app'   (default) - this process sends all four.
                //   'nginx'           - this process sends none. Verified to
                //                       leave dynamic responses with zero
                //                       security headers under the current
                //                       nginx config, so change it only together
                //                       with conf/bt-panel-rewrite.conf.
                //   'both'            - v2.2 defect, duplicates. Rollback only.
                'security_headers_owner' => 'app',
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
                // Telemetry sink selection: 'file' | 'redis' | 'both'.
                // See lib/Metrics/Driver.php for the driver contract.
                'driver' => 'both',
                'file'   => self::$root . '/data/metrics.json',
                'redis'  => array(
                    'enabled' => true,
                    // A dedicated logical database keeps counter writes off the
                    // cache database, so a metrics burst cannot evict datasets.
                    'db'      => 9,
                    'prefix'  => 'satapi:m:',
                    'timeout' => 1.0,
                ),
                'mysql'  => array(
                    // Historical rollups. Disabled until credentials are given
                    // via SATAPI_MYSQL_* or conf/config.php; when disabled the
                    // metrics endpoints degrade instead of failing.
                    'enabled'        => false,
                    'host'           => '127.0.0.1',
                    'port'           => 3306,
                    'database'       => 'satapi',
                    'user'           => '',
                    'password'       => '',
                    'charset'        => 'utf8mb4',
                    'retention_days' => 90,
                ),
                // Redis minute buckets expire after this many seconds (48h).
                'redis_retention' => 172800,
            ),

            'admin' => array(
                // Empty token => maintenance writes are disabled (read-only mode).
                'token' => '',
            ),

            // ------------------------------------------------------------------
            // Site announcement banner.
            //
            // Rendered server-side into the HTML so it is visible without
            // JavaScript and is announced by screen readers. `id` is the identity
            // of the notice: changing it makes a previously dismissed banner
            // reappear (the dismissal cookie stores the id, not a boolean).
            // `expires_at` is evaluated on the SERVER: a client with a wrong
            // clock must not be able to keep an expired notice alive.
            // ------------------------------------------------------------------
            'announcement' => array(
                'enabled'    => false,
                'id'         => '',
                'text'       => '',
                'href'       => '',
                'href_text'  => '',
                'level'      => 'info',   // info | warning
                'expires_at' => '',       // '' = never; otherwise ISO-8601 UTC
            ),

            // ------------------------------------------------------------------
            // Consent (privacy policy + terms of service acknowledgement).
            //
            // The contract is stateless so it survives CDN caching:
            //   ?consent=privacy,terms&consent_version=1
            //   X-Consent: privacy,terms;version=1
            //
            // `required` also gates enforcement. It is false by default so an
            // existing integration is never broken by a new legal notice; set
            // SATAPI_CONSENT_REQUIRED=1 to answer 451 without an acknowledgement.
            // Bumping `version` invalidates every previous acknowledgement, which
            // is what a material change to either document requires.
            // ------------------------------------------------------------------
            'consent' => array(
                'required' => false,
                'version'  => 1,
                'items'    => array('privacy', 'terms'),
                'docs'     => array(
                    'privacy' => '/legal/privacy.html',
                    'terms'   => '/legal/terms.html',
                ),
                // Paths that never require an acknowledgement: the notice itself,
                // the machine-readable contract, the probes an operator needs, and
                // the very document a client is being asked to read.
                'exempt_paths' => array(
                    '/v1/health', '/v1/health/live', '/v1/health/ready',
                    '/v1/status', '/v1/meta', '/v1/consent/status',
                    '/v1/consent/verify-pow',
                    '/docs.html', '/openapi.yaml',
                    '/legal/privacy.html', '/legal/terms.html', '/legal/index.html',
                ),
                // Longest accepted value for ?consent=, in bytes. Guards logs.
                'max_param_bytes' => 512,
            ),

            // ------------------------------------------------------------------
            // Proof of work (Cap) in front of the HTML entry points.
            //
            // Only the browser entry points are challenged: an API client cannot
            // solve a proof of work, and challenging /v1/* would also make every
            // response uncacheable at the CDN.
            //
            // `fail_open` is deliberate. A third-party challenge service going
            // down must degrade the protection, never lock the whole site, so an
            // unreachable or slow Cap instance results in a pass plus a logged
            // warning and an X-PoW-Status header the page can surface.
            // ------------------------------------------------------------------
            'pow' => array(
                'enabled'      => false,
                'provider'     => 'cap',
                // Public base URL of the Cap instance as the BROWSER sees it;
                // it is also what the widget calls, so it must not be loopback.
                'endpoint'     => '/cap',
                'site_key'     => '',
                // Prefer SATAPI_POW_SECRET_KEY so the secret stays out of the repo.
                'secret_key'   => '',
                'timeout_ms'   => 5000,
                'fail_open'    => true,
                'session_ttl'  => 43200,   // cap_session cookie lifetime, seconds
                'exempt_paths' => array(
                    '/v1/health', '/v1/health/live', '/v1/health/ready',
                    '/v1/status', '/v1/meta', '/v1/consent/status',
                    '/v1/consent/verify-pow',
                    '/legal/privacy.html', '/legal/terms.html', '/legal/index.html',
                ),
            ),

            // ------------------------------------------------------------------
            // Cookie categories exposed to the consent dialog.
            //
            // `essential` cookies are written even when the visitor rejects
            // everything: they are what makes the visitor's own choice and the
            // proof-of-work session possible. That is stated plainly in the dialog
            // and in the privacy policy rather than being quietly assumed.
            // ------------------------------------------------------------------
            'cookies' => array(
                'version' => 1,
                'essential' => array(
                    'cookie_consent'         => 'Stores the choice made in the cookie dialog.',
                    'announcement_dismissed' => 'Remembers which notice you dismissed.',
                    'cap_session'            => 'Keeps a passed proof-of-work check for this browser.',
                ),
                'preferences' => array(
                    'satapi-theme' => 'Remembers the light or dark theme you picked.',
                ),
                'analytics' => array(),
                'lifetime_days' => 180,
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
            'SATAPI_SECURITY_HEADERS_OWNER' => 'http.security_headers_owner',
            'SATAPI_METRICS_DRIVER'       => 'metrics.driver',
            'SATAPI_METRICS_FILE'         => 'metrics.file',
            'SATAPI_METRICS_REDIS_ENABLED' => 'metrics.redis.enabled',
            'SATAPI_METRICS_REDIS_DB'     => 'metrics.redis.db',
            'SATAPI_METRICS_REDIS_PREFIX' => 'metrics.redis.prefix',
            'SATAPI_METRICS_REDIS_RETENTION' => 'metrics.redis_retention',
            'SATAPI_MYSQL_ENABLED'        => 'metrics.mysql.enabled',
            'SATAPI_MYSQL_HOST'           => 'metrics.mysql.host',
            'SATAPI_MYSQL_PORT'           => 'metrics.mysql.port',
            'SATAPI_MYSQL_DATABASE'       => 'metrics.mysql.database',
            'SATAPI_MYSQL_USER'           => 'metrics.mysql.user',
            'SATAPI_MYSQL_PASSWORD'       => 'metrics.mysql.password',
            'SATAPI_MYSQL_RETENTION_DAYS' => 'metrics.mysql.retention_days',
            'SATAPI_ANNOUNCEMENT_ENABLED' => 'announcement.enabled',
            'SATAPI_ANNOUNCEMENT_ID'      => 'announcement.id',
            'SATAPI_ANNOUNCEMENT_TEXT'    => 'announcement.text',
            'SATAPI_ANNOUNCEMENT_HREF'    => 'announcement.href',
            'SATAPI_ANNOUNCEMENT_EXPIRES' => 'announcement.expires_at',
            'SATAPI_CONSENT_REQUIRED'     => 'consent.required',
            'SATAPI_CONSENT_VERSION'      => 'consent.version',
            'SATAPI_POW_ENABLED'          => 'pow.enabled',
            'SATAPI_POW_ENDPOINT'         => 'pow.endpoint',
            'SATAPI_POW_SITE_KEY'         => 'pow.site_key',
            'SATAPI_POW_SECRET_KEY'       => 'pow.secret_key',
            'SATAPI_POW_FAIL_OPEN'        => 'pow.fail_open',
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
