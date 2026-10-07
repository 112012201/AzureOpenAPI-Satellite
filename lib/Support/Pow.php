<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * Proof of work in front of the HTML entry points, using Cap.
 *
 * Two design decisions, both driven by what the service actually is:
 *
 *   1. Only the browser entry points are challenged. An API client cannot solve a
 *      proof of work, and challenging /v1/* would make every response uncacheable
 *      at the CDN because the challenge state would differ per caller.
 *
 *   2. Failure is open, not closed. A third-party challenge service going down must
 *      degrade the protection, never lock the site: an unreachable or slow Cap
 *      answers "unavailable", the request proceeds, and the state is reported in
 *      X-PoW-Status so the page can tell the visitor. Locking every visitor out
 *      because a container stopped is a worse outcome than a brief loss of
 *      bot protection, and this service's threat model is casual scraping, not
 *      targeted abuse.
 *
 * The session is a signed, stateless cookie rather than a server-side record: the
 * site already has no session store, and introducing one for this would add a
 * failure mode without changing what the cookie proves (that this browser solved a
 * challenge recently).
 */
final class Pow
{
    public const STATE_OK = 'ok';
    public const STATE_DISABLED = 'disabled';
    public const STATE_UNAVAILABLE = 'unavailable';
    public const STATE_FAILED = 'failed';
    public const STATE_MISSING = 'missing';

    const COOKIE_NAME = 'cap_session';

    /**
     * Should this path be challenged? Only the HTML entry points.
     */
    public static function isExemptPath($path)
    {
        $normalised = rtrim((string) $path, '/');
        if ($normalised === '') {
            $normalised = '/';
        }
        foreach ((array) Config::get('pow.exempt_paths', array()) as $exempt) {
            $exempt = rtrim((string) $exempt, '/');
            if ($exempt !== '' && $normalised === $exempt) {
                return true;
            }
        }

        // Everything that is not a document is exempt: assets, the manifest, the
        // API surface and the legal pages must stay reachable for the challenge
        // itself to be solvable and for the notice to be readable.
        if (strpos($normalised, '/v1/') === 0) {
            return true;
        }
        if (strpos($normalised, '/assets/') === 0) {
            return true;
        }
        if (strpos($normalised, '/cap/') === 0) {
            // The challenge's own endpoints. Challenging them would make the gate
            // unsolvable, which is the one failure mode a gate must not have.
            return true;
        }
        // The legal pages must be readable BEFORE they can be accepted, and they have
        // been extensionless since v3.1 (`/legal`, `/privacy`, `/terms`). The
        // configured exempt list names `/legal/privacy.html`, and an exact comparison
        // therefore missed exactly the pages the gate exists to make readable: a gate
        // that hides the document you are asked to agree to is a wall, not a gate.
        if (in_array($normalised, array('/legal', '/privacy', '/terms'), true)) {
            return true;
        }
        if (preg_match('#\.[a-z0-9]{2,5}$#i', $normalised)) {
            // A file with an extension (css/js/png/ico/yaml/txt/webmanifest…).
            return true;
        }

        return false;
    }

    public static function enabled()
    {
        return (bool) Config::get('pow.enabled', false) && self::configured();
    }

    public static function configured()
    {
        return (string) Config::get('pow.site_key', '') !== ''
            || (string) Config::get('pow.secret_key', '') !== '';
    }

    /**
     * Report the state for a request without blocking it.
     *
     * @return array<string,mixed>
     */
    public static function status(Request $request)
    {
        $enabled = self::enabled();
        $state = self::STATE_DISABLED;
        if ($enabled) {
            $state = self::hasValidSession($request) ? self::STATE_OK : self::STATE_MISSING;
        }

        return array(
            'enabled'      => $enabled,
            'provider'     => (string) Config::get('pow.provider', 'cap'),
            'endpoint'     => (string) Config::get('pow.endpoint', '/cap'),
            'site_key'     => $enabled ? (string) Config::get('pow.site_key', '') : '',
            'state'        => $state,
            'fail_open'    => (bool) Config::get('pow.fail_open', true),
            'session_ttl'  => (int) Config::get('pow.session_ttl', 43200),
        );
    }

