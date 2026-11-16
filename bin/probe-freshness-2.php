<?php
/**
 * 时效性判定 —— weather / amateur 专项（单次登录，避免触发登录限流）。
 *
 * 用法：ST_USER=... ST_PASS=... php bin/probe-freshness-2.php [weather|amateur]
 */

require __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;

$user = (string) getenv('ST_USER');
$pass = (string) getenv('ST_PASS');
if ($user === '' || $pass === '') {
    fwrite(STDERR, "need ST_USER / ST_PASS\n");
    exit(2);
}
$which = isset($argv[1]) ? $argv[1] : 'weather';

$hash = $which === 'amateur'
    ? 'cabd01d9caae0fa70caba23cb7e3625b6dee5dd624393fd0761fad97eee05478'
    : '192d107acfb2b3b83334aa69da9a49f3caefc397a2968a4b104768461ce54f7b';

$raw = file_get_contents(\SatApi\Config::path('data/cache/' . $hash . '.cache'));
$rest = substr($raw, strlen(Store::ENVELOPE));
$b = strpos($rest, "\n");
$recs = unserialize(substr($rest, $b + 1), array('allowed_classes' => false));

$local = array();
foreach ((array) $recs as $r) {
    $id = (string) ($r['norad_id'] ?? '');
    if ($id === '') {
        continue;
    }
    $local[$id] = array(
        'name'  => (string) ($r['name'] ?? ''),
        'epoch' => (int) ($r['epoch_unix'] ?? 0),
    );
}
printf("set            : %s (%s)\n", $which, $hash);
printf("local ids      : %d\n", count($local));
$eps = array_values(array_filter(array_map(static function ($v) {
    return $v['epoch'];
}, $local)));
sort($eps);
printf("local epoch p10/p50/p90/newest : %s / %s / %s / %s\n",
    $eps ? gmdate('m-d H:i', $eps[(int) (count($eps) * 0.1)]) : '-',
    $eps ? gmdate('m-d H:i', $eps[(int) (count($eps) * 0.5)]) : '-',
    $eps ? gmdate('m-d H:i', $eps[(int) (count($eps) * 0.9)]) : '-',
    $eps ? gmdate('m-d H:i', end($eps)) : '-');

/* single login */
$jar = sys_get_temp_dir() . '/st-fr-' . getmypid() . '.jar';
@unlink($jar);
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $user, 'password' => $pass)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36'),
    CURLOPT_COOKIEJAR => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
));
curl_exec($ch);
$lc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
printf("spacetrack login: http=%d\n", $lc);
if ($lc !== 200) {
    fwrite(STDERR, "login failed; aborting to avoid further rate-limit pressure\n");
    exit(1);
}

function epochToUnix($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return 0;
    }
    if (preg_match('/^(\d{2})(\d{3}\.\d+)$/', $v, $m)) {
        $yy = (int) $m[1];
        $year = $yy < 57 ? 2000 + $yy : 1900 + $yy;

        return (int) (gmmktime(0, 0, 0, 1, 1, $year) + ((float) $m[2] - 1) * 86400);
    }
    $ts = strtotime($v);

    return $ts === false ? 0 : $ts;
}

$st = array();
$ids = array_keys($local);
foreach (array_chunk($ids, 30) as $i => $chunk) {
    $path = 'class/gp/NORAD_CAT_ID/' . implode(',', $chunk) . '/format/json';
    $ch = curl_init('https://www.space-track.org/basicspacedata/query/' . $path);
    curl_setopt_array($ch, array(
        CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.0+ra'),
    ));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rows = json_decode($body, true);
    printf("  chunk %-2d http=%d rows=%s\n", $i + 1, $code, is_array($rows) ? count($rows) : 'n/a');
    foreach ((array) $rows as $row) {
        $id = (string) ($row['NORAD_CAT_ID'] ?? '');
        if ($id !== '') {
            $st[$id] = array(
                'epoch' => epochToUnix($row['EPOCH'] ?? ''),
                'type'  => (string) ($row['OBJECT_TYPE'] ?? ''),
                'decay' => (string) ($row['DECAY_DATE'] ?? ''),
            );
        }
    }
    usleep(500000);
}
@unlink($jar);

$stEps = array_values(array_filter(array_map(static function ($v) {
    return $v['epoch'];
}, $st)));
sort($stEps);
printf("\nspacetrack ids : %d\n", count($st));
printf("st  epoch p10/p50/p90/newest : %s / %s / %s / %s\n",
    $stEps ? gmdate('m-d H:i', $stEps[(int) (count($stEps) * 0.1)]) : '-',
    $stEps ? gmdate('m-d H:i', $stEps[(int) (count($stEps) * 0.5)]) : '-',
    $stEps ? gmdate('m-d H:i', $stEps[(int) (count($stEps) * 0.9)]) : '-',
    $stEps ? gmdate('m-d H:i', end($stEps)) : '-');

$both = 0; $stNewer = 0; $celNewer = 0; $equal = 0; $sum = 0; $rows = array();
foreach ($local as $id => $c) {
    if (!isset($st[$id]) || $c['epoch'] === 0 || $st[$id]['epoch'] === 0) {
        continue;
    }
    $both++;
    $d = $st[$id]['epoch'] - $c['epoch'];
    $sum += $d;
    if ($d > 60) {
        $stNewer++;
    } elseif ($d < -60) {
        $celNewer++;
    } else {
        $equal++;
    }
    $rows[] = array($id, $c['name'], $c['epoch'], $st[$id]['epoch'], $d);
}

printf("\nmatched        : %d / %d\n", $both, count($local));
printf("ST fresher     : %d\n", $stNewer);
printf("Cel fresher    : %d\n", $celNewer);
printf("equivalent     : %d\n", $equal);
if ($both > 0) {
    printf("mean (ST-Cel)  : %+.1f min\n", $sum / $both / 60);
}
printf("missing in ST  : %d\n", count(array_diff(array_keys($local), array_keys($st))));
printf("extra in ST    : %d\n", count(array_diff(array_keys($st), array_keys($local))));

usort($rows, static function ($a, $b) {
    return abs($b[4]) <=> abs($a[4]);
});
echo "\nlargest deltas (positive = SpaceTrack fresher)\n";
foreach (array_slice($rows, 0, 8) as $r) {
    printf("  %-7s %-30s cel=%-15s st=%-15s %+8.1f min\n", $r[0], substr($r[1], 0, 30),
        gmdate('m-d H:i', $r[2]), gmdate('m-d H:i', $r[3]), $r[4] / 60);
}

echo "\nverdict: ";
if ($both < 5) {
    echo "insufficient overlap\n";
} elseif ($stNewer > $celNewer * 2 + 2) {
    echo "SpaceTrack clearly fresher\n";
} elseif ($celNewer > $stNewer * 2 + 2) {
    echo "CelesTrak clearly fresher\n";
} elseif ($both > 0 && abs($sum / $both) < 1800) {
    echo "equivalent (mean |delta| < 30 min) -> use CelesTrak as primary, SpaceTrack as fallback\n";
} else {
    echo "mixed\n";
}
