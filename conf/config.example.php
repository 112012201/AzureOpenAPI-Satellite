<?php
/**
 * Optional local configuration overrides.
 *
 * Copy to conf/config.php and edit. This file lives outside the web root
 * exposure list (nginx denies /conf/) and is merged over the built-in
 * defaults in lib/Config.php. Environment variables (SATAPI_*) always win.
 *
 * Everything here is optional; the defaults are production-sane.
 */

return array(

    'service' => array(
        // 'name'        => 'Satellite Data API',
        // 'version'     => '2.2.0',
        // 'homepage'    => 'https://satellite.api.authskin.xyz',
        // 'build'       => 'edgeone-2026-02',
    ),

    'cache' => array(
        // Redis is optional: without it the file cache in data/cache is used.
        'redis' => array(
            'enabled' => true,
            'host'    => '127.0.0.1',
            'port'    => 6379,
            'db'      => 8,
            'prefix'  => 'satapi:',
        ),
        // 'dir' => '/www/wwwroot/satellite.api.authskin.xyz/data/cache',
    ),

    'admin' => array(
        // Required for POST /v1/maintenance. Prefer the environment variable
        // SATAPI_ADMIN_TOKEN so the secret is not stored in the repository.
        'token' => '',
    ),

    'ratelimit' => array(
        // The CDN already absorbs most abuse; enable this to protect the
        // origin from cache-miss stampedes.
        'enabled'     => false,
        'per_minute'  => 120,
        'trust_proxy' => true, // EdgeOne / nginx sets X-Forwarded-For
    ),

    'http' => array(
        // Never disable TLS verification in production. Only useful for
        // debugging against a self-signed upstream.
        'verify_tls' => true,
        'timeout'    => 20,
        'retries'    => 2,
    ),

    'legacy' => array(
        // Send Deprecation / Link headers on the pre-v1 routes.
        'warn'   => true,
        // Optional RFC 8594 sunset date, e.g. 'Wed, 31 Dec 2026 23:59:59 GMT'.
        'sunset' => '',
    ),

    'logs' => array(
        'level' => 'warning', // debug | info | warning | error
    ),
);
