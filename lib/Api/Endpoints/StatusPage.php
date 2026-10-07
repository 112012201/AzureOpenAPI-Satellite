<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;

/**
 * Self-contained HTML status dashboard.
 *
 * The previous dashboard loaded MDUI and Material Icons from jsDelivr plus
 * Google Fonts; those requests are slow or blocked for a large part of the
 * audience, so this page ships zero external dependencies, a dark/light
 * theme driven by prefers-color-scheme, and an optional auto refresh.
 */
final class StatusPage
{
    /**
     * @param array $health
     * @return string
     */
    public static function render(array $health, $request = null)
    {
        $version = htmlspecialchars((string) Config::get('service.version', ''), ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) Config::get('service.name', 'AzureOpenAPI for Satellite'), ENT_QUOTES, 'UTF-8');
        $overall = (string) $health['overall'];
        $badgeClass = $overall === 'ok' ? 'ok' : ($overall === 'degraded' ? 'warn' : 'bad');
        $timestamp = htmlspecialchars((string) $health['timestamp'], ENT_QUOTES, 'UTF-8');
        $uptime = self::duration((int) $health['uptime_seconds']);
        $redis = htmlspecialchars((string) $health['checks']['redis'], ENT_QUOTES, 'UTF-8');
        $storage = $health['checks']['storage'];
        $backend = htmlspecialchars((string) $storage['backend'], ENT_QUOTES, 'UTF-8');
        $totals = $health['metrics']['totals'];

        $cacheRows = '';
        foreach ($health['checks']['cache_files'] as $name => $entry) {
            $exists = !empty($entry['exists']);
            $fresh = !empty($entry['fresh']);
            $state = !$exists ? 'missing' : ($fresh ? 'fresh' : 'stale');
            $chip = !$exists ? 'bad' : ($fresh ? 'ok' : 'warn');
            $size = $exists ? self::bytes((int) $entry['size']) : '—';
            $age = $exists ? htmlspecialchars((string) $entry['age_human'], ENT_QUOTES, 'UTF-8') : '—';
            $ttl = $exists && !empty($entry['ttl']) ? (int) $entry['ttl'] . 's' : '—';
            $pct = 0;
            if ($exists && !empty($entry['ttl'])) {
                $pct = max(0, min(100, (int) round(100 - ($entry['age_seconds'] / max(1, $entry['ttl'])) * 100)));
            }
            $cacheRows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td><span class="chip ' . $chip . '">' . $state . '</span></td>'
                . '<td class="num">' . $size . '</td>'
                . '<td class="num">' . $age . '</td>'
                . '<td class="num">' . $ttl . '</td>'
                . '<td><div class="bar"><span style="width:' . $pct . '%"></span></div></td>'
                . '</tr>';
        }

