<?php

namespace SatApi\Support;

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Logger;

/**
 * Per-endpoint maintenance switches.
 *
 * Storage is intentionally compatible with the previous implementation:
 *   - Redis key  maintenance:<endpoint>   (DB 8, no namespace prefix)
 *   - file       data/.maintenance_<endpoint>
 * so existing operator tooling keeps working, while the new admin API
 * (POST /v1/maintenance, bearer token) can also toggle them.
 */
final class Maintenance
{
    /** Endpoints that can be switched off individually. */
    const ENDPOINTS = array(
        'tle_merge',
        'tle_weather',
        'tle_amateur',
        'tle_amsat',
        'tle_satnogs',
        'satnogs_satellites',
        'satnogs_transmitters',
        'amsat_status',
        // AMSAT's own v1 API, relayed by Api\Endpoints\AmsatApi. A separate switch
        // from amsat_status on purpose: the scraped status page and the upstream JSON
        // API fail independently, so taking one down must not silence the other.
        'amsat_api',
        'health',
    );

    private static $cache = null;

    public static function isKnown($id)
    {
        return in_array($id, self::ENDPOINTS, true);
    }

    /**
     * @return array{message:string,until:int,source:string}|null
     */
    public static function status($id)
    {
        if (self::$cache === null) {
            self::$cache = array();
        }
        if (array_key_exists($id, self::$cache)) {
            return self::$cache[$id];
        }

        $result = null;
        $redis = Store::redisClient();
        if ($redis !== false) {
            try {
                $value = $redis->get('maintenance:' . $id);
                $ttl = (int) $redis->ttl('maintenance:' . $id);
                if (is_string($value) && $value !== '') {
                    $result = array(
                        'message' => $value,
                        'until'   => $ttl > 0 ? time() + $ttl : 0,
                        'source'  => 'redis',
                    );
                }
            } catch (\Throwable $e) {
                Logger::warning('maintenance_redis_read_failed', array('error' => $e->getMessage()));
            }
        }

        if ($result === null) {
            $file = self::flagPath($id);
            if (is_file($file)) {
                $message = trim((string) @file_get_contents($file));
                $result = array(
                    'message' => $message !== '' ? $message : self::defaultMessage($id),
                    'until'   => 0,
                    'source'  => 'file',
                );
            }
        }

        self::$cache[$id] = $result;

        return $result;
    }

    /**
     * Emit a 503 and stop when the endpoint is disabled.
     */
    public static function enforce($id, Request $request)
    {
        $status = self::status($id);
        if ($status === null) {
            return;
        }

        $retry = $status['until'] > 0 ? max(1, $status['until'] - time()) : 120;
        $headers = array(
            'Retry-After' => (string) $retry,
            'Cache-Control' => 'no-store',
            'X-Maintenance' => $id,
        );

        Logger::info('maintenance_block', array('endpoint' => $id));

        if ($request->isBrowser() && !$request->wantsJson(false)) {
            Response::raw(self::html($status['message'], $id, $retry), 'text/html; charset=utf-8', 503, $headers);

            return;
        }

        Problem::send(
            503,
            'Service Unavailable',
            $status['message'],
            'maintenance',
            array(
                'maintenance' => array(
                    'endpoint'    => $id,
                    'retry_after' => $retry,
                    'until'       => $status['until'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $status['until']) : null,
                ),
            ),
            $headers
        );
    }

    /**
     * @return array<string,array>
     */
    public static function all()
    {
        $out = array();
        foreach (self::ENDPOINTS as $id) {
            $status = self::status($id);
            $out[$id] = array(
                'endpoint' => $id,
                'enabled'  => $status !== null,
                'message'  => $status !== null ? $status['message'] : '',
                'source'   => $status !== null ? $status['source'] : '',
            );
        }

        return $out;
    }

