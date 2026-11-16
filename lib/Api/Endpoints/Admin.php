<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Data\Repository;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Logger;
use SatApi\Support\Problem;

/**
 * Operator endpoints. Never part of the public contract.
 *
 * AUTHENTICATION
 *
 * One shared secret, `admin.token` (SATAPI_ADMIN_TOKEN). It is compared with
 * hash_equals so a wrong token cannot be discovered byte by byte from response
 * timing -- cheap to do and it removes an entire class of finding.
 *
 * The token is read on every request rather than cached, because rotating it is
 * the only revocation mechanism a single shared secret has.
 *
 * NO TOKEN CONFIGURED MEANS THE ENDPOINT DOES NOT EXIST (404, not 401).
 * Answering 401 for "no token configured" would advertise that an admin surface
 * exists on a deployment that deliberately left it off, which is a hint nobody
 * needs. The distinguishing case is worth stating because it is easy to get
 * backwards: absent credential -> 404 (this route is not for you), wrong
 * credential -> 401 (you are at the right door).
 */
final class Admin extends Base
{
    /**
     * POST /v1/admin/reload -- re-pull every source and overwrite the cache.
     */
    public static function reload(Request $request, array $params, array $route)
    {
        $token = trim((string) Config::get('admin.token', ''));
        if ($token === '') {
            Problem::send(
                404,
                'Not Found',
                'No route matches ' . $request->path() . '.',
                'not_found'
            );
        }

        if (!(bool) Config::get('reload.enabled', true)) {
            Problem::send(
                503,
                'Service Unavailable',
                'Hot reload is disabled by configuration (reload.enabled=false).',
                'reload_disabled'
            );
        }

        $provided = self::providedToken($request);
        if ($provided === '' || !hash_equals($token, $provided)) {
            Logger::warning('admin_reload_unauthorised', array(
                'ip' => $request->ip(),
                // Never log the token, not even a prefix: a partial secret is still a
                // secret and the log is shipped off the host.
                'reason' => $provided === '' ? 'missing' : 'mismatch',
            ));

            Problem::send(
                401,
                'Unauthorized',
                'A valid admin token is required. Send X-Admin-Token, or Authorization: Bearer <token>.',
                'unauthorized'
            );
        }

        // Optional narrowing: ?targets=weather,amsat or ?targets=all (default).
        $requested = array();
        $raw = trim((string) $request->param('targets', ''));
        if ($raw !== '' && strtolower($raw) !== 'all') {
            foreach (explode(',', $raw) as $piece) {
                $piece = strtolower(trim($piece));
                if ($piece !== '') {
                    $requested[] = $piece;
                }
            }
        }

        $unknown = array_values(array_diff($requested, Repository::DATASETS));
        if ($unknown !== array()) {
            Problem::send(
                400,
                'Bad Request',
                'Unknown target(s): ' . implode(', ', $unknown) . '.',
                'bad_request',
                array('parameter' => 'targets', 'allowed' => Repository::DATASETS)
            );
        }

        $started = microtime(true);
        $report = Repository::reloadAll($requested);

        if (!empty($report['locked'])) {
            // 409 rather than 503: the request is valid, the service is healthy, and
            // the correct client behaviour is to try again shortly -- not to fail over.
            Problem::send(
                409,
                'Conflict',
                (string) $report['error'] . '. Retry when the running reload has finished.',
                'reload_in_progress',
                array('retry_after' => 30),
                array('Retry-After' => '30', 'Cache-Control' => 'no-store')
            );
        }

        $report['requested_by'] = array('ip' => $request->ip(), 'request_id' => $request->requestId());

        Logger::info('admin_reload', array(
            'ok'       => !empty($report['ok']),
            'failed'   => isset($report['failed']) ? (int) $report['failed'] : 0,
            'elapsed'  => (int) round((microtime(true) - $started) * 1000),
            'targets'  => $requested === array() ? 'all' : implode(',', $requested),
        ));

        // no-store on both sides: a reload is a state change, and a cached copy of
        // its *report* would be actively misleading.
        Response::json(
            self::envelope(array('reload' => $report), self::envelopeMeta($request)),
            !empty($report['ok']) ? 200 : 503,
            array('Cache-Control' => 'no-store, private')
        );
    }

    /**
     * The token from whichever accepted place carries it.
     *
     * The header forms are the documented ones and always work. A query parameter
     * is read only when `admin.allow_query_token` is switched on, because a query
     * string lands in the access log, in shell history, and (if the response ever
     * links out) in a Referer -- and a logged admin token is a leaked credential.
     * With the flag off, `?token=` is answered exactly like a wrong token: 401,
     * with no hint about which place was checked.
     */
    private static function providedToken(Request $request)
    {
        $header = trim((string) $request->header('x-admin-token', ''));
        if ($header !== '') {
            return $header;
        }

        $auth = trim((string) $request->header('authorization', ''));
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        if (!(bool) Config::get('admin.allow_query_token', false)) {
            return '';
        }

        return trim((string) $request->param('token', ''));
    }
}
