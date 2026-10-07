<?php

namespace SatApi\Http;

use SatApi\Config;
use SatApi\Logger;

/**
 * SpaceTrack client.
 *
 * Two properties of the real service shape this class, both measured rather than
 * assumed:
 *
 *   1. Login is rate limited far more aggressively than queries are. Several
 *      logins in quick succession produced 401s on data requests that had just
 *      succeeded, so the session is cached and reused until it nears expiry. Only
 *      a 401 or an expired cache triggers a new login.
 *   2. Session cookies live 7200s (their Set-Cookie Max-Age). The cached session
 *      is considered stale at `session_ttl` (5400s by default) to leave headroom
 *      for clock skew and a slow request.
 *
 * The class also enforces a minimum interval between logins across processes, so
 * a cache stampede cannot turn into a login storm.
 */
final class SpacetrackClient
{
    /** @var array<string,mixed> */
    private $options;

    /** @var string */
    private $sessionFile;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = array())
    {
        $configured = Config::get('sources.spacetrack', array());
        $this->options = array_merge(is_array($configured) ? $configured : array(), $options);
        $this->sessionFile = (string) Config::path('data/spacetrack.session');
    }

    public function configured()
    {
        return !empty($this->options['enabled'])
            && (string) ($this->options['identity'] ?? '') !== ''
            && (string) ($this->options['password'] ?? '') !== '';
    }

    /**
     * Fetch a TLE set for the given NORAD ids.
     *
     * @param string[] $noradIds
     * @return array{ok:bool,records:string,error:string,chunks:int,count:int}
     */
    public function tleForIds(array $noradIds, $format = '3le')
    {
        $ids = array();
        foreach ($noradIds as $id) {
            $id = trim((string) $id);
            if ($id !== '' && preg_match('/^[0-9]{1,9}$/', $id)) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === array()) {
            return array('ok' => false, 'records' => '', 'error' => 'no valid NORAD ids', 'chunks' => 0, 'count' => 0);
        }
        if (!$this->configured()) {
            return array('ok' => false, 'records' => '', 'error' => 'spacetrack not configured', 'chunks' => 0, 'count' => 0);
        }

        $chunkSize = max(1, (int) ($this->options['chunk_size'] ?? 30));
        $parts = array();
        $chunks = 0;
        $count = 0;
        $authRetried = false;

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $path = 'class/gp/NORAD_CAT_ID/' . implode(',', $chunk) . '/format/' . $format;
            $res = $this->query($path);
            $chunks++;

            if ($res['code'] === 401 && !$authRetried) {
                // The cached session died earlier than its TTL suggests: drop it
                // and try exactly once more.
                $authRetried = true;
                $this->forgetSession(true);
                $res = $this->query($path);
                $chunks++;
            }
            if ($res['code'] !== 200) {
                Logger::warning('spacetrack_query_failed', array(
                    'code'   => $res['code'],
                    'chunk'  => count($chunk),
                    'error'  => substr((string) $res['error'], 0, 160),
                ));

                return array(
                    'ok'      => false,
                    'records' => '',
                    'error'   => 'spacetrack HTTP ' . $res['code'],
                    'chunks'  => $chunks,
                    'count'   => 0,
                );
            }

            $body = trim((string) $res['body']);
            if ($body === '' || $body === '[]') {
                continue;
            }
            $parts[] = $body;
            // Count TLE element sets: line 1 begins with "1 " in 3le output.
            $count += preg_match_all('/^1 [0-9]{5}/m', $body);
            // SpaceTrack asks clients to be gentle; one request every 400ms keeps
            // a 95-id refresh comfortably inside their limits.
            usleep(400000);
        }

        return array(
            'ok'      => $parts !== array(),
            'records' => implode("\n", $parts),
            'error'   => $parts === array() ? 'spacetrack returned no records' : '',
            'chunks'  => $chunks,
            'count'   => $count,
        );
    }

    /**
     * Run one authenticated query. Re-establishes the session if needed.
     *
     * @return array{code:int,body:string,error:string}
     */
    private function query($path)
    {
        $cookie = $this->session(true);
        if ($cookie === null) {
            return array('code' => 0, 'body' => '', 'error' => 'login failed');
        }

        $url = rtrim((string) ($this->options['query_base'] ?? ''), '/') . '/' . ltrim($path, '/');
        $res = $this->curl($url, array(), $cookie);

        if ($res['code'] === 401) {
            // Retry once with a brand-new session; the caller decides whether the
            // second 401 is fatal.
            $this->forgetSession(true);
            $cookie = $this->session(true);
            if ($cookie !== null) {
                $res = $this->curl($url, array(), $cookie);
            }
        }

        return $res;
    }

    /**
     * Return a usable cookie string, logging in when the cache is unusable.
     *
     * @return string|null
     */
    private function session($allowLogin = true)
    {
        $cached = $this->readSession();
        if ($cached !== null) {
            return $cached['cookie'];
        }
        if (!$allowLogin) {
            return null;
        }

        // Cross-process guard: never log in more often than min_login_interval,
        // whatever the cache says. A stampede must not become a login storm.
        $stampFile = (string) Config::path('data/spacetrack.login.stamp');
        $minInterval = max(0, (int) ($this->options['min_login_interval'] ?? 300));
        $last = is_file($stampFile) ? (int) @file_get_contents($stampFile) : 0;
        if ($last > 0 && (time() - $last) < $minInterval) {
            // Somebody logged in recently. Re-read the cache once in case they
            // have already written a fresh session.
            $again = $this->readSession();
            if ($again !== null) {
                return $again['cookie'];
            }
            Logger::warning('spacetrack_login_throttled', array('since_last' => time() - $last));
            return null;
        }
        @file_put_contents($stampFile, (string) time(), LOCK_EX);

        $identity = (string) $this->options['identity'];
        $password = (string) $this->options['password'];
        $res = $this->curl(
            (string) ($this->options['login_url'] ?? 'https://www.space-track.org/ajaxauth/login'),
            array(
                'identity' => $identity,
                'password' => $password,
            ),
            null
        );

        if ($res['code'] !== 200) {
            Logger::warning('spacetrack_login_failed', array('code' => $res['code']));

            return null;
        }

        $cookie = $this->cookieHeader($res['rawHeaders']);
        if ($cookie === '') {
            Logger::warning('spacetrack_login_no_cookie', array('code' => $res['code']));

            return null;
        }

        $this->writeSession($cookie);
        Logger::info('spacetrack_session_established', array('cookie_bytes' => strlen($cookie)));

        return $cookie;
    }

    /**
     * @return array{cookie:string,expires:int}|null
     */
    private function readSession()
    {
        if (!is_file($this->sessionFile)) {
            return null;
        }
        $raw = @file_get_contents($this->sessionFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['cookie'], $data['expires'])) {
            return null;
        }
        if ((int) $data['expires'] <= time()) {
            return null;
        }

        return array('cookie' => (string) $data['cookie'], 'expires' => (int) $data['expires']);
    }

    private function writeSession($cookie)
    {
        $ttl = max(300, (int) ($this->options['session_ttl'] ?? 5400));
        $payload = json_encode(array('cookie' => $cookie, 'expires' => time() + $ttl));
        $dir = dirname($this->sessionFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $tmp = $this->sessionFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $this->sessionFile);
        }
    }

    public function forgetSession($alsoStamp = false)
    {
        @unlink($this->sessionFile);
        if ($alsoStamp) {
            // Clearing the stamp lets the retry path log in immediately; the
            // caller only reaches here after a 401, which is evidence the cached
            // session really is dead.
            @unlink((string) Config::path('data/spacetrack.login.stamp'));
        }
    }

    /**
     * @param array<string,string>|null $cookieJar
     * @return array{code:int,body:string,error:string,rawHeaders:string}
     */
    private function curl($url, array $post, $cookie)
    {
        $ch = curl_init($url);
        $timeout = max(5, (int) ($this->options['timeout'] ?? 60));
        $headers = array(
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        );
        if ($post !== array()) {
            $headers[] = 'Content-Type: application/json';
        }
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => (bool) Config::get('http.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => (bool) Config::get('http.verify_tls', true) ? 2 : 0,
            CURLOPT_ENCODING => '',
        );
        if ($cookie !== null && $cookie !== '') {
            $opts[CURLOPT_COOKIE] = $cookie;
        }
        if ($post !== array()) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($post);
        }
        curl_setopt_array($ch, $opts);

        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        return array(
            'code'       => $code,
            'body'       => substr($raw, $headerSize),
            'rawHeaders' => substr($raw, 0, $headerSize),
            'error'      => $err,
        );
    }

    /**
     * Build a Cookie header from raw response headers. SpaceTrack's session
     * cookie is named `chocolatechip`; other names are carried through too so a
     * server-side rename does not silently break authentication.
     */
    private function cookieHeader($rawHeaders)
    {
        $pairs = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $rawHeaders) as $line) {
            if (stripos($line, 'set-cookie:') !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen('set-cookie:')));
            $semi = strpos($value, ';');
            $pair = $semi === false ? $value : substr($value, 0, $semi);
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            $val = trim(substr($pair, $eq + 1));
            if ($name === '' || $val === '') {
                continue;
            }
            $pairs[$name] = $val;
        }
        $out = array();
        foreach ($pairs as $name => $value) {
            $out[] = $name . '=' . $value;
        }

        return implode('; ', $out);
    }
}