    /**
     * @param string $id
     * @param bool   $enabled
     * @param string $message
     * @param int    $ttl
     * @return bool
     */
    public static function set($id, $enabled, $message = '', $ttl = 3600)
    {
        if (!self::isKnown($id)) {
            return false;
        }

        $message = trim((string) $message);
        if ($message === '') {
            $message = self::defaultMessage($id);
        }

        $ok = false;
        $redis = Store::redisClient();
        if ($redis !== false) {
            try {
                if ($enabled) {
                    $ok = (bool) $redis->setex('maintenance:' . $id, max(60, (int) $ttl), $message);
                } else {
                    $redis->del('maintenance:' . $id);
                    $ok = true;
                }
            } catch (\Throwable $e) {
                Logger::warning('maintenance_redis_write_failed', array('error' => $e->getMessage()));
                $ok = false;
            }
        }

        $file = self::flagPath($id);
        if ($enabled) {
            if (@file_put_contents($file, $message, LOCK_EX) === false && !$ok) {
                return false;
            }
            $ok = true;
        } else {
            if (is_file($file) && !@unlink($file) && !$ok) {
                return false;
            }
            $ok = true;
        }

        self::$cache = null;
        Logger::info('maintenance_set', array('endpoint' => $id, 'enabled' => (bool) $enabled, 'ttl' => (int) $ttl));

        return $ok;
    }

    private static function flagPath($id)
    {
        $dir = (string) Config::get('maintenance.dir', Config::path('data'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return rtrim($dir, "/\\") . '/.maintenance_' . $id;
    }

    public static function defaultMessage($id)
    {
        return 'The ' . $id . ' endpoint is temporarily unavailable for scheduled maintenance. Please retry later.';
    }

    /**
     * Self-contained maintenance page (no CDN, respects prefers-color-scheme).
     */
    public static function html($message, $id, $retryAfter = 120)
    {
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $home = htmlspecialchars((string) Config::get('service.homepage', '/'), ENT_QUOTES, 'UTF-8');
        $docs = htmlspecialchars((string) Config::get('service.docs', '/docs.html'), ENT_QUOTES, 'UTF-8');
        $contact = htmlspecialchars((string) Config::get('service.contact', ''), ENT_QUOTES, 'UTF-8');
        $contactHtml = $contact !== '' ? ' &middot; <a href="' . $contact . '" rel="noopener">Contact</a>' : '';
        $retry = (int) $retryAfter;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>503 - Scheduled maintenance | AzureOpenAPI for Satellite</title>
<style>
:root{color-scheme:light dark;--bg:#f6f7f9;--fg:#111827;--muted:#6b7280;--card:#ffffff;--line:#e5e7eb;--accent:#0891b2}
@media (prefers-color-scheme:dark){:root{--bg:#0b1120;--fg:#e5e7eb;--muted:#9ca3af;--card:#111827;--line:#1f2937;--accent:#22d3ee}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);
 font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans SC","Microsoft YaHei",sans-serif;padding:24px}
.card{max-width:640px;width:100%;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:32px;
 box-shadow:0 10px 30px rgba(2,6,23,.08)}
.badge{display:inline-flex;align-items:center;gap:8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;
 color:var(--accent);border:1px solid currentColor;border-radius:999px;padding:4px 10px;font-weight:600}
h1{font-size:1.6rem;margin:18px 0 8px}
p{margin:0 0 14px}
.msg{background:color-mix(in srgb, var(--accent) 10%, transparent);border-left:3px solid var(--accent);
 border-radius:8px;padding:14px 16px;color:var(--fg)}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:18px 0 0;font-size:.92rem;color:var(--muted)}
dt{font-weight:600;color:var(--fg)}
code{background:color-mix(in srgb, var(--fg) 8%, transparent);padding:2px 6px;border-radius:6px;font-size:.9em}
footer{margin-top:22px;padding-top:16px;border-top:1px solid var(--line);font-size:.88rem;color:var(--muted);
 display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between}
a{color:var(--accent)}
</style>
</head>
<body>
<main class="card" role="main">
  <span class="badge">503 &middot; Service Unavailable</span>
  <h1>Endpoint under maintenance</h1>
  <div class="msg">{$safeMessage}</div>
  <dl>
    <dt>Endpoint</dt><dd><code>{$safeId}</code></dd>
    <dt>Retry after</dt><dd>{$retry} seconds</dd>
    <dt>Status page</dt><dd><a href="{$home}/v1/status">{$home}/v1/status</a></dd>
  </dl>
  <footer>
    <span>AzureOpenAPI for Satellite &middot; CelesTrak / AMSAT / SatNOGS</span>
    <span><a href="{$docs}">API docs</a>{$contactHtml}</span>
  </footer>
</main>
</body>
</html>
HTML;
    }
}
