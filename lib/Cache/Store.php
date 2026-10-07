<?php

namespace SatApi\Cache;

use SatApi\Config;
use SatApi\Logger;

/**
 * Two-tier cache (Redis + local file) with:
 *
 *   - stale-while-revalidate: a stale copy is served immediately and the
 *     refresh is executed after the response has been flushed,
 *   - single-flight: only one PHP worker refreshes a key at a time,
 *   - atomic file writes (tmp + rename) so readers never see half a file,
 *   - graceful degradation: Redis down => file cache, no fatal errors.
 *
 * Stored layout (both backends):
 *   "SATAPI1|" + json(meta) + "\n" + payload
 */
final class Store
{
    const ENVELOPE = 'SATAPI1|';

    /** @var \Redis|false|null */
    private static $redis = null;

    /** @var array<string,array> */
    private static $memo = array();

    /** @var array<string,int> */
    private static $stats = array('hit' => 0, 'miss' => 0, 'stale' => 0, 'error' => 0);

    /**
     * @param string   $key
     * @param int      $ttl      fresh lifetime in seconds
     * @param callable $producer fn(): mixed
     * @param array    $opts     codec, stale_ttl, lock_timeout_ms, force
     * @return array{value:mixed,status:string,age:int,ttl:int,stored_at:int,key:string,backend:string,refresh:callable|null,error:string}
     */
    public static function remember($key, $ttl, callable $producer, array $opts = array())
    {
        $opts += array(
            'codec'           => 'raw',
            'stale_ttl'       => 2592000, // keep stale copies for 30 days
            'lock_timeout_ms' => 4000,
            'force'           => false,
        );

        $ttl = max(1, (int) $ttl);

        if (!$opts['force']) {
            $entry = self::read($key, $opts['codec']);
            if ($entry !== null) {
                $age = max(0, time() - $entry['stored_at']);
                if ($age < $ttl) {
                    self::$stats['hit']++;
                    $entry['age'] = $age;
                    $entry['ttl'] = $ttl;
                    $entry['status'] = 'HIT';
                    $entry['refresh'] = null;

                    return $entry;
                }

                // Stale: serve it now, refresh once the client is gone.
                self::$stats['stale']++;
                $entry['age'] = $age;
                $entry['ttl'] = $ttl;
                $entry['status'] = 'STALE';
                $entry['refresh'] = self::refreshCallback($key, $ttl, $producer, $opts);

                return $entry;
            }
        }

        self::$stats['miss']++;
        $lock = new Lock($key);
        $locked = $lock->acquire((int) $opts['lock_timeout_ms']);

        if (!$locked) {
            // Somebody else is refreshing: give it a moment, then fall through.
            $entry = self::read($key, $opts['codec']);
            if ($entry !== null) {
                $entry['age'] = max(0, time() - $entry['stored_at']);
                $entry['ttl'] = $ttl;
                $entry['status'] = 'HIT';
                $entry['refresh'] = null;

                return $entry;
            }
        }

        try {
            $value = call_user_func($producer);
        } catch (\Throwable $e) {
            if ($locked) {
                $lock->release();
            }
            // Last resort: serve an expired copy rather than failing.
            $entry = self::read($key, $opts['codec'], true);
            if ($entry !== null) {
                $entry['age'] = max(0, time() - $entry['stored_at']);
                $entry['ttl'] = $ttl;
                $entry['status'] = 'STALE';
                $entry['refresh'] = null;
                $entry['error'] = $e->getMessage();
                Logger::warning('cache_producer_failed_serving_stale', array('key' => $key, 'error' => $e->getMessage()));

                return $entry;
            }
            throw $e;
        }

        self::write($key, $value, $ttl + (int) $opts['stale_ttl'], $opts['codec'], $ttl);
        if ($locked) {
            $lock->release();
        }

        return array(
            'value'     => $value,
            'status'    => 'MISS',
            'age'       => 0,
            'ttl'       => $ttl,
            'stored_at' => time(),
            'key'       => $key,
            'backend'   => self::backendName(),
            'refresh'   => null,
            'error'     => '',
        );
    }

