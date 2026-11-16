<?php

namespace SatApi\Http;

use SatApi\Config;

/**
 * Read-only view over the incoming request.
 */
final class Request
{
    /** @var array<string,string> */
    private $headers = array();

    private $method = 'GET';
    private $path = '/';
    private $query = array();
    private $rawQuery = '';
    private $body = null;
    private $requestId = '';

    public static function capture()
    {
        $request = new self();
        $request->method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
        $request->rawQuery = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        parse_str($request->rawQuery, $request->query);

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $request->path = self::normalizePath(is_string($path) ? $path : '/');

        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $request->headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $request->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $request->headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $request->requestId = self::sanitizeRequestId(
            isset($request->headers['x-request-id']) ? $request->headers['x-request-id'] : ''
        );

        return $request;
    }

    /**
     * Build a request from an explicit path, query and headers.
     *
     * `capture()` reads the SAPI superglobals, which makes negotiation rules
     * (`format`, `envelope`, content type) awkward to exercise without spawning a
     * process per case. The CLI tooling needs the same thing, so this is a
     * first-class constructor rather than a test-only seam.
     *
     * @param string               $path    e.g. "/v1/tle/weather"
     * @param array<string,string> $query   e.g. ['format' => 'json']
     * @param array<string,string> $headers lowercase header names
     * @param string               $method
     * @return self
     */
    public static function fromParts($path, array $query = array(), array $headers = array(), $method = 'GET')
    {
        $request = new self();
        $request->method = strtoupper((string) $method);
        $request->query = $query;
        $request->rawQuery = http_build_query($query);
        $request->path = self::normalizePath($path);
        foreach ($headers as $name => $value) {
            $request->headers[strtolower((string) $name)] = (string) $value;
        }
        $request->requestId = self::generateRequestId();

        return $request;
    }

    private static function normalizePath($path)
    {
        $path = '/' . ltrim((string) $path, '/');
        // Collapse duplicate slashes and resolve the few dot segments we allow.
        $path = preg_replace('#/+#', '/', $path);
        if ($path === '' || $path === false) {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private static function sanitizeRequestId($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value)) {
            return self::generateRequestId();
        }

        return $value;
    }

    public static function generateRequestId()
    {
        try {
            $bytes = random_bytes(8);
        } catch (\Exception $e) {
            $bytes = pack('N2', mt_rand(), mt_rand());
        }

        return 'req_' . bin2hex($bytes);
    }

    public function method()
    {
        return $this->method;
    }

    public function path()
    {
        return $this->path;
    }

    public function queryString()
    {
        return $this->rawQuery;
    }

    public function requestId()
    {
        return $this->requestId;
    }

    public function header($name, $default = null)
    {
        $name = strtolower((string) $name);

        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all()
    {
        return $this->query;
    }

    /**
     * Query parameter access with type coercion.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function param($key, $default = null)
    {
        if (!array_key_exists($key, $this->query)) {
            return $default;
        }
        $value = $this->query[$key];
        if (is_array($value)) {
            return $default;
        }

        return $value;
    }

    public function bool($key, $default = false)
    {
        $value = $this->param($key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on', ''), true);
    }

    public function int($key, $default = 0, $min = null, $max = null)
    {
        $value = $this->param($key, null);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        $value = (int) $value;
        if ($min !== null && $value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * Comma separated list parameter, e.g. ?norad=25544,43017
     *
     * @return string[]
     */
    public function list($key)
    {
        $value = $this->param($key, '');
        if (!is_string($value) || trim($value) === '') {
            return array();
        }
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static function ($item) {
            return $item !== '';
        });

        return array_values($parts);
    }

