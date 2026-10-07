<?php

namespace SatApi\Support;

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Http\Request;

/**
 * Fixed-window rate limiter (Redis INCR when available, file fallback).
 *
 * Disabled by default: a public read-only API in front of a CDN usually
 * prefers the CDN's own throttling. Enable with SATAPI_RATELIMIT_ENABLED=1
 * to protect the origin from cache-miss stampedes.
 */
final class RateLimit
{
    /**
     * @return array{allowed:bool,limit:int,remaining:int,reset:int,policy:string}
     */
    public static function check(Request $request, $bucket = 'default')
    {
        $limit = max(1, (int) Config::get('ratelimit.per_minute', 120));
        $window = 60;
        $now = time();
        $reset = ($now - ($now % $window)) + $window;

        $policy = array(
            'allowed'   => true,
            'limit'     => $limit,
            'remaining' => $limit,
            'reset'     => $reset,
            'policy'    => $limit . ';w=' . $window,
        );

        if (!(bool) Config::get('ratelimit.enabled', false)) {
            return $policy;
        }

        $identifier = $request->ip();
        $key = 'ratelimit:' . $bucket . ':' . $identifier . ':' . (int) floor($now / $window);
        $count = self::increment($key, $window * 2);

        $policy['remaining'] = max(0, $limit - $count);
        $policy['allowed'] = $count <= $limit;

        return $policy;
    }

    private static function increment($key, $ttl)
    {
        $redis = Store::redisClient();
        if ($redis !== false) {
            try {
                $count = (int) $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, (int) $ttl);
                }

                return $count;
            } catch (\Throwable $e) {
                // fall through to the file limiter
            }
        }

        return self::incrementFile($key);
    }

    private static function incrementFile($key)
    {
        $dir = (string) Config::get('ratelimit.dir', Config::path('data/ratelimit'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return 1; // fail open
        }

        $count = 1;
        if (@flock($handle, LOCK_EX)) {
            $contents = stream_get_contents($handle);
            $data = json_decode((string) $contents, true);
            if (is_array($data) && isset($data['window']) && (int) $data['window'] === (int) floor(time() / 60)) {
                $count = (int) $data['count'] + 1;
            }
            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, json_encode(array('window' => (int) floor(time() / 60), 'count' => $count)));
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);

        return $count;
    }

    /**
     * @return array<string,string>
     */
    public static function headers(array $policy)
    {
        $headers = array(
            'X-RateLimit-Limit'     => (string) $policy['limit'],
            'X-RateLimit-Remaining' => (string) $policy['remaining'],
            'X-RateLimit-Reset'     => (string) $policy['reset'],
        );
        if (!$policy['allowed']) {
            $headers['Retry-After'] = (string) max(1, $policy['reset'] - time());
        }

        return $headers;
    }
}
