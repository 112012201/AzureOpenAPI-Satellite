<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Legacy;
use SatApi\Support\Problem;

/**
 * Shared helpers for endpoint handlers: response envelope and caching headers.
 */
abstract class Base
{
    /**
     * Build the `meta` block shared by every JSON envelope.
     *
     * @param array $extra
     * @return array<string,mixed>
     */
    protected static function envelopeMeta(Request $request, array $extra = array())
    {
        $meta = array(
            'service'     => (string) Config::get('service.name', 'AzureOpenAPI for Satellite'),
            'version'     => (string) Config::get('service.version', ''),
            'api_version' => 'v1',
            'generated'   => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id'  => $request->requestId(),
            'elapsed_ms'  => (int) round((microtime(true) - (defined('SATAPI_START') ? SATAPI_START : microtime(true))) * 1000),
        );

        // Attach the acknowledgement state so an interactive client learns about the
        // requirement from the payload it is already parsing, without a second call.
        // Emitted only while unacknowledged, so a compliant client's meta block is
        // byte-identical to what it was before this feature existed.
        $consent = \SatApi\Support\Consent::current();
        if (is_array($consent) && empty($consent['accepted'])) {
            $meta['consent'] = \SatApi\Support\Consent::payload($consent);
        }

        return array_merge($meta, $extra);
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array<string,mixed>
     */
    protected static function envelope(array $data, array $meta)
    {
        return array('meta' => $meta, 'data' => $data);
    }

    /**
     * Data-only envelope: `{"data": …, "generated": …, "count": …}`.
     *
     * Kept separate from `envelope()` rather than folded into it because the two
     * shapes have different audiences: the full envelope carries per-request
     * diagnostics, while this one exists so a client that parses the top level as
     * an array can read the payload. `generated` and `count` survive on purpose --
     * a bare array would leave the client unable to judge freshness.
     *
     * `total` is carried through when the caller knows it (paginated endpoints do),
     * because a paging client needs the full size to know when to stop. It is not
     * synthesised: an endpoint that has no such notion simply omits it.
     *
     * @param array $data
     * @param array $meta the meta block produced by envelopeMeta()
     * @return array<string,mixed>
     */
    protected static function dataOnlyEnvelope(array $data, array $meta)
    {
        $out = array('data' => $data);
        foreach (array('generated', 'count', 'total') as $key) {
            if (array_key_exists($key, $meta)) {
                $out[$key] = $meta[$key];
            }
        }
        // `count` must describe the payload actually returned, whatever the caller
        // passed in the meta block. Paginated endpoints set meta.count correctly,
        // but a future caller forgetting to would silently publish a wrong number.
        $out['count'] = count($data);

        return $out;
    }

    /**
     * Resolve the requested envelope shape, or answer 400 and stop.
     *
     * Centralised so every JSON endpoint rejects an unsupported value the same way
     * and none of them silently falls back to the default.
     *
     * @return string 'full' | 'none'
     */
    protected static function resolveEnvelope(Request $request)
    {
        $envelope = $request->envelope(array('full', 'none'));
        if ($envelope === null) {
            Problem::send(
                400,
                'Bad Request',
                'Unsupported envelope. Use envelope=full ({"meta":…,"data":…}) or envelope=none ({"data":…,"generated":…,"count":…}).',
                'bad_request',
                array('parameter' => 'envelope', 'allowed' => array('full', 'none'))
            );
        }

        return $envelope;
    }

    /**
     * Send a JSON payload in the requested envelope shape.
     *
     * @param mixed $data
     * @param array $meta
     * @param int   $status
     * @param array $headers
     * @param mixed $defer
     */
    protected static function respondData(Request $request, $data, array $meta, $status = 200, array $headers = array(), $defer = null)
    {
        $envelope = self::resolveEnvelope($request);

        // The envelope participates in the ETag: a client that switches shapes must
        // not be handed a 304 for a representation it has never seen.
        if (isset($headers['ETag'])) {
            $headers['ETag'] = self::etag(array('envelope' => $envelope, 'etag' => $headers['ETag']));
        }
        // Merge, do not replace: Response::withDefaults() adds
        // "Accept, Accept-Encoding" later, but a caller may already have set Vary,
        // and clobbering it would let a cache serve one envelope for the other.
        $vary = isset($headers['Vary']) ? trim((string) $headers['Vary']) : '';
        if (stripos($vary, 'envelope') === false) {
            $headers['Vary'] = $vary === '' ? 'envelope' : $vary . ', envelope';
        }

        if ($envelope === 'none') {
            Response::json(self::dataOnlyEnvelope((array) $data, $meta), $status, $headers, $defer);

            return;
        }

        Response::json(self::envelope((array) $data, $meta), $status, $headers, $defer);
    }

    /**
     * Cache-Control + cache diagnostics aimed at both browsers and EdgeOne.
     *
     * @param array $cache  ['status'=>,'age'=>,'ttl'=>,'backend'=>]
     * @param int   $ttl    fresh lifetime in seconds
     * @param array $route
     * @return array<string,string>
     */
    protected static function cacheHeaders(array $cache, $ttl, array $route)
    {
        $status = isset($cache['status']) ? (string) $cache['status'] : 'MISS';
        $age = isset($cache['age']) ? (int) $cache['age'] : 0;
        $ttl = max(1, (int) $ttl);

        if (in_array($status, array('HIT', 'MISS'), true)) {
            $shared = max(30, min($ttl, $ttl - $age - 30));
        } else {
            // Stale payloads: let the CDN revalidate quickly.
            $shared = 30;
        }

        $headers = array(
            'Cache-Control'   => 'public, max-age=' . $shared . ', s-maxage=' . $shared
                . ', stale-while-revalidate=' . max(600, $ttl * 2)
                . ', stale-if-error=604800',
            'X-Cache-Status'  => $status,
            'X-Cache-Age'     => (string) $age,
            'X-Cache-TTL'     => (string) $ttl,
            'X-Cache-Backend' => isset($cache['backend']) ? (string) $cache['backend'] : '',
        );

        return Legacy::apply($headers, $route);
    }

    /**
     * Stable ETag for an enveloped JSON response.
     *
     * The envelope carries per-request diagnostics (request_id, elapsed_ms,
     * generated), so an ETag computed from the serialised body would change on
     * every request and conditional requests would never hit. Hashing the data
     * plus the cache stamp keeps the validator stable for as long as the
     * representation actually is.
     *
     * @param array $parts
     * @return string
     */
    public static function etag(array $parts)
    {
        return '"' . substr(hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32) . '"';
    }

    /**
     * The work that must happen after the response has been flushed.
     *
     * @param array $refreshers
     * @return callable|null
     */
    protected static function deferred(array $refreshers)
    {
        $refreshers = array_values(array_filter($refreshers, 'is_callable'));
        if (empty($refreshers)) {
            return null;
        }

        return static function () use ($refreshers) {
            foreach ($refreshers as $refresh) {
                call_user_func($refresh);
            }
        };
    }
}