    private static function refreshCallback($key, $ttl, callable $producer, array $opts)
    {
        return static function () use ($key, $ttl, $producer, $opts) {
            $lock = new Lock($key);
            if (!$lock->acquire(0)) {
                return; // another worker is already on it
            }
            try {
                $value = call_user_func($producer);
                self::write($key, $value, $ttl + (int) $opts['stale_ttl'], $opts['codec'], $ttl);
                Logger::debug('cache_revalidated', array('key' => $key));
            } catch (\Throwable $e) {
                self::$stats['error']++;
                Logger::warning('cache_revalidate_failed', array('key' => $key, 'error' => $e->getMessage()));
            }
            $lock->release();
        };
    }

    /**
     * @return array|null ['value'=>mixed,'stored_at'=>int,'ttl'=>int,'backend'=>string,'key'=>string,'age'=>int]
     */
    public static function read($key, $codec = 'raw', $allowAnyAge = true)
    {
        $raw = self::readRaw($key);
        if ($raw === null) {
            return null;
        }

        $meta = $raw['meta'];
        $payload = $raw['payload'];
        $value = self::decode($payload, $codec);
        if ($value === null) {
            return null;
        }

        return array(
            'value'     => $value,
            'stored_at' => isset($meta['stored_at']) ? (int) $meta['stored_at'] : 0,
            'ttl'       => isset($meta['ttl']) ? (int) $meta['ttl'] : 0,
            'backend'   => $raw['backend'],
            'key'       => $key,
            'age'       => max(0, time() - (int) (isset($meta['stored_at']) ? $meta['stored_at'] : time())),
            'status'    => 'HIT',
            'refresh'   => null,
            'error'     => '',
        );
    }

    public static function write($key, $value, $expireSeconds, $codec = 'raw', $ttl = null, $storedAt = null)
    {
        $payload = self::encode($value, $codec);
        if ($payload === null) {
            return false;
        }
        $meta = array(
            'stored_at' => $storedAt === null ? time() : (int) $storedAt,
            'ttl'       => $ttl === null ? (int) $expireSeconds : (int) $ttl,
            'codec'     => $codec,
            'bytes'     => strlen($payload),
        );
        $envelope = self::ENVELOPE . json_encode($meta) . "\n" . $payload;

        $ok = false;
        $redis = self::redis();
        if ($redis !== false) {
            try {
                $ok = (bool) $redis->setex(self::redisKey($key), max(1, (int) $expireSeconds), $envelope);
            } catch (\Throwable $e) {
                self::$stats['error']++;
                Logger::warning('redis_write_failed', array('key' => $key, 'error' => $e->getMessage()));
                $ok = false;
            }
        }

        $fileOk = self::writeFile($key, $envelope);
        if (!$ok && !$fileOk) {
            Logger::error('cache_write_failed', array('key' => $key));
        }

        unset(self::$memo[$key]);

        return $ok || $fileOk;
    }

    public static function delete($key)
    {
        $redis = self::redis();
        if ($redis !== false) {
            try {
                $redis->del(self::redisKey($key));
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $file = self::filePath($key);
        if (is_file($file)) {
            @unlink($file);
        }
        unset(self::$memo[$key]);

        return true;
    }

    /**
     * Raw envelope read (Redis first, then file), memoised per request.
     *
     * @return array{meta:array,payload:string,backend:string}|null
     */
    private static function readRaw($key)
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $envelope = null;
        $backend = 'file';

        $redis = self::redis();
        if ($redis !== false) {
            try {
                $value = $redis->get(self::redisKey($key));
                if (is_string($value) && $value !== '') {
                    $envelope = $value;
                    $backend = 'redis';
                }
            } catch (\Throwable $e) {
                self::$stats['error']++;
                Logger::warning('redis_read_failed', array('key' => $key, 'error' => $e->getMessage()));
            }
        }

        if ($envelope === null) {
            $file = self::filePath($key);
            if (is_file($file)) {
                $contents = @file_get_contents($file);
                if (is_string($contents) && $contents !== '') {
                    $envelope = $contents;
                    $backend = 'file';
                }
            }
        }

        if ($envelope === null) {
            self::$memo[$key] = null;

            return null;
        }

        $parsed = self::parseEnvelope($envelope);
        if ($parsed === null) {
            self::$memo[$key] = null;

            return null;
        }
        $parsed['backend'] = $backend;
        self::$memo[$key] = $parsed;

        return $parsed;
    }

    private static function parseEnvelope($envelope)
    {
        if (strncmp($envelope, self::ENVELOPE, strlen(self::ENVELOPE)) !== 0) {
            // Legacy / foreign payload: treat the whole string as the value.
            return array('meta' => array('stored_at' => time(), 'ttl' => 0), 'payload' => $envelope);
        }
        $rest = substr($envelope, strlen(self::ENVELOPE));
        $break = strpos($rest, "\n");
        if ($break === false) {
            return null;
        }
        $meta = json_decode(substr($rest, 0, $break), true);
        if (!is_array($meta)) {
            $meta = array('stored_at' => time(), 'ttl' => 0);
        }

        return array('meta' => $meta, 'payload' => substr($rest, $break + 1));
    }

    private static function encode($value, $codec)
    {
        switch ($codec) {
            case 'json':
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $encoded === false ? null : $encoded;
            case 'serialize':
                try {
                    return serialize($value);
                } catch (\Throwable $e) {
                    return null;
                }
            case 'raw':
            default:
                return is_string($value) ? $value : null;
        }
    }

    private static function decode($payload, $codec)
    {
        switch ($codec) {
            case 'json':
                $decoded = json_decode($payload, true);

                return $decoded === null ? null : $decoded;
            case 'serialize':
                $decoded = @unserialize($payload, array('allowed_classes' => false));
                if ($decoded === false && $payload !== serialize(false)) {
                    return null;
                }

                return $decoded;
            case 'raw':
            default:
                return is_string($payload) ? $payload : null;
        }
    }

    private static function writeFile($key, $envelope)
    {
        $dir = self::dir();
        $file = self::filePath($key);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $envelope, LOCK_EX) === false) {
            return false;
        }

