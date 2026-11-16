<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;

/**
 * Which public hostname answered this request, and what is in front of it.
 *
 * WHY THIS EXISTS
 *
 * The same application is served under more than one hostname, each behind a
 * different CDN. Two consequences that are easy to get wrong:
 *
 *   1. A client cannot tell "cache HIT" from "cache HIT" when one number is the
 *      edge's and the other is the origin's. Both vendors write their own header
 *      and one of them reuses a name this application already emits:
 *
 *        EdgeOne  EO-Cache-Status    separate namespace, no collision
 *        SCDN     X-Cache-Status     COLLIDES with the origin's own header
 *
 *      So the origin verdict must also travel under a name no CDN touches
 *      (X-Origin-Cache-Status, emitted in Api\Endpoints\Base::cacheHeaders), and a
 *      client must be TOLD which header to read for the edge. That is what
 *      edgeHeader() is for: the answer depends on the hostname, so it cannot be
 *      hard-coded in a script.
 *
 *   2. One of those hostnames is newer than the other and is still being
 *      characterised. An operator needs to see which address a response came from
 *      without reading the Host header by hand, and a client deserves to know it is
 *      talking to the address that is under test.
 *
 * Everything here is derived from configuration (Config::endpoints) rather than
 * from a hard-coded list, so adding a third hostname is a config edit.
 */
final class Endpoint
{
    /**
     * The registry entry for the request's Host, with `id` folded in.
     *
     * Always returns an array: an unrecognised host resolves to the configured
     * default entry (see Config::endpointIdForHost), because "unknown hostname"
     * is not a failure the caller can act on -- the request is being served
     * either way, and the honest answer is the address it most likely arrived at.
     *
     * @return array<string,mixed>
     */
    public static function current(Request $request)
    {
        $id = Config::endpointIdForHost($request->header('host', ''));
        $entry = Config::endpoint($id);
        if ($entry === null) {
            $entry = Config::endpoint(Config::defaultEndpointId());
        }
        if ($entry === null) {
            // No registry configured at all: describe the request itself rather
            // than inventing a hostname.
            $host = strtolower((string) $request->header('host', ''));

            return array(
                'id'         => 'direct',
                'host'       => $host,
                'url'        => $host === '' ? '' : 'https://' . $host,
                'cdn'        => 'unknown',
                'cdn_header' => '',
                'status'     => 'unknown',
                'testing'    => false,
            );
        }

        $entry['id'] = $id;
        $entry += array(
            'host'       => '',
            'url'        => '',
            'cdn'        => 'unknown',
            'cdn_header' => '',
            'status'     => 'stable',
            'testing'    => false,
            // The page-level notice is opt-in: an entry that states no notice key
            // renders no strip, so adding a fourth hostname cannot accidentally
            // show a half-built banner with an empty badge.
            'badge'        => '',
            'notice'       => '',
            'notice_level' => 'info',
            // The announcement board defaults to ON: a new address should inherit
            // the operator's notices rather than silently opt out of them. Only an
            // entry that says `false` hides it (the API address, today).
            'announcements' => true,
        );

        return $entry;
    }

    /**
     * The response header a client must read for the EDGE cache verdict.
     *
     * Empty string when the configured CDN is not known to write one, which is the
     * instruction "there is no edge verdict to read" rather than a guess.
     */
    public static function edgeHeader(Request $request)
    {
        $entry = self::current($request);
        $header = strtolower(trim((string) $entry['cdn_header']));

        return $header;
    }

    /**
     * How the request reached this process, from the request side only.
     *
     * There is no reliable request header that names the CDN: neither EdgeOne nor
     * SCDN signs its hop, and `X-Forwarded-For` is set by any reverse proxy (this
     * host has nginx in front of PHP even with no CDN). So the vendor is decided by
     * the hostname, which IS authoritative because it is what routes the traffic,
     * and the generic answer for a hostname outside the registry is 'proxy'.
     *
     * @return string 'edgeone' | 'scdn' | 'proxy' | 'direct'
     */
    public static function via(Request $request)
    {
        $entry = self::current($request);
        $cdn = strtolower((string) $entry['cdn']);
        if ($cdn === 'edgeone' || $cdn === 'scdn') {
            return $cdn;
        }

        $forwarded = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwarded !== '' || trim((string) $request->header('via', '')) !== '') {
            return 'proxy';
        }

        return 'direct';
    }

    /**
     * True when this hostname is the one flagged as new / under test.
     */
    public static function isTesting(Request $request)
    {
        $entry = self::current($request);

        return !empty($entry['testing']);
    }

    /**
     * The `endpoint` object attached to every JSON envelope.
     *
     * Kept deliberately small: an id, the hostname, the CDN and the header to read.
     * `testing` is the flag a client (or a human reading the payload) uses to know
     * which address it just talked to.
     *
     * @return array<string,mixed>
     */
    public static function describe(Request $request)
    {
        $entry = self::current($request);

        $out = array(
            'id'         => (string) $entry['id'],
            'host'       => (string) $entry['host'],
            'url'        => (string) $entry['url'],
            'cdn'        => (string) $entry['cdn'],
            // Response header carrying the CDN's own cache verdict for THIS
            // response. An empty string means "this edge exposes none".
            'cdn_header' => (string) $entry['cdn_header'],
            'status'     => (string) $entry['status'],
            'testing'    => (bool) $entry['testing'],
        );

        if (!empty($entry['since'])) {
            $out['since'] = (string) $entry['since'];
        }

        return $out;
    }

    /**
     * Every configured address, for /v1/meta and the landing page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function registry()
    {
        $out = array();
        foreach (Config::endpoints() as $id => $entry) {
            $out[] = array(
                'id'         => (string) $id,
                'host'       => isset($entry['host']) ? (string) $entry['host'] : '',
                'url'        => isset($entry['url']) ? (string) $entry['url'] : '',
                'cdn'        => isset($entry['cdn']) ? (string) $entry['cdn'] : '',
                'cdn_header' => isset($entry['cdn_header']) ? (string) $entry['cdn_header'] : '',
                'status'     => isset($entry['status']) ? (string) $entry['status'] : '',
                'testing'    => !empty($entry['testing']),
                'default'    => (string) $id === Config::defaultEndpointId(),
            );
        }

        return $out;
    }
}
