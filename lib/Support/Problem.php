<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;

/**
 * RFC 9457 (formerly RFC 7807) problem details.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9457
 */
final class Problem
{
    /** @var Request|null */
    private static $request = null;
    private static $typeBase = 'https://satellite.api.authskin.xyz/errors/';
    private static $textPreferred = false;

    public static function bind(Request $request)
    {
        self::$request = $request;
    }

    /**
     * Routes that natively answer with text/plain (TLE feeds) keep a plain
     * text error body so existing consumers do not have to parse JSON.
     */
    public static function setTextPreferred($preferred)
    {
        self::$textPreferred = (bool) $preferred;
    }

    /**
     * Build a problem document.
     *
     * @param int    $status
     * @param string $title
     * @param string $detail
     * @param string $code
     * @param array  $extra
     * @return array<string,mixed>
     */
    public static function build($status, $title, $detail, $code, array $extra = array())
    {
        $document = array(
            'type'       => self::$typeBase . str_replace('_', '-', (string) $code),
            'title'      => (string) $title,
            'status'     => (int) $status,
            'detail'     => (string) $detail,
            'code'       => (string) $code,
        );

        if (self::$request !== null) {
            $document['instance'] = self::$request->path();
            $document['request_id'] = self::$request->requestId();
        }
        if (!empty($extra)) {
            $document += $extra;
        }

        return $document;
    }

    /**
     * Emit the problem document and stop. Content negotiation keeps plain-text
     * clients (TLE consumers) working.
     *
     * @param int    $status
     * @param string $title
     * @param string $detail
     * @param string $code
     * @param array  $extra
     * @param array  $headers
     */
    public static function send($status, $title, $detail, $code, array $extra = array(), array $headers = array())
    {
        $document = self::build($status, $title, $detail, $code, $extra);

        $wantsJson = self::$request !== null ? self::$request->wantsJson(true) : true;
        if (self::$textPreferred && self::$request !== null) {
            $format = strtolower((string) self::$request->param('format', ''));
            $accept = strtolower((string) self::$request->header('accept', ''));
            $explicitJson = ($format === 'json' || strpos($accept, 'application/json') !== false);
            $wantsJson = $explicitJson;
        }

        if (!$wantsJson) {
            // Plain-text clients get a compact, greppable error.
            $body = "# " . $status . " " . $title . "\n# " . $detail . "\n";
            if (isset($document['code'])) {
                $body .= "# code: " . $document['code'] . "\n";
            }
            $retryAfter = isset($headers['Retry-After']) ? $headers['Retry-After'] : null;
            if ($retryAfter !== null) {
                $body .= "# retry-after: " . $retryAfter . "\n";
            }
            \SatApi\Http\Response::raw($body, 'text/plain; charset=utf-8', $status, $headers);
        } else {
            \SatApi\Http\Response::json($document, $status, $headers + array(
                'Content-Type' => 'application/problem+json; charset=utf-8',
            ));
        }

        // Stop the request here in production: an error response is the whole answer, and
        // returning to the caller would let an endpoint append a second one.
        //
        // Under a test or CLI dispatch there is no request to end, and exiting kills the
        // PROCESS -- which is how a test suite loses its summary: one code path reached a
        // problem response, exit ran, and the run ended at that line with status 0, so it
        // looked like a pass. SATAPI_NO_EXIT makes that boundary explicit instead of
        // leaving the suite to be silently truncated.
        if (!defined('SATAPI_NO_EXIT')) {
            exit;
        }
    }

    public static function typeBase()
    {
        return self::$typeBase;
    }

    public static function setTypeBase($base)
    {
        self::$typeBase = rtrim((string) $base, '/') . '/';
    }

    /**
     * Convenience list of the problem types this API can emit, for /v1/meta.
     *
     * @return array<string,string>
     */
    public static function catalog()
    {
        return array(
            'bad_request'          => 'Malformed or contradictory query parameters.',
            'not_found'            => 'Unknown route or unknown resource identifier.',
            'method_not_allowed'   => 'HTTP method not supported on this route.',
            'maintenance'          => 'The endpoint is temporarily disabled by an operator.',
            'upstream_unavailable' => 'All upstream providers failed and no cache was usable.',
            'rate_limited'         => 'Client exceeded the configured request budget.',
            'payload_too_large'    => 'Request body exceeded the accepted size.',
            'unauthorized'         => 'Missing or invalid admin credentials.',
            // RFC 7725. Emitted only while consent.required is on; the lenient
            // default advertises the requirement through meta.consent instead.
            'consent_required'     => 'The privacy policy and terms of service have not been acknowledged.',
            'pow_failed'           => 'The proof-of-work token was missing, invalid or expired.',
            'internal_error'       => 'Unexpected server-side failure.',
        );
    }

    public static function docsUrl()
    {
        return Config::get('service.homepage', '') . '/docs.html#errors';
    }
}