        return @rename($tmp, $file);
    }

    private static function filePath($key)
    {
        return self::dir() . '/' . hash('sha256', $key) . '.cache';
    }

    private static function dir()
    {
        $dir = (string) Config::get('cache.dir', Config::path('data/cache'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    private static function redisKey($key)
    {
        return (string) Config::get('cache.redis.prefix', 'satapi:') . $key;
    }

    /**
     * @return \Redis|false
     */
    private static function redis()
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        if (!(bool) Config::get('cache.redis.enabled', true) || !class_exists('Redis')) {
            self::$redis = false;

            return self::$redis;
        }

        try {
            $redis = new \Redis();
            $timeout = (float) Config::get('cache.redis.timeout', 1.0);
            $connected = $redis->connect(
                (string) Config::get('cache.redis.host', '127.0.0.1'),
                (int) Config::get('cache.redis.port', 6379),
                $timeout
            );
            if (!$connected) {
                self::$redis = false;

                return self::$redis;
            }
            $redis->select((int) Config::get('cache.redis.db', 8));
            if (defined('Redis::OPT_READ_TIMEOUT')) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, $timeout);
            }
            self::$redis = $redis;
        } catch (\Throwable $e) {
            Logger::warning('redis_connect_failed', array('error' => $e->getMessage()));
            self::$redis = false;
        }

        return self::$redis;
    }

    public static function redisAvailable()
    {
        return self::redis() !== false;
    }

    /**
     * Expose the connection (used by Maintenance, which keeps the legacy
     * un-prefixed `maintenance:<id>` key convention).
     *
     * @return \Redis|false
     */
    public static function redisClient()
    {
        return self::redis();
    }

    public static function backendName()
    {
        return self::redis() !== false ? 'redis+file' : 'file';
    }

    /**
     * @return array<string,int>
     */
    public static function stats()
    {
        return self::$stats;
    }

    /**
     * Metadata for the /v1/health + /v1/status views.
     *
     * @return array<string,array>
     */
    public static function inventory(array $keys)
    {
        $out = array();
        foreach ($keys as $name => $key) {
            $raw = self::readRaw($key);
            if ($raw === null) {
                $out[$name] = array('exists' => false, 'key' => $key);
                continue;
            }
            $storedAt = isset($raw['meta']['stored_at']) ? (int) $raw['meta']['stored_at'] : 0;
            $ttl = isset($raw['meta']['ttl']) ? (int) $raw['meta']['ttl'] : 0;
            $age = max(0, time() - $storedAt);
            $out[$name] = array(
                'exists'       => true,
                'key'          => $key,
                'backend'      => $raw['backend'],
                'stored_at'    => $storedAt,
                'age_seconds'  => $age,
                'age_human'    => self::humanAge($age),
                'ttl'          => $ttl,
                'fresh'        => $ttl > 0 ? $age < $ttl : false,
                'size'         => strlen($raw['payload']),
            );
        }

        return $out;
    }

    public static function humanAge($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }

        return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
    }
}
