<?php
/**
 * 诊断：两边 epoch 字段的真实内容与位置（只读）。
 */
require __DIR__ . '/../bootstrap.php';
use SatApi\Cache\Store;

echo "=== A. CelesTrak 快照里的单条记录结构（原样打印）\n";
$found = false;
foreach ((array) glob(\SatApi\Config::path('data/cache/*.cache')) as $f) {
    if ($found) {
        break;
    }
    $raw = @file_get_contents($f);
    if (!is_string($raw) || strncmp($raw, Store::ENVELOPE, strlen(Store::ENVELOPE)) !== 0) continue;
    $rest = substr($raw, strlen(Store::ENVELOPE));
    $b = strpos($rest, "\n");
    $recs = @unserialize(substr($rest, $b + 1), array('allowed_classes' => false));
    if (!is_array($recs) || !isset($recs[0]['name'])) continue;
    $j = strtoupper(implode(' ', array_map(static function ($r) { return (string) ($r['name'] ?? ''); }, $recs)));
    if (substr_count($j, 'NOAA') + substr_count($j, 'DMSP') + substr_count($j, 'METOP') + substr_count($j, 'METEOR') < 10) continue;
    $found = true;
    echo "file: ", basename($f), "\n";
    echo "record[0] keys: ", implode(', ', array_keys($recs[0])), "\n";
    echo "record[0] = ", json_encode($recs[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    for ($i = 1; $i < 3; $i++) {
        echo "record[$i].name=", ($recs[$i]['name'] ?? '?'), "  epoch=", var_export($recs[$i]['epoch'] ?? null, true),
             "  epoch_unix=", var_export($recs[$i]['epoch_unix'] ?? null, true), "\n";
    }
}

echo "\n=== B. SpaceTrack 原始 3le 响应（前 14 行，逐列标注）\n";
$user = getenv('ST_USER'); $pass = getenv('ST_PASS');
$jar = tempnam(sys_get_temp_dir(), 'stj');
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $user, 'password' => $pass)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent: Mozilla/5.0'),
    CURLOPT_COOKIEJAR => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
));
curl_exec($ch); curl_close($ch);
$ch = curl_init('https://www.space-track.org/basicspacedata/query/class/gp/NORAD_CAT_ID/25338,28654/format/3le');
curl_setopt_array($ch, array(
    CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.1+ra'),
));
$body = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch); @unlink($jar);
echo "http=$code bytes=", strlen($body), "\n";
foreach (array_slice(preg_split('/\r\n|\r|\n/', trim($body)), 0, 14) as $i => $line) {
    printf("  [%02d] len=%-3d |%s|\n", $i, strlen($line), $line);
}
echo "\n--- 按 TLE 规范切片（line1: norad=2..7, epoch=18..32）\n";
foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
    if (strlen($line) >= 69 && $line[0] === '1') {
        printf("  norad=[%s]  epoch=[%s]\n", substr($line, 2, 5), substr($line, 18, 14));
    }
}

echo "\n=== C. SpaceTrack json 格式（看有没有直接给 epoch 字段）\n";
$jar2 = tempnam(sys_get_temp_dir(), 'stj');
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $user, 'password' => $pass)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent: Mozilla/5.0'),
    CURLOPT_COOKIEJAR => $jar2, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
));
curl_exec($ch); curl_close($ch);
$ch = curl_init('https://www.space-track.org/basicspacedata/query/class/gp/NORAD_CAT_ID/25338,28654/format/json');
curl_setopt_array($ch, array(
    CURLOPT_COOKIEFILE => $jar2, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.1+ra'),
));
$jb = (string) curl_exec($ch); curl_close($ch); @unlink($jar2);
$d = json_decode($jb, true);
if (is_array($d)) {
    echo "rows=", count($d), "\n";
    echo "keys=", implode(', ', array_keys($d[0] ?? array())), "\n";
    foreach (array_slice($d, 0, 2) as $row) {
        printf("  NORAD_CAT_ID=%s  EPOCH=%s  TLE_LINE1=%s\n",
            $row['NORAD_CAT_ID'] ?? '?', $row['EPOCH'] ?? '?', substr((string) ($row['TLE_LINE1'] ?? ''), 0, 40));
    }
} else {
    echo "非 JSON，前 200 字节：", substr($jb, 0, 200), "\n";
}
