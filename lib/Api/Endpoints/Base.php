<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Support\Legacy;

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
            'service'     => (string) Config::get('service.name', 'Satellite Data API'),
            'version'     => (string) Config::get('service.version', ''),
            'api_version' => 'v1',
            'generated'   => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id'  => $request->requestId(),
            'elapsed_ms'  => (int) round((microtime(true) - (defined('SATAPI_START') ? SATAPI_START : microtime(true))) * 1000),
        );

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
    protected static function etag(array $parts)
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
