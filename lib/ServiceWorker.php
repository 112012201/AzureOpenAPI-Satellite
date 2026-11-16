<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;

/**
 * Serves the pre-answer ServiceWorker.
 *
 * WHY THE URL IS /v1/sw -- ROUTING ON THIS HOST IS A WHITELIST, NOT A CATCH-ALL
 *
 * Measured on the live vhost (2026-10-28), the panel rewrite only sends these paths
 * to index.php, and NOTHING ELSE reaches PHP:
 *
 *     location = /                                            -> index.php
 *     location ~ ^/(v1|tle|satnogs|amsat|health|status)(/.*)?$ -> index.php
 *     location = /index, location = /docs                     -> index.php
 *     location ~ ^/(legal|privacy|terms|error|verify)$         -> index.php
 *
 * Everything else is a static lookup, which is why /sw and /sw.js both answered a
 * 404 from nginx without PHP ever running. The file itself documents the reason:
 * "只列白名单精确匹配,绝不写通配 catch-all" -- a catch-all try_files would let the
 * landing page swallow /tle/weather. Adding a `/sw` line would mean editing
 * /www/server, which this project does not do from code: the web server belongs to
 * the operator, and a rewritten vhost is one reload away from taking the site down.
 *
 * So the worker lives under /v1/, which is already routed to the front controller.
 *
 * SCOPE. A worker's default scope is its script's directory, so /v1/sw would default
 * to /v1/ and could never see a navigation to the legacy /tle. The response therefore
 * carries `Service-Worker-Allowed: /`, the header that widens the allowed scope to the
 * origin root. That is the one thing the header exists for, and the reason the worker
 * is served by PHP rather than as a static file (a static server will not add it).
 */
final class ServiceWorker
{
    /**
     * Paths that serve the worker.
     *
     * The first is the canonical one the browser registers. The others are kept
     * because an installed worker keeps polling the URL it was registered with: if a
     * future deployment adds an nginx exception for /sw, a worker registered there
     * must not be orphaned by a 404 (a worker that cannot re-fetch its own script can
     * never be updated or removed).
     */
    const PATHS = array('/v1/sw', '/sw', '/sw.js');

    public static function handles(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return false;
        }
        $path = rtrim((string) $request->path(), '/');

        return in_array($path === '' ? '/' : $path, self::PATHS, true);
    }

    public static function render(Request $request)
    {
        $file = Config::path('assets/sw.js');
        $body = is_file($file) ? (string) @file_get_contents($file) : '';

        if ($body === '') {
            // A missing worker must fail as a 404, not as an empty JavaScript file:
            // an empty file would install a worker that intercepts nothing and then
            // quietly keep control of every navigation until it is unregistered.
            Response::raw("Not Found\n", 'text/plain; charset=utf-8', 404, array(
                'Cache-Control' => 'no-store',
            ));

            return;
        }

        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $headers = array(
            // Short, not immutable: the worker's own update check compares the bytes,
            // and `immutable` would let a browser keep an old copy past the point
            // where the interception rules changed.
            'Cache-Control' => 'public, max-age=300',
            // The header that lets a root-scoped worker be served from a nested path
            // if that is ever chosen instead. Harmless here, and the reason it is
            // spelled out is so the constraint is visible to the next reader.
            'Service-Worker-Allowed' => '/',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
        );

        if (trim((string) $request->header('if-none-match', '')) === $etag) {
            Response::noContent(304, $headers);

            return;
        }

        Response::raw($body, 'application/javascript; charset=utf-8', 200, $headers);
    }
}
