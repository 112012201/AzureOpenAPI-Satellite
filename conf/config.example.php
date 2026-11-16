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

    // Holiday themes. The calendar in conf/themes.php decides when each one runs --
    // Christmas 24-26 December, New Year 31 December - 2 January, Spring Festival
    // 4-10 February (all Asia/Shanghai) -- so normally NOTHING needs to be configured
    // here. This block exists to pin one theme for testing, or to hold one on outside
    // its window:
    //
    //   'theme' => array('season' => 'christmas'),   // default | christmas | newyear | spring
    //   'theme' => array('season' => 'auto'),        // the calendar (the default)
    //
    // A pinned theme is the same document for everybody and stays cacheable; a theme
    // chosen by ?season= or by the visitor's own "leave" button is served no-store.
    // Environment equivalent: SATAPI_THEME_SEASON=christmas

    'service' => array(
        // 'name'        => 'AzureOpenAPI for Satellite',
        // 'version'     => '3.3.1+ra',
        // 'homepage'    => 'https://www.authskin.xyz',
        // 'build'       => 'edgeone-2026-02',
    ),

    // Search-engine identity.
    //
    // `site` is the host these pages are canonically served from: it is what goes
    // into <link rel="canonical">, og:url and the sitemap, and it must be the host
    // that answers HTTP itself rather than a CDN alias. Left empty it resolves to
    // the `origin` entry of the hostname registry, which is that host.
    //
    // It is NOT 'service.homepage': that one is the operator's own site, a
    // different site. The two used to share a value, and the landing page spent
    // months telling search engines its canonical copy lived over there.
    //
    // 'seo' => array(
    //     'site'    => 'https://satellite.authskin.xyz',
    //     'same_as' => array(),   // real profiles elsewhere, if any
    // ),

    // Which jurisdiction and which language a visitor is served under. Both tables
    // are compiled by bin/build-cn-ranges.php from the APNIC delegation file; the
    // request path only reads them. Mainland addresses (CC=CN) get the mainland
    // documents in Simplified Chinese, Hong Kong/Macao/Taiwan addresses get the
    // international documents in Traditional Chinese, and everything else gets
    // English (en-US or en-GB, chosen from the browser's own English subtag).
    // 'region' => array(
    //     'ranges'     => 'data/cn-ranges.php',
    //     'hmt_ranges' => 'data/hmt-ranges.php',
    // ),

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

        // Which layer emits the four security headers on dynamic responses.
        // The application owns them by default: it is the only source that also
        // covers Apache and `php -S`, and a server-level nginx group is applied
        // twice to fastcgi responses on this box (measured).
        //   'app'   (default) — PHP sends all four.
        //   'nginx'           — PHP sends none. Only correct if the nginx config
        //                       is changed in step (see the rewrite file's
        //                       section 6); otherwise dynamic responses end up
        //                       with no security headers at all.
        //   'both'            — v2.2 behaviour (duplicates). Rollback only.
        'security_headers_owner' => 'app',
    ),

    'metrics' => array(
        // Request/upstream telemetry. `driver` selects the sink:
        //   'file'  — single JSON document (works with no Redis/MySQL)
        //   'redis' — atomic counters in Redis db 9
        //   'both'  — write to both, read from Redis with file as fallback
        'driver' => 'both',
        'redis'  => array(
            'db'     => 9,
            'prefix' => 'satapi:m:',
        ),
        // Historical rollups go to MySQL. Leave user/password empty to disable.
        'mysql' => array(
            'enabled'   => false,
            'host'      => '127.0.0.1',
            'port'      => 3306,
            'database'  => 'satapi',
            'user'      => '',
            'password'  => '',   // prefer SATAPI_MYSQL_PASSWORD
            'charset'   => 'utf8mb4',
            'retention_days' => 90,
        ),
        // Redis minute buckets live this long (seconds).
        'redis_retention' => 172800,
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
