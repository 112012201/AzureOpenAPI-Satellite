<?php
/**
 * 时效性判定（第 3 版，只读）。
 *
 * 前两版的错判（记录在案，避免后人重踩）：
 *   v1 用 $r['epoch'] 取 epoch —— 实际字段是 epoch_unix / epoch_iso，取到 null。
 *   v1/v2 用“记录里有没有 norad_id + tle”识别 TLE 数据集 —— 实际 TLE 记录是
 *        {norad_id, name, line1, line2, epoch_unix, epoch_iso}，没有 tle 字段，
 *        于是 weather 快照被漏掉，反而把 SatNOGS 目录误认成 TLE（它含
 *        norad_cat_id 且名字里有历史星）。
 *   v1/v2 用名称特征（NOAA/DMSP…）判定组归属 —— SatNOGS 目录同样含这些名字。
 *   正确判据：存在 epoch_unix + line1 + line2 三字段 == 已解析的 TLE 数据集。
 *
 * 用法：ST_USER=... ST_PASS=... php bin/probe-freshness.php
 */

require __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;

$stUser = (string) getenv('ST_USER');
$stPass = (string) getenv('ST_PASS');
if ($stUser === '' || $stPass === '') {
    fwrite(STDERR, "need ST_USER / ST_PASS\n");
    exit(2);
}

function epochToUnix($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    if (preg_match('/^(\d{2})(\d{3}\.\d+)$/', $value, $m)) {
        $yy = (int) $m[1];
        $year = $yy < 57 ? 2000 + $yy : 1900 + $yy;

        return (int) (gmmktime(0, 0, 0, 1, 1, $year) + ((float) $m[2] - 1) * 86400);
    }
    $ts = strtotime($value);

    return $ts === false ? 0 : $ts;
}

/* -------------------- A. enumerate parsed TLE datasets in the file cache -- */

$datasets = array();
foreach ((array) glob(\SatApi\Config::path('data/cache/*.cache')) as $f) {
    $raw = @file_get_contents($f);
    if (!is_string($raw) || strncmp($raw, Store::ENVELOPE, strlen(Store::ENVELOPE)) !== 0) {
        continue;
    }
    $rest = substr($raw, strlen(Store::ENVELOPE));
    $b = strpos($rest, "\n");
    if ($b === false) {
        continue;
    }
    $recs = @unserialize(substr($rest, $b + 1), array('allowed_classes' => false));
    if (!is_array($recs) || $recs === array()) {
        continue;
    }
    $first = reset($recs);
    if (!is_array($first)) {
        continue;
    }
    // Correct discriminator: a parsed TLE record carries these three.
    if (!array_key_exists('epoch_unix', $first) || !array_key_exists('line1', $first) || !array_key_exists('line2', $first)) {
        continue;
    }
    $datasets[] = array('file' => $f, 'records' => $recs, 'count' => count($recs));
}
usort($datasets, static function ($a, $b) {
    return $b['count'] <=> $a['count'];
});

echo "=== A. parsed TLE datasets in the file cache\n";
foreach ($datasets as $d) {
    $names = array();
    foreach (array_slice($d['records'], 0, 6) as $r) {
        $names[] = (string) ($r['name'] ?? '');
    }
    $ep = 0;
    $epochs = array();
    foreach ($d['records'] as $r) {
        $u = (int) ($r['epoch_unix'] ?? 0);
        if ($u > 0) {
            $ep++;
            $epochs[] = $u;
        }
    }
    printf("  %-12s n=%-5d withEpoch=%-5d medianEpoch=%-20s %s\n",
        substr(basename($d['file']), 0, 10), $d['count'], $ep,
        $epochs === array() ? '-' : gmdate('Y-m-d H:i', $epochs[(int) (count($epochs) / 2)]),
        implode(' | ', $names));
}

/* ------------------------------------------------- per-dataset comparison */