    /**
     * Whether the caller prefers JSON. `?format=json` always wins.
     */
    public function wantsJson($default = false)
    {
        $format = strtolower((string) $this->param('format', ''));
        if ($format !== '') {
            return in_array($format, array('json', 'application/json'), true);
        }

        $accept = strtolower((string) $this->header('accept', ''));
        if ($accept === '' || $accept === '*/*') {
            return $default;
        }

        $jsonPos = strpos($accept, 'application/json');
        $htmlPos = strpos($accept, 'text/html');
        $textPos = strpos($accept, 'text/plain');

        if ($jsonPos !== false && ($textPos === false || $jsonPos < $textPos)) {
            return true;
        }
        if ($textPos !== false) {
            return false;
        }
        if ($htmlPos !== false) {
            return false;
        }

        return $default;
    }

    /**
     * The representation to answer with, normalised to one of: tle, json, txt,
     * ndjson.
     *
     * Precedence, highest first:
     *
     *   1. `?json=` — an explicit statement of format. `?json=true` (or `1`, `yes`,
     *      `on`, or a bare `?json`) asks for JSON, `?json=false` (or `0`, `no`,
     *      `off`) asks for standard TLE text. This outranks both `?format=` and the
     *      Accept header, because it is what a person typing a URL reaches for and
     *      it must not be outvoted by whatever the browser or a wrapper happens to
     *      put in Accept.
     *
     *      It exists because the DEFAULT is standard TLE text: a plain browser tab
     *      on /v1/tle shows 20-column TLE, and JSON is one query parameter away.
     *      Before this, a wrapper that sent `Accept: application/json` silently turned
     *      every TLE URL into JSON -- the header was choosing the representation on
     *      the caller's behalf, which is exactly the confusion this switch removes.
     *
     *   2. `?format=` — the original switch, kept byte-compatible. It also accepts
     *      media-type spellings (`format=application/json`, `format=text/plain`).
     *
     *   3. Accept negotiation — an API client that explicitly names
     *      `application/json` still gets JSON, which is correct content negotiation
     *      and what existing integrations rely on. A browser does not send that, so a
     *      browser gets text. **Pass `$acceptMayChoose = false` to switch this off**
     *      where the representation must depend on the URL alone; see the note below.
     *
     * WHY AN ENDPOINT MIGHT REFUSE TO LET ACCEPT DECIDE
     *
     * A representation chosen by a request header is only safe behind a shared cache
     * that keys on that header. Measured on 2026-10-28 through the SCDN edge:
     *
     *     GET /v1/tle?limit=3  Accept: application/json   -> JSON, edge MISS then HIT
     *     GET /v1/tle?limit=3  Accept: text/plain         -> JSON, edge HIT   <-- wrong
     *     response: vary: Accept-Encoding                  <-- Accept is not in Vary
     *
     * So whoever fills the cache entry first decides what everybody else receives.
     * The TLE routes therefore pass `false`: their format is `?json=`, then
     * `?format=`, and nothing else. That is also what makes `/v1/tle` genuinely
     * default to TLE text for every client rather than "text unless your Accept says
     * otherwise, or unless a stranger warmed the cache".
     *
     * @param string[] $allowed formats this endpoint can answer with
     * @param bool     $acceptMayChoose false to make the response depend on the URL only
     * @return string|null null when a value was given but the endpoint cannot honour
     *                     it, so the caller answers 400 instead of guessing
     */
    public function format($allowed = array('tle', 'json'), $acceptMayChoose = true)
    {
        // 1. the explicit switch
        if ($this->param('json', null) !== null) {
            $want = $this->bool('json', true) ? 'json' : 'tle';
            // A JSON-only endpoint (Catalog) cannot answer `?json=false`; that is an
            // unsupported request for this endpoint, not a format preference.
            return in_array($want, $allowed, true) ? $want : null;
        }

        // 2. ?format=
        $format = strtolower(trim((string) $this->param('format', '')));
        if ($format === '') {
            // 3. Accept (when the endpoint allows it), then the endpoint's own default.
            return ($acceptMayChoose && $this->wantsJson()) ? 'json' : $allowed[0];
        }
        $aliases = array(
            'application/json' => 'json',
            'text/plain'       => 'tle',
            'plain'            => 'tle',
            'text'             => 'txt',
        );
        if (isset($aliases[$format])) {
            $format = $aliases[$format];
        }
        if (!in_array($format, $allowed, true)) {
            return null; // caller turns this into a 400 problem response
        }

        return $format;
    }

