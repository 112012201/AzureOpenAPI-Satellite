<?php

namespace SatApi\Http;

use SatApi\Config;
use SatApi\Logger;

/**
 * Response builder.
 *
 * Responsibilities:
 *  - content negotiation result -> correct Content-Type
 *  - ETag / Last-Modified + conditional requests (304)
 *  - cache directives aimed at browsers AND the EdgeOne CDN
 *  - CORS + security headers
 *  - optional gzip when the web server does not compress for us
 *  - "flush first, refresh later" for stale-while-revalidate
 */
final class Response
{
    /** @var Request|null */
    private static $request = null;

    /** @var callable|null */
    private static $defer = null;

    /**
     * Optional observer invoked right before the body is written:
     * fn(int $status, array $headers, int $bytes). The test harness uses it to
     * assert on the real status line and headers, which are not otherwise
     * observable from the CLI SAPI.
     *
     * @var callable|null
     */
    public static $observer = null;

    private static $sent = false;

    public static function bind(Request $request)
    {
        self::$request = $request;
    }

    /**
     * Send a JSON document.
     *
     * @param mixed $data
     */
    public static function json($data, $status = 200, array $headers = array(), $defer = null)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        // An explicit media type (e.g. application/problem+json) wins.
        $contentType = 'application/json; charset=utf-8';
        if (isset($headers['Content-Type'])) {
            $contentType = (string) $headers['Content-Type'];
            unset($headers['Content-Type']);
        }
        if (self::$request !== null && self::$request->bool('pretty', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $body = json_encode($data, $flags);
        if ($body === false) {
            $body = '{"type":"about:blank","title":"Serialization failed","status":500}';
            $status = 500;
            $contentType = 'application/problem+json; charset=utf-8';
        }

        self::emit($body, $contentType, $status, $headers, $defer);
    }

    /**
     * Send raw bytes (TLE text, HTML, ...).
     */
    public static function raw($body, $contentType, $status = 200, array $headers = array(), $defer = null)
    {
        self::emit((string) $body, $contentType, $status, $headers, $defer);
    }

    public static function redirect($location, $status = 302)
    {
        self::emit('', 'text/plain; charset=utf-8', $status, array('Location' => $location));
    }

    public static function noContent($status = 204, array $headers = array())
    {
        self::emit('', 'text/plain; charset=utf-8', $status, $headers);
    }

    /**
     * Central output path.
     *
     * @param string        $body
     * @param string        $contentType
     * @param int           $status
     * @param array         $headers
     * @param callable|null $defer Work to run after the response is flushed.
     */
    public static function emit($body, $contentType, $status = 200, array $headers = array(), $defer = null)
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $status = (int) $status;

        // ---- conditional request handling (only for successful payloads) ----
        $etag = isset($headers['ETag']) ? $headers['ETag'] : null;
        $notModified = false;

        if ($status === 200 && self::$request !== null) {
            if ($etag === null && $body !== '') {
                $etag = '"' . md5($body) . '"';
            }
            if ($etag !== null && self::etagMatches(self::$request->ifNoneMatch(), $etag)) {
                $notModified = true;
            }
            $lastModified = isset($headers['Last-Modified']) ? (int) $headers['Last-Modified'] : 0;
            if (!$notModified && $lastModified > 0 && self::$request->ifNoneMatch() === '') {
                $since = self::$request->ifModifiedSince();
                if ($since > 0 && $lastModified <= $since) {
                    $notModified = true;
                }
            }
        }

        // Normalise helper headers into HTTP form.
        if (isset($headers['Last-Modified']) && is_int($headers['Last-Modified'])) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $headers['Last-Modified']) . ' GMT';
        }
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        $headers = self::withDefaults($headers, $contentType, $notModified ? 304 : $status);

        if ($notModified) {
            $status = 304;
            $body = '';
            unset($headers['Content-Type']);
        }
        header('HTTP/1.1 ' . $status . ' ' . self::reason($status));
        http_response_code($status);

        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            header($name . ': ' . $value, true);
        }

        // ---- body ----
        $headOnly = self::$request !== null && self::$request->method() === 'HEAD';

        if ($status === 304 || $status === 204) {
            self::notifyObserver($status, $headers, 0);
            self::record($status, 0, $headers);
            self::flushAndDefer($defer);

            return;
        }

        if ($headOnly) {
            header('Content-Length: ' . strlen($body));
            self::notifyObserver($status, $headers, 0);
            self::record($status, 0, $headers);
            self::flushAndDefer($defer);

            return;
        }

        $encoded = self::maybeCompress($body);
        if ($encoded !== null) {
            $body = $encoded;
            header('Content-Encoding: gzip');
            $headers['Content-Encoding'] = 'gzip';
        }
        header('Content-Length: ' . strlen($body));
        $headers['Content-Length'] = (string) strlen($body);

        self::notifyObserver($status, $headers, strlen($body));
        self::record($status, strlen($body), $headers);

        echo $body;

        self::flushAndDefer($defer);
    }

    /**
     * Hand the emitted status/headers to the optional test observer.
     */
    private static function notifyObserver($status, array $headers, $bytes)
    {
        if (is_callable(self::$observer)) {
            call_user_func(self::$observer, $status, $headers, $bytes);
        }
    }

    private static function record($status, $bytes, array $headers)
    {
        \SatApi\Support\Metrics::record(
            \SatApi\Support\Metrics::currentEndpoint(),
            $status,
            (int) round((microtime(true) - (defined('SATAPI_START') ? SATAPI_START : microtime(true))) * 1000),
            isset($headers['X-Cache-Status']) ? (string) $headers['X-Cache-Status'] : '',
            (int) $bytes
        );
    }

    private static function withDefaults(array $headers, $contentType, $status = 200)
    {
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $contentType;
        }

        if ($status >= 400) {
            $defaultCache = 'no-store, no-cache, must-revalidate';
        } else {
            $defaultCache = 'public, max-age=60, s-maxage=300, stale-while-revalidate=600, stale-if-error=86400';
        }

        $defaults = array(
            'X-Service-Version'      => (string) Config::get('service.version', ''),
            'Cache-Control'          => $defaultCache,
        );

        // Security headers are only emitted here when this process owns them.
        // Under nginx the site config already sends nosniff / Referrer-Policy /
        // X-Frame-Options / Permissions-Policy, and emitting them a second time
        // makes the response carry the same header twice — scanners flag that as
        // a misconfiguration. The single owner is chosen by
        // `http.security_headers_owner` (nginx | app | both).
        foreach (self::securityHeaders() as $name => $value) {
            $defaults[$name] = $value;
        }

        foreach ($defaults as $name => $value) {
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }

        if (self::$request !== null) {
            if (!isset($headers['X-Request-Id'])) {
                $headers['X-Request-Id'] = self::$request->requestId();
            }
            $headers = self::withCors($headers);
        }

        if (!isset($headers['Vary'])) {
            $headers['Vary'] = 'Accept, Accept-Encoding';
        }

        return $headers;
    }

    /**
     * Security headers this process is responsible for.
     *
     * Established by three isolation experiments on the live site, not assumed:
     *
     *   1. nginx `add_header` does NOT reach a fastcgi response served through
     *      `location ~ [^/]\.php` unless it is written at server level or inside
     *      that location: with the server-level group removed, /v1/meta lost
     *      X-Content-Type-Options entirely (count 0).
     *   2. A server-level group applies TWICE to responses that reach PHP but
     *      once to static responses. Commenting out the panel's vhost copy took
     *      /v1/meta's X-Content-Type-Options from 2 to 1, which is how the
     *      duplicate was attributed to the vhost and not to PHP.
     *   3. An exact-match or prefix location that declares any `add_header` drops
     *      the whole inherited server-level group: `location = /` carrying only
     *      Cache-Control measured 0 copies of all four headers.
     *
     * Conclusion: the application is the only source that can cover dynamic
     * responses under nginx AND under Apache / `php -S` with the same code. So
     * PHP emits all four, and the nginx site config emits none at server level
     * (it duplicates) while every static location declares the four itself
     * (because of rule 3).
     *
     *   owner = 'app'   (default) -> all four, from this process
     *   owner = 'nginx' -> array(); only correct for a deployment whose nginx
     *                      config sends the four on dynamic responses too --
     *                      otherwise every dynamic response loses them
     *   owner = 'both'  -> all four; duplicates, kept as a rollback switch
     *
     * @return array<string,string>
     */
    private static function securityHeaders()
    {
        $owner = strtolower((string) Config::get('http.security_headers_owner', 'app'));
        if ($owner === 'nginx') {
            return array();
        }

        return array(
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
        );
    }

    private static function withCors(array $headers)
    {
        if (!(bool) Config::get('cors.enabled', true)) {
            return $headers;
        }
        $origin = (string) Config::get('cors.allow_origin', '*');
        $requestOrigin = self::$request !== null ? (string) self::$request->header('origin', '') : '';

        if ($origin === '*') {
            $headers['Access-Control-Allow-Origin'] = '*';
        } else {
            $allowed = array_map('trim', explode(',', $origin));
            if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
                $headers['Access-Control-Allow-Origin'] = $requestOrigin;
                $headers['Vary'] = isset($headers['Vary']) ? $headers['Vary'] . ', Origin' : 'Origin';
            }
        }
        $headers['Access-Control-Allow-Methods'] = (string) Config::get('cors.allow_methods', 'GET, HEAD, OPTIONS');
        $headers['Access-Control-Allow-Headers'] = (string) Config::get('cors.allow_headers', 'Content-Type');
        $headers['Access-Control-Expose-Headers'] = (string) Config::get('cors.expose_headers', 'ETag');
        $headers['Access-Control-Max-Age'] = (string) Config::get('cors.max_age', 86400);

        return $headers;
    }

    private static function etagMatches($ifNoneMatch, $etag)
    {
        if ($ifNoneMatch === '') {
            return false;
        }
        if ($ifNoneMatch === '*') {
            return true;
        }
        $etag = trim($etag, '"');
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (strncmp($candidate, 'W/', 2) === 0) {
                $candidate = substr($candidate, 2);
            }
            if (trim($candidate, '"') === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function maybeCompress($body)
    {
        if (!(bool) Config::get('compression.enabled', true)) {
            return null;
        }
        if (self::$request === null || !self::$request->acceptsGzip()) {
            return null;
        }
        if (strlen($body) < (int) Config::get('compression.min_bytes', 1024)) {
            return null;
        }
        if (!function_exists('gzencode')) {
            return null;
        }
        $level = (int) Config::get('compression.level', 6);

        $encoded = gzencode($body, $level);

        return $encoded === false ? null : $encoded;
    }

    private static function flushAndDefer($defer)
    {
        if (function_exists('fastcgi_finish_request')) {
            // FPM: hand the response to the client, then keep working.
            @fastcgi_finish_request();
        } else {
            @flush();
        }

        if (is_callable($defer)) {
            try {
                call_user_func($defer);
            } catch (\Throwable $e) {
                Logger::error('deferred_task_failed', array('message' => $e->getMessage()));
            }
        }
    }

    public static function reason($status)
    {
        static $reasons = array(
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout',
            409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
            503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        );

        return isset($reasons[$status]) ? $reasons[$status] : 'Status';
    }
}
