<?php
/**
 * 事故恢复：把 weather TLE 数据集重新写入 Redis 缓存。
 *
 * 背景（2026-10-07 事故）：
 *   验证脚本为确认"缓存不残留旧名"而执行了
 *     redis-cli -n 8 del satapi:dataset:tle:weather
 *   清掉 weather 数据集后，上游 CelesTrak 恰在同期开始对
 *   /NORAD/elements/* 返回 HTTP 403（warmup.log: 07:00 仍 ok，08:00 起 FAIL 403），
 *   于是该端点从"服务陈旧副本"变成 503。
 *
 * 恢复来源（按可信度排序）：
 *   1. 站点自身的文件缓存 data/cache/192d107a….cache —— 与线上 Redis 条目同源
 *      （staging 副本哈希一致），即事故前最后一次成功写入的内容；
 *   2. 归档站点的 /www/wwwroot/satellite.api.authskin.xyz.old/tle_weather_cache.txt
 *      —— 旧版脚本的原始上游文本，可作为内容校验的对照。
 *
 * 用法：php bin/restore-dataset.php tle:weather
 *       默认 --dry-run，加 --apply 才写入。
 */

require __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;
use SatApi\Tle\Parser;

$key = isset($argv[1]) ? $argv[1] : 'tle:weather';
$apply = in_array('--apply', $argv, true);
$root = \SatApi\Config::root();

if ($key !== 'tle:weather') {
    fwrite(STDERR, "unsupported key: $key\n");
    exit(2);
}

$targets = array(
    // The API's own file cache entry for this dataset.
    $root . '/data/cache/192d107acfb2b3b83334aa69da9a49f3caefc397a2968a4b104768461ce54f7b.cache',
    // The archived legacy script cache (raw upstream text).
    '/www/wwwroot/satellite.api.authskin.xyz.old/tle_weather_cache.txt',
);

$chosen = null;
$chosenSource = '';
foreach ($targets as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "skip (absent): $file\n");
        continue;
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        fwrite(STDERR, "skip (empty): $file\n");
        continue;
    }
    // File-cache entries carry the SATAPI1 envelope; unwrap to the payload.
    if (strncmp($raw, Store::ENVELOPE, strlen(Store::ENVELOPE)) === 0) {
        $rest = substr($raw, strlen(Store::ENVELOPE));
        $break = strpos($rest, "\n");
        if ($break === false) {
            fwrite(STDERR, "skip (bad envelope): $file\n");
            continue;
        }
        $meta = json_decode(substr($rest, 0, $break), true);
        $raw = substr($rest, $break + 1);
        fwrite(STDOUT, "envelope: codec=" . (is_array($meta) ? ($meta['codec'] ?? '?') : '?')
            . " stored_at=" . (is_array($meta) ? gmdate('c', (int) ($meta['stored_at'] ?? 0)) : '?')
            . " ttl=" . (is_array($meta) ? ($meta['ttl'] ?? '?') : '?') . "\n");
        // A serialized PHP array of parsed records: use it directly.
        $decoded = @unserialize($raw, array('allowed_classes' => false));
        if (is_array($decoded) && !empty($decoded)) {
            $chosen = $decoded;
            $chosenSource = $file . ' (unserialized records)';
            break;
        }
        // Otherwise it is TLE text.
    }
    $records = Parser::parseText($raw, 'weather');
    if (empty($records)) {
        fwrite(STDERR, "skip (no records parsed): $file\n");
        continue;
    }
    $chosen = $records;
    $chosenSource = $file . ' (parsed TLE text)';
    break;
}

if (!is_array($chosen) || empty($chosen)) {
    fwrite(STDERR, "FATAL: no recoverable source produced records\n");
    exit(1);
}

$norad = array();
foreach ($chosen as $r) {
    if (isset($r['norad_id'])) {
        $norad[$r['norad_id']] = true;
    }
}
ksort($norad);

fwrite(STDOUT, "source     : $chosenSource\n");
fwrite(STDOUT, "records    : " . count($chosen) . "\n");
fwrite(STDOUT, "distinct id: " . count($norad) . "\n");
fwrite(STDOUT, "id range   : " . (empty($norad) ? '-' : min(array_keys($norad)) . ' … ' . max(array_keys($norad))) . "\n");
$first = reset($chosen);
fwrite(STDOUT, "first rec  : " . json_encode(array(
    'name'      => $first['name'] ?? null,
    'norad_id'  => $first['norad_id'] ?? null,
    'epoch'     => $first['epoch'] ?? null,
    'line1_len' => isset($first['tle'][0]) ? strlen($first['tle'][0]) : null,
)) . "\n");

// Sanity gate: the weather group is ~70+ objects with 5-digit NORAD ids and
// well-formed 69-column TLE lines. Refuse to write anything that fails this.
$bad = 0;
foreach ($chosen as $r) {
    foreach ((array) ($r['tle'] ?? array()) as $line) {
        if (strlen((string) $line) !== 69) {
            $bad++;
        }
    }
}
fwrite(STDOUT, "malformed TLE lines: $bad\n");
if ($bad > 0) {
    fwrite(STDERR, "FATAL: refusing to write records with malformed TLE lines\n");
    exit(1);
}
if (count($chosen) < 20) {
    fwrite(STDERR, "FATAL: only " . count($chosen) . " records; weather group is expected to be larger\n");
    exit(1);
}

if (!$apply) {
    fwrite(STDOUT, "\nDRY RUN — nothing written. Re-run with --apply\n");
    exit(0);
}

// TTL: use the configured weather TTL so the entry behaves like a fresh fetch.
$ttl = (int) \SatApi\Config::get('sources.weather.ttl', 10800);
$ok = Store::write('dataset:tle:weather', $chosen, $ttl + 2592000, 'serialize', $ttl, time());

fwrite(STDOUT, "\nwrite      : " . ($ok ? 'OK' : 'FAILED') . "\n");
fwrite(STDOUT, "redis ttl  : " . (Store::redisAvailable() ? 'redis available' : 'redis unavailable') . "\n");
exit($ok ? 0 : 1);