    /**
     * Verify a Cap token and, on success, mint a session.
     *
     * @return array{ok:bool,state:string,error:string,session:string}
     */
    public static function verify($token, Request $request)
    {
        $token = trim((string) $token);
        if (!self::enabled()) {
            return array('ok' => true, 'state' => self::STATE_DISABLED, 'error' => '', 'session' => self::mintSession());
        }
        if ($token === '') {
            return array('ok' => false, 'state' => self::STATE_MISSING, 'error' => 'No proof-of-work token was supplied.', 'session' => '');
        }
        // Cap tokens are base64url-ish and short; anything long is not a token.
        if (strlen($token) > 4096) {
            return array('ok' => false, 'state' => self::STATE_FAILED, 'error' => 'The proof-of-work token is too large.', 'session' => '');
        }

        $secret = (string) Config::get('pow.secret_key', '');
        if ($secret === '') {
            return self::failOpen('pow secret key is not configured');
        }

        $url = rtrim((string) Config::get('pow.endpoint', '/cap'), '/') . '/siteverify';
        $absolute = Config::get('service.homepage', '') . $url;

        $started = microtime(true);
        $res = self::httpPost($absolute, array(
            'secret'   => $secret,
            'response' => $token,
        ), max(500, (int) Config::get('pow.timeout_ms', 5000)));
        $ms = (int) round((microtime(true) - $started) * 1000);

        Logger::info('pow_verify', array(
            'state' => $res['ok'] ? 'ok' : 'failed',
            'code'  => $res['code'],
            'ms'    => $ms,
        ));

        if (!$res['ok']) {
            return self::failOpen('the challenge service could not be reached (' . $res['error'] . ')');
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            return array(
                'ok'      => false,
                'state'   => self::STATE_FAILED,
                'error'   => 'The proof-of-work token was rejected.',
                'session' => '',
            );
        }

        return array('ok' => true, 'state' => self::STATE_OK, 'error' => '', 'session' => self::mintSession());
    }

    /**
     * When the challenge service is unusable, pass or fail depending on policy.
     *
     * @return array{ok:bool,state:string,error:string,session:string}
     */
    private static function failOpen($reason)
    {
        if ((bool) Config::get('pow.fail_open', true)) {
            Logger::warning('pow_fail_open', array('reason' => $reason));

            return array('ok' => true, 'state' => self::STATE_UNAVAILABLE, 'error' => '', 'session' => self::mintSession());
        }

        return array('ok' => false, 'state' => self::STATE_UNAVAILABLE, 'error' => 'The challenge service is unavailable.', 'session' => '');
    }

    /**
     * Mint a signed session value.
     *
     * The payload carries an issue time and a random nonce; the signature makes it
     * unforgeable without the key, and the timestamp makes it expire. Nothing else
     * is stored, which is what keeps this stateless.
     */
    public static function mintSession($issuedAt = null)
    {
        $issuedAt = $issuedAt === null ? time() : (int) $issuedAt;
        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $nonce = dechex(mt_rand());
        }
        $payload = $issuedAt . '.' . $nonce;
        $sig = self::sign($payload);