$jar = tempnam(sys_get_temp_dir(), 'stj');
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $stUser, 'password' => $stPass)),
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36'),
    CURLOPT_COOKIEJAR => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
));
curl_exec($ch);
printf("\nSpaceTrack login http=%d\n", (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
curl_close($ch);

$summary = array();
foreach ($datasets as $d) {
    if ($d['count'] < 50) {
        continue; // the 72/95/97/1497 TLE sets are what matter
    }
    $byId = array();
    foreach ($d['records'] as $r) {
        $id = (string) ($r['norad_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $byId[$id] = array(
            'name'      => (string) ($r['name'] ?? ''),
            'epoch'     => (int) ($r['epoch_unix'] ?? 0),
            'epoch_iso' => (string) ($r['epoch_iso'] ?? ''),
        );
    }
    if ($byId === array()) {
        continue;
    }

    $st = array();
    foreach (array_chunk(array_keys($byId), 40) as $chunk) {
        $url = 'https://www.space-track.org/basicspacedata/query/class/gp/NORAD_CAT_ID/'
            . implode(',', $chunk) . '/format/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.1+ra (+https://satellite.api.authskin.xyz)'),
        ));
        $body = (string) curl_exec($ch);
        curl_close($ch);
        foreach ((array) json_decode($body, true) as $row) {
            $id = (string) ($row['NORAD_CAT_ID'] ?? '');
            if ($id !== '') {
                $st[$id] = epochToUnix($row['EPOCH'] ?? '');
            }
        }
        usleep(400000);
    }

    $both = 0; $stNewer = 0; $celNewer = 0; $equal = 0; $sum = 0; $newest = 0;
    foreach ($byId as $id => $c) {
        if ($c['epoch'] > $newest) {
            $newest = $c['epoch'];
        }
        if (!isset($st[$id]) || $c['epoch'] === 0 || $st[$id] === 0) {
            continue;
        }
        $both++;
        $delta = $st[$id] - $c['epoch'];
        $sum += $delta;
        if ($delta > 60) {
            $stNewer++;
        } elseif ($delta < -60) {
            $celNewer++;
        } else {
            $equal++;
        }
    }
    $summary[] = array(
        'file'      => substr(basename($d['file']), 0, 10),
        'ids'       => count($byId),
        'matched'   => $both,
        'stNewer'   => $stNewer,
        'celNewer'  => $celNewer,
        'equal'     => $equal,
        'meanMin'   => $both > 0 ? $sum / $both / 60 : 0,
        'celNewest' => $newest,
        'sample'    => implode(' | ', array_slice(array_map(static function ($r) {
            return (string) ($r['name'] ?? '');
        }, array_slice($d['records'], 0, 3)), 0, 3)),
    );
    printf("  compared %-12s ids=%-5d matched=%-5d\n", substr(basename($d['file']), 0, 10), count($byId), $both);
}
@unlink($jar);

echo "\n=== B. result per dataset\n";
printf("  %-12s %-6s %-8s %-10s %-10s %-8s %-12s %s\n",
    'dataset', 'ids', 'matched', 'ST newer', 'Cel newer', 'equal', 'mean(ST-Cel)', 'newest Cel epoch');
foreach ($summary as $s) {
    printf("  %-12s %-6d %-8d %-10d %-10d %-8d %+9.1f min  %-16s %s\n",
        $s['file'], $s['ids'], $s['matched'], $s['stNewer'], $s['celNewer'], $s['equal'],
        $s['meanMin'],
        $s['celNewest'] > 0 ? gmdate('Y-m-d H:i', $s['celNewest']) : '-',
        $s['sample']);
}

echo "\n=== C. verdict\n";
foreach ($summary as $s) {
    if ($s['matched'] < 5) {
        continue;
    }
    if ($s['stNewer'] > $s['celNewer'] * 2 + 2) {
        $v = 'SpaceTrack clearly fresher';
    } elseif ($s['celNewer'] > $s['stNewer'] * 2 + 2) {
        $v = 'CelesTrak clearly fresher';
    } elseif (abs($s['meanMin']) < 30) {
        $v = 'equivalent (within 30 min on average)';
    } else {
        $v = 'mixed; prefer the one with the newer median';
    }
    printf("  %-12s -> %s\n", $s['file'], $v);
}
