<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Media;
use SatApi\Support\Pow;

/**
 * GET /media-key — the key that decrypts the film and the music.
 *
 * Deliberately NOT a static file and NOT a router endpoint: it answers one small JSON
 * object, it always depends on a cookie, and putting it next to the documents keeps it
 * out of the API surface (it is not part of the documented /v1 contract and must never
 * be cached by anything).
 *
 * The gate is the same signed session cookie the challenge mints (Pow::hasValidSession).
 * A crawler that never solved the challenge gets 403 and the ciphertext it may have
 * downloaded stays noise.
 *
 * The key reaches the browser because the browser has to decrypt; see the long note in
 * Support\Media for what that does and does not protect.
 */
final class MediaKey
{
    public static function handles(Request $request)
    {
        return rtrim((string) $request->path(), '/') === '/media-key';
    }

    public static function render(Request $request)
    {
        $headers = array(
            'Cache-Control' => 'no-store, max-age=0',
            // The answer is per-visitor: a cache that keys on nothing must not keep it.
            'Vary'          => 'Cookie',
        );

        if (!Pow::hasValidSession($request)) {
            Response::raw(
                (string) json_encode(array('error' => 'challenge_required')),
                'application/json; charset=utf-8',
                403,
                $headers
            );

            return;
        }

        $key = Media::keyBytes();
        if ($key === '') {
            Response::raw(
                (string) json_encode(array('error' => 'no_media_key')),
                'application/json; charset=utf-8',
                404,
                $headers
            );

            return;
        }

        Response::raw(
            (string) json_encode(array('v' => 1, 'key' => base64_encode($key)), JSON_UNESCAPED_SLASHES),
            'application/json; charset=utf-8',
            200,
            $headers
        );
    }
}
