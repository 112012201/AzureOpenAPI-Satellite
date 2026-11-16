<?php
/**
 * 诊断：SpaceTrack 对 weather/amateur 组 ID 返回 0 匹配的原因。
 */
require __DIR__ . '/../bootstrap.php';
use SatApi\Cache\Store;

$user = (string) getenv('ST_USER');
$pass = (string) getenv('ST_PASS');

$want = array(
    '192d107acfb2b3b83334aa69da9a49f3caefc397a2968a4b104768461ce54f7b' => 'weather(72)',
    'cabd01d9caae0fa70caba23cb7e3625b6dee5dd624393fd0761fad97eee05478' => 'amateur(95)',
    'f6ac84c7fc0b934430beb7dfebb416e46cd4d64a0031bcc3e49b5f36d8b9bb1f' => 'satnogs(1497)',
);
$sets = array();
foreach ($want as $hash => $label) {
    $p = \SatApi\Config::path('data/cache/' . $hash . '.cache');
    $raw = @file_get_contents($p);
    if (!is_string($raw)) {
        echo "missing $label\n";
        continue;
    }
    $rest = substr($raw, strlen(Store::ENVELOPE));
    $b = strpos($rest, "\n");
    $recs = @unserialize(substr($rest, $b + 1), array('allowed_classes' => false));
    $ids = array();
    foreach ((array) $recs as $r) {
        $id = (string) ($r['norad_id'] ?? '');
        if ($id !== '' && ctype_digit($id)) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    $sets[$label] = $ids;
}

echo "=== A. 本地 ID 概况\n";
foreach ($sets as $label => $ids) {
    printf("  %-16s count=%-5d min=%-6s max=%-6s first10=%s\n",
        $label, count($ids), $ids[0] ?? '-', end($ids) ?: '-', implode(',', array_slice($ids, 0, 10)));
}

$jar = tempnam(sys_get_temp_dir(), 'stj');
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $user, 'password' => $pass)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent: Mozilla/5.0'),
    CURLOPT_COOKIEJAR => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
));
curl_exec($ch); curl_close($ch);

function stq($jar, $path)
{
    $ch = curl_init('https://www.space-track.org/basicspacedata/query/' . $path);
    curl_setopt_array($ch, array(
        CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.1+ra'),
    ));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('code' => $code, 'body' => $body);
}

echo "\n=== B. 逐个数据集：不同 chunk 大小 + URL 长度\n";
foreach ($sets as $label => $ids) {
    echo "--- $label\n";
    foreach (array(10, 40) as $size) {
        $chunk = array_slice($ids, 0, $size);
        $path = 'class/gp/NORAD_CAT_ID/' . implode(',', $chunk) . '/format/json';
        $r = stq($jar, $path);
        $rows = json_decode($r['body'], true);
        $n = is_array($rows) ? count($rows) : 0;
        $first = is_array($rows) && $rows ? ((string) ($rows[0]['NORAD_CAT_ID'] ?? '?')) : '-';
        printf("    chunk=%-3d urlLen=%-4d http=%-4d rows=%-3d firstNorad=%-8s %s\n",
            $size, strlen($path), $r['code'], $n, $first,
            $n === 0 ? substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 90) : '');
        usleep(300000);
    }
}

echo "\n=== C. 直接测 weather 组前 5 个 ID 单独查询\n";
foreach (array_slice($sets['weather(72)'] ?? array(), 0, 5) as $id) {
    $r = stq($jar, 'class/gp/NORAD_CAT_ID/' . $id . '/format/json');
    $rows = json_decode($r['body'], true);
    printf("    id=%-7s http=%-4d rows=%s  epoch=%s\n", $id, $r['code'],
        is_array($rows) ? count($rows) : 'n/a',
        is_array($rows) && $rows ? (string) ($rows[0]['EPOCH'] ?? '?') : '-');
    usleep(300000);
}

echo "\n=== D. 用 satcat 看这些 ID 是否存在及其类型\n";
$ids = array_slice($sets['weather(72)'] ?? array(), 0, 6);
$r = stq($jar, 'class/satcat/NORAD_CAT_ID/' . implode(',', $ids) . '/format/json');
$rows = json_decode($r['body'], true);
printf("    http=%d rows=%s\n", $r['code'], is_array($rows) ? count($rows) : 'n/a');
foreach ((array) $rows as $row) {
    printf("    %-8s %-30s type=%-10s decay=%s\n",
        (string) ($row['NORAD_CAT_ID'] ?? '?'), (string) ($row['OBJECT_NAME'] ?? '?'),
        (string) ($row['OBJECT_TYPE'] ?? '?'), (string) ($row['DECAY_DATE'] ?? 'null'));
}

@unlink($jar);