    /**
     * Response envelope shape: `full` (meta + data) or `none` (data + the
     * freshness fields only).
     *
     * The data-only shape exists because a consumer that parses the top level as
     * an array cannot read `{meta, data}` at all: it sees an object where it
     * expected a list and either errors or yields zero rows. `envelope=none`
     * answers that case without breaking every existing client, which keeps the
     * default untouched.
     *
     * `generated`, `count` and `total` are deliberately retained in the flat
     * shape: a bare array would drop the only signal a client has for judging how
     * fresh the payload is.
     *
     * @return string|null null when the value is present but not recognised, so
     *                     the caller can answer 400 instead of guessing
     */
    public function envelope($allowed = array('full', 'none'))
    {
        $value = strtolower(trim((string) $this->param('envelope', '')));
        if ($value === '') {
            return $allowed[0];
        }
        $aliases = array(
            'data'      => 'none',
            'data-only' => 'none',
            'dataonly'  => 'none',
            'flat'      => 'none',
            'bare'      => 'none',
            'default'   => 'full',
            'normal'    => 'full',
        );
        if (isset($aliases[$value])) {
            $value = $aliases[$value];
        }
        if (!in_array($value, $allowed, true)) {
            return null;
        }

        return $value;
    }

    public function acceptsGzip()
    {
        $encoding = strtolower((string) $this->header('accept-encoding', ''));

        return strpos($encoding, 'gzip') !== false;
    }

    public function ifNoneMatch()
    {
        return trim((string) $this->header('if-none-match', ''));
    }

    public function ifModifiedSince()
    {
        $value = trim((string) $this->header('if-modified-since', ''));
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);

        return $time === false ? 0 : (int) $time;
    }

    /**
     * Client IP, honouring X-Forwarded-For only when explicitly trusted
     * (the CDN / reverse proxy sets it).
     */
    public function ip()
    {
        if ((bool) Config::get('ratelimit.trust_proxy', false)) {
            $forwarded = (string) $this->header('x-forwarded-for', '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '' && preg_match('/^[0-9a-fA-F:.]{3,45}$/', $first)) {
                    return $first;
                }
            }
            $real = (string) $this->header('x-real-ip', '');
            if ($real !== '' && preg_match('/^[0-9a-fA-F:.]{3,45}$/', $real)) {
                return $real;
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    public function userAgent()
    {
        return substr((string) $this->header('user-agent', ''), 0, 255);
    }

    public function isBrowser()
    {
        $accept = strtolower((string) $this->header('accept', ''));
        if (strpos($accept, 'text/html') !== false) {
            return true;
        }
        $ua = strtolower($this->userAgent());

        return strpos($ua, 'mozilla') !== false && strpos($accept, 'application/json') === false;
    }

    public function rawBody()
    {
        if ($this->body === null) {
            $this->body = (string) file_get_contents('php://input');
        }

        return $this->body;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonBody()
    {
        $raw = $this->rawBody();
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Absolute URL of the current request (used for JSON-LD / problem type URIs).
     */
    public function selfUrl()
    {
        $host = (string) $this->header('host', '');
        $scheme = 'http';
        if ((string) $this->header('x-forwarded-proto', '') !== '') {
            $scheme = strtolower(explode(',', (string) $this->header('x-forwarded-proto'))[0]);
        } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }
        $host = $host !== '' ? $host : 'localhost';

        return $scheme . '://' . $host . $this->path;
    }

    public function baseUrl()
    {
        return rtrim(str_replace($this->path, '', $this->selfUrl()), '/');
    }
}