        return $payload . '.' . $sig;
    }

    /**
     * Is the request carrying a session this server minted and that has not expired?
     */
    public static function hasValidSession(Request $request)
    {
        $cookie = self::readSessionCookie($request);
        if ($cookie === '') {
            return false;
        }
        $parts = explode('.', $cookie);
        if (count($parts) !== 3) {
            return false;
        }
        list($issuedAt, $nonce, $sig) = $parts;
        if (!preg_match('/^[0-9]{1,12}$/', $issuedAt) || $nonce === '') {
            return false;
        }
        $expected = self::sign($issuedAt . '.' . $nonce);
        if (!hash_equals($expected, (string) $sig)) {
            return false;
        }
        $ttl = max(60, (int) Config::get('pow.session_ttl', 43200));

        return (time() - (int) $issuedAt) < $ttl;
    }

    private static function sign($payload)
    {
        $key = (string) Config::get('pow.secret_key', '');
        if ($key === '') {
            // Without a key nothing can be verified, so sign with a per-process
            // value: sessions minted in fail-open mode simply will not survive a
            // restart, which is the honest outcome when no key is configured.
            $key = 'unconfigured:' . (string) Config::get('service.version', '');
        }

        return substr(hash_hmac('sha256', $payload, $key), 0, 32);
    }

    public static function sessionCookie($value)
    {
        if ((string) $value === '') {
            return '';
        }
        $ttl = max(60, (int) Config::get('pow.session_ttl', 43200));

        return self::COOKIE_NAME . '=' . rawurlencode((string) $value)
            . '; Path=/; Max-Age=' . $ttl . '; SameSite=Lax; HttpOnly; Secure';
    }

    public static function readSessionCookie(Request $request)
    {
        $cookieHeader = (string) $request->header('cookie', '');
        if ($cookieHeader === '') {
            return '';
        }
        foreach (explode(';', $cookieHeader) as $piece) {
            $piece = trim($piece);
            $eq = strpos($piece, '=');
            if ($eq === false) {
                continue;
            }
            if (substr($piece, 0, $eq) === self::COOKIE_NAME) {
                return rawurldecode(substr($piece, $eq + 1));
            }
        }

        return '';
    }

    /**
     * Report the cookie categories this client has agreed to.
     *
     * `cookie_consent` is an essential cookie (it stores the visitor's own choice),
     * which is why it is written even when everything optional was rejected. That is
     * stated in the dialog and in the privacy policy rather than assumed.
     *
     * @return array<string,mixed>
     */
    public static function cookieStatus(Request $request)
    {
        $value = '';
        $cookieHeader = (string) $request->header('cookie', '');
        foreach (explode(';', $cookieHeader) as $piece) {
            $piece = trim($piece);
            $eq = strpos($piece, '=');
            if ($eq === false) {
                continue;
            }
            if (substr($piece, 0, $eq) === 'cookie_consent') {
                $value = rawurldecode(substr($piece, $eq + 1));
            }
        }

        $choice = '';
        $version = 0;
        // Formats: "accept_all", "essential_only", "reject_all", optionally "…;v=1"
        if ($value !== '') {
            $bits = explode(';', $value);
            $choice = trim($bits[0]);
            foreach ($bits as $bit) {
                $bit = trim($bit);
                if (strpos($bit, 'v=') === 0) {
                    $version = (int) substr($bit, 2);
                }
            }
        }
        $allowed = array('accept_all', 'essential_only', 'reject_all');
        if (!in_array($choice, $allowed, true)) {
            $choice = '';
        }

        $categories = (array) Config::get('cookies', array());

        return array(
            'choice'   => $choice,
            'decided'  => $choice !== '',
            'version'  => $version,
            'current_version' => (int) Config::get('cookies.version', 1),
            'categories' => array(
                'essential'   => array_keys((array) ($categories['essential'] ?? array())),
                'preferences' => array_keys((array) ($categories['preferences'] ?? array())),
                'analytics'   => array_keys((array) ($categories['analytics'] ?? array())),
            ),
            'lifetime_days' => (int) Config::get('cookies.lifetime_days', 180),
        );
    }

    /**
     * Minimal POST helper for the siteverify call.
     *
     * Deliberately not reusing Http\Client: that client is tuned for caching TLE
     * feeds (long timeouts, large bodies, retries) and this call has the opposite
     * requirements (hard short timeout, tiny body, no retry -- a retried challenge
     * costs the visitor latency for no benefit).
     *
     * @return array{ok:bool,code:int,body:string,error:string}
     */
    private static function httpPost($url, array $fields, $timeoutMs)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'code' => 0, 'body' => '', 'error' => 'curl extension missing');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: AzureOpenAPI-for-Satellite/' . (string) Config::get('service.version', ''),
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) Config::get('http.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => (bool) Config::get('http.verify_tls', true) ? 2 : 0,
        ));
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        if ($code === 0) {
            return array('ok' => false, 'code' => 0, 'body' => '', 'error' => $err === '' ? 'connection failed' : $err);
        }

        return array('ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body, 'error' => $err);
    }
}
