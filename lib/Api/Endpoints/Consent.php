<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Consent as ConsentSupport;
use SatApi\Support\Pow;
use SatApi\Support\Problem;

/**
 * Consent plumbing for browser clients.
 *
 *   GET  /v1/consent/status      what this client still has to acknowledge
 *   POST /v1/consent/verify-pow  exchange a proof-of-work token for a session
 *
 * Both are exempt from the consent gate: a client cannot be asked to acknowledge a
 * document it is forbidden to read, and the proof-of-work challenge has to be
 * solvable before anything is acknowledged.
 */
final class Consent extends Base
{
    public static function status(Request $request, array $params, array $route)
    {
        $verdict = ConsentSupport::current();
        if (!is_array($verdict)) {
            // Called outside the Router (CLI, direct handler tests): evaluate now
            // rather than reporting a misleading "accepted".
            $verdict = ConsentSupport::describe($request);
        }

        $pow = Pow::status($request);
        $cookies = Pow::cookieStatus($request);

        Response::json(
            array(
                'consent' => ConsentSupport::payload($verdict),
                'pow'     => $pow,
                'cookies' => $cookies,
            ),
            200,
            array('Cache-Control' => 'no-store')
        );
    }

    public static function verifyPow(Request $request, array $params, array $route)
    {
        $token = (string) $request->param('token', '');
        if ($token === '') {
            $body = $request->jsonBody();
            if (isset($body['token'])) {
                $token = (string) $body['token'];
            }
        }

        $result = Pow::verify($token, $request);

        if (!$result['ok']) {
            Problem::send(
                403,
                'Forbidden',
                $result['error'] === '' ? 'The proof-of-work token could not be verified.' : $result['error'],
                'pow_failed',
                array('pow' => array('provider' => (string) Config::get('pow.provider', 'cap'), 'state' => $result['state'])),
                array('Cache-Control' => 'no-store')
            );

            return;
        }

        $headers = array('Cache-Control' => 'no-store');
        $cookie = Pow::sessionCookie($result['session']);
        if ($cookie !== '') {
            $headers['Set-Cookie'] = $cookie;
        }

        Response::json(
            array(
                'ok'      => true,
                'pow'     => array('state' => $result['state'], 'provider' => (string) Config::get('pow.provider', 'cap')),
                'session' => array('expires_in' => (int) Config::get('pow.session_ttl', 43200)),
                'consent' => ConsentSupport::payload(ConsentSupport::describe($request)),
            ),
            200,
            $headers
        );
    }
}