        $maintenanceRows = '';
        foreach ($health['checks']['maintenance'] as $id => $state) {
            $on = !empty($state['enabled']);
            $maintenanceRows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td><span class="chip ' . ($on ? 'warn' : 'ok') . '">' . ($on ? 'maintenance' : 'live') . '</span></td>'
                . '<td>' . ($on ? htmlspecialchars((string) $state['message'], ENT_QUOTES, 'UTF-8') : '—') . '</td>'
                . '<td class="num">' . htmlspecialchars((string) $state['source'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $upstreamRows = '';
        foreach ($health['metrics']['upstream'] as $source => $stats) {
            $upstreamRows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $source, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td class="num">' . (int) $stats['ok'] . '</td>'
                . '<td class="num">' . (int) $stats['fail'] . '</td>'
                . '<td class="num">' . (int) $stats['last_ms'] . ' ms</td>'
                . '<td class="small">' . htmlspecialchars((string) $stats['last_error'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($upstreamRows === '') {
            $upstreamRows = '<tr><td colspan="5" class="muted">No upstream activity recorded yet.</td></tr>';
        }

        $endpointRows = '';
        foreach ($health['metrics']['endpoints'] as $endpoint => $stats) {
            $cache = array();
            foreach ((array) $stats['cache'] as $key => $count) {
                $cache[] = $key . ':' . $count;
            }
            $endpointRows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $endpoint, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td class="num">' . (int) $stats['requests'] . '</td>'
                . '<td class="num">' . (int) $stats['errors'] . '</td>'
                . '<td class="num">' . htmlspecialchars((string) $stats['avg_ms'], ENT_QUOTES, 'UTF-8') . ' ms</td>'
                . '<td class="small">' . htmlspecialchars(implode(' ', $cache), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($endpointRows === '') {
            $endpointRows = '<tr><td colspan="5" class="muted">No requests recorded yet.</td></tr>';
        }

        $requests = number_format((int) $totals['requests']);
        $errors = number_format((int) $totals['errors']);
        $avgMs = htmlspecialchars((string) $totals['avg_ms'], ENT_QUOTES, 'UTF-8');
        $bytes = self::bytes((int) $totals['bytes']);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$service} v{$version} · status</title>
<style>
:root{color-scheme:light dark;--bg:#f5f7fa;--fg:#0f172a;--muted:#64748b;--card:#fff;--line:#e2e8f0;--accent:#0891b2;--ok:#16a34a;--warn:#d97706;--bad:#dc2626}
@media (prefers-color-scheme:dark){:root{--bg:#070c17;--fg:#e2e8f0;--muted:#94a3b8;--card:#0f172a;--line:#1e293b;--accent:#22d3ee}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1080px;margin:0 auto;padding:28px 20px 60px}
header.top{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;margin-bottom:22px}
h1{font-size:1.35rem;margin:0;display:flex;align-items:center;gap:10px}
h1 svg{width:26px;height:26px;flex:none}
h2{font-size:1.02rem;margin:0 0 12px;display:flex;align-items:center;gap:8px}
.muted{color:var(--muted)}
.small{font-size:.82rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:18px}
.kpi .label{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.kpi .value{font-size:1.5rem;font-weight:600;margin-top:6px}
.chip{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.chip.ok{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok)}
.chip.warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:var(--warn)}
.chip.bad{background:color-mix(in srgb,var(--bad) 16%,transparent);color:var(--bad)}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line)}
th{font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:600}
td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
tr:last-child td{border-bottom:none}
code{background:color-mix(in srgb,var(--fg) 8%,transparent);padding:2px 6px;border-radius:6px;font-size:.85em}
.bar{background:color-mix(in srgb,var(--fg) 10%,transparent);border-radius:999px;height:6px;width:110px;overflow:hidden}
.bar span{display:block;height:100%;background:var(--accent)}
.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{display:inline-block;padding:7px 13px;border-radius:9px;border:1px solid var(--line);background:var(--card);color:var(--fg);text-decoration:none;font-size:.85rem;cursor:pointer}
.btn:hover{border-color:var(--accent);color:var(--accent)}
footer{margin-top:26px;color:var(--muted);font-size:.85rem;display:flex;gap:14px;flex-wrap:wrap;justify-content:space-between}
a{color:var(--accent)}
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
        <path d="M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>
      </svg>
      {$service} <span class="muted small">v{$version}</span>
    </h1>
    <div class="controls">
      <span class="chip {$badgeClass}">{$overall}</span>
      <select id="refresh" class="btn" aria-label="Auto refresh">
        <option value="0">Auto refresh: off</option>
        <option value="10">Every 10s</option>
        <option value="30">Every 30s</option>
        <option value="60">Every 60s</option>
      </select>
      <a class="btn" href="/v1/health">JSON</a>
      <a class="btn" href="/docs.html">API docs</a>
      <a class="btn" href="/openapi.yaml">OpenAPI</a>
    </div>
  </header>

  <section class="grid">
    <div class="card kpi"><div class="label">Checked at</div><div class="value small" style="font-weight:500">{$timestamp}</div></div>
    <div class="card kpi"><div class="label">Redis</div><div class="value small" style="font-weight:500">{$redis}</div></div>
    <div class="card kpi"><div class="label">Cache backend</div><div class="value small" style="font-weight:500">{$backend}</div></div>
    <div class="card kpi"><div class="label">Uptime</div><div class="value small" style="font-weight:500">{$uptime}</div></div>
    <div class="card kpi"><div class="label">Requests</div><div class="value">{$requests}</div></div>
    <div class="card kpi"><div class="label">Errors</div><div class="value">{$errors}</div></div>
    <div class="card kpi"><div class="label">Avg latency</div><div class="value">{$avgMs} ms</div></div>
    <div class="card kpi"><div class="label">Served</div><div class="value">{$bytes}</div></div>
  </section>

  <section class="card">
    <h2>Cache freshness</h2>
    <table>
      <thead><tr><th>Dataset</th><th>State</th><th>Size</th><th>Age</th><th>TTL</th><th>Freshness</th></tr></thead>
      <tbody>{$cacheRows}</tbody>
    </table>
  </section>

  <section class="card">
    <h2>Maintenance switches</h2>
    <table>
      <thead><tr><th>Endpoint</th><th>State</th><th>Message</th><th>Source</th></tr></thead>
      <tbody>{$maintenanceRows}</tbody>
    </table>
  </section>

  <section class="card">
    <h2>Upstream providers</h2>
    <table>
      <thead><tr><th>Source</th><th>OK</th><th>Failed</th><th>Last latency</th><th>Last error</th></tr></thead>
      <tbody>{$upstreamRows}</tbody>
    </table>
  </section>

  <section class="card">
    <h2>Request metrics</h2>
    <table>
      <thead><tr><th>Endpoint</th><th>Requests</th><th>Errors</th><th>Avg latency</th><th>Cache</th></tr></thead>
      <tbody>{$endpointRows}</tbody>
    </table>
  </section>

  <footer>
    <span>Data sources: CelesTrak · AMSAT · SatNOGS DB</span>
    <span><a href="/v1/meta">/v1/meta</a> · <a href="/v1/health/ready">readiness</a> · <a href="/v1/health/live">liveness</a></span>
  </footer>
</div>
<script>
(function () {
  var select = document.getElementById('refresh');
  var timer = null;
  function schedule(seconds) {
    if (timer) { clearTimeout(timer); timer = null; }
    if (seconds > 0) { timer = setTimeout(function () { location.reload(); }, seconds * 1000); }
  }
  select.addEventListener('change', function () {
    try { localStorage.setItem('satapi-refresh', select.value); } catch (e) {}
    schedule(parseInt(select.value, 10) || 0);
  });
  try {
    var saved = localStorage.getItem('satapi-refresh');
    if (saved && select.querySelector('option[value="' + saved + '"]')) {
      select.value = saved;
      schedule(parseInt(saved, 10) || 0);
    }
  } catch (e) {}
})();
</script>
</body>
</html>
HTML;
    }

    private static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }

        return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
    }

    private static function bytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }
}
