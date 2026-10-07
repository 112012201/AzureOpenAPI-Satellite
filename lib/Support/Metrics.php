<?php

namespace SatApi\Support;

use SatApi\Config;

/**
 * Lightweight request/upstream telemetry persisted as a single JSON document.
 *
 * It powers the diagnostics in /v1/health and the dashboard in /v1/status
 * without requiring Prometheus or an external metrics service.
 */
final class Metrics
{
    /** @var array|null */
    private static $data = null;
    private static $dirty = false;
    private static $endpoint = 'unknown';

    public static function setEndpoint($name)
    {
        self::$endpoint = (string) $name;
    }

    public static function currentEndpoint()
    {
        return self::$endpoint;
    }

    private static function file()
    {
        return (string) Config::get('metrics.file', Config::path('data/metrics.json'));
    }

    /**
     * Reference to the in-memory document so writers mutate the real state
     * instead of a copy-on-write duplicate.
     *
     * @return array<string,mixed>
     */
    private static function &data()
    {
        if (self::$data === null) {
            self::load();
        }

        return self::$data;
    }

    private static function load()
    {
        $file = self::file();
        $data = null;
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        if ($data === null) {
            $data = array();
        }
        $data += array(
            'started_at' => time(),
            'version'    => (string) Config::get('service.version', ''),
            'endpoints'  => array(),
            'upstream'   => array(),
            'totals'     => array('requests' => 0, 'errors' => 0, 'bytes' => 0, 'ms_sum' => 0),
        );
        $data['totals'] += array('requests' => 0, 'errors' => 0, 'bytes' => 0, 'ms_sum' => 0);

        self::$data = $data;
    }

    /**
     * @return array<string,mixed>
     */
    public static function snapshot()
    {
        if (self::$data === null) {
            self::load();
        }

        return self::$data;
    }

    public static function record($endpoint, $status, $ms, $cacheStatus = '', $bytes = 0)
    {
        $data = &self::data();
        $endpoint = (string) $endpoint;

        if (!isset($data['endpoints'][$endpoint])) {
            $data['endpoints'][$endpoint] = array(
                'requests' => 0, 'errors' => 0, 'ms_sum' => 0, 'bytes' => 0, 'last_status' => 0,
                'last_seen' => 0, 'cache' => array(),
            );
        }
        $data['endpoints'][$endpoint]['requests']++;
        $data['endpoints'][$endpoint]['ms_sum'] += (int) $ms;
        $data['endpoints'][$endpoint]['bytes'] = (int) ($data['endpoints'][$endpoint]['bytes'] ?? 0) + (int) $bytes;
        $data['endpoints'][$endpoint]['last_status'] = (int) $status;
        $data['endpoints'][$endpoint]['last_seen'] = time();
        if ($status >= 400) {
            $data['endpoints'][$endpoint]['errors']++;
            $data['totals']['errors']++;
        }
        if ($cacheStatus !== '') {
            if (!isset($data['endpoints'][$endpoint]['cache'][$cacheStatus])) {
                $data['endpoints'][$endpoint]['cache'][$cacheStatus] = 0;
            }
            $data['endpoints'][$endpoint]['cache'][$cacheStatus]++;
        }

        $data['totals']['requests']++;
        $data['totals']['ms_sum'] += (int) $ms;
        $data['totals']['bytes'] += (int) $bytes;

        self::$dirty = true;
    }

    /**
     * Record the outcome of an upstream fetch.
     */
    public static function upstream($source, $ok, $ms = 0, $error = '')
    {
        $data = &self::data();
        if (!isset($data['upstream'][$source])) {
            $data['upstream'][$source] = array(
                'ok' => 0, 'fail' => 0, 'last_ms' => 0, 'last_ok_at' => 0, 'last_fail_at' => 0, 'last_error' => '',
            );
        }
        $data['upstream'][$source]['last_ms'] = (int) $ms;
        if ($ok) {
            $data['upstream'][$source]['ok']++;
            $data['upstream'][$source]['last_ok_at'] = time();
        } else {
            $data['upstream'][$source]['fail']++;
            $data['upstream'][$source]['last_fail_at'] = time();
            $data['upstream'][$source]['last_error'] = substr((string) $error, 0, 200);
        }

        self::$dirty = true;
    }

    public static function flush()
    {
        if (!self::$dirty || self::$data === null) {
            return;
        }
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $encoded = json_encode(self::$data, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
        self::$dirty = false;
    }

    /**
     * Aggregated view for /v1/health.
     *
     * @return array<string,mixed>
     */
    public static function summary()
    {
        $data = self::snapshot();
        $endpoints = array();
        foreach ($data['endpoints'] as $name => $bucket) {
            $requests = max(1, (int) $bucket['requests']);
            $endpoints[$name] = array(
                'requests'     => (int) $bucket['requests'],
                'errors'       => (int) $bucket['errors'],
                'avg_ms'       => round($bucket['ms_sum'] / $requests, 2),
                'cache'        => $bucket['cache'],
                'last_status'  => (int) $bucket['last_status'],
                'last_seen_at' => (int) $bucket['last_seen'],
            );
        }

        $totalRequests = max(1, (int) $data['totals']['requests']);

        return array(
            'started_at'     => (int) $data['started_at'],
            'uptime_seconds' => max(0, time() - (int) $data['started_at']),
            'totals'         => array(
                'requests' => (int) $data['totals']['requests'],
                'errors'   => (int) $data['totals']['errors'],
                'avg_ms'   => round($data['totals']['ms_sum'] / $totalRequests, 2),
                'bytes'    => (int) $data['totals']['bytes'],
            ),
            'endpoints' => $endpoints,
            'upstream'  => $data['upstream'],
        );
    }
}
