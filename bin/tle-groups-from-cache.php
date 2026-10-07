<?php
/**
 * Derive the static TLE group membership files from the CelesTrak snapshots that
 * are already cached on this server.
 *
 * Why a static list: SpaceTrack has no equivalent of CelesTrak's
 * `GROUP=weather` / `GROUP=amateur` presets (measured: `class/gp/GROUP/weather`
 * answers HTTP 500), so the fallback provider has to be queried by NORAD id. The
 * id set has to come from somewhere authoritative, and the last successful
 * CelesTrak fetch is exactly that.
 *
 * Usage (dry run prints the list, --write updates conf/tle-groups.php):
 *   php bin/tle-groups-from-cache.php
 *   php bin/tle-groups-from-cache.php --write
 */

require __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;

$write = in_array('--write', $argv, true);

$sources = array(
    'weather' => '192d107acfb2b3b83334aa69da9a49f3caefc397a2968a4b104768461ce54f7b',
    'amateur' => 'cabd01d9caae0fa70caba23cb7e3625b6dee5dd624393fd0761fad97eee05478',
);

$derived = array();
foreach ($sources as $group => $hash) {
    $path = \SatApi\Config::path('data/cache/' . $hash . '.cache');
    if (!is_file($path)) {
        fwrite(STDERR, "missing snapshot for $group: $path\n");
        exit(1);
    }
    $raw = (string) file_get_contents($path);
    if (strncmp($raw, Store::ENVELOPE, strlen(Store::ENVELOPE)) !== 0) {
        fwrite(STDERR, "not an envelope: $path\n");
        exit(1);
    }
    $rest = substr($raw, strlen(Store::ENVELOPE));
    $b = strpos($rest, "\n");
    $meta = json_decode(substr($rest, 0, $b), true);
    $recs = unserialize(substr($rest, $b + 1), array('allowed_classes' => false));
    if (!is_array($recs) || $recs === array()) {
        fwrite(STDERR, "no records in $path\n");
        exit(1);
    }

    $ids = array();
    $names = array();
    foreach ($recs as $r) {
        $id = (string) ($r['norad_id'] ?? '');
        if ($id === '' || !ctype_digit($id)) {
            continue;
        }
        $ids[$id] = true;
        $names[$id] = (string) ($r['name'] ?? '');
    }
    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);

    $derived[$group] = array(
        'ids'       => $ids,
        'names'     => $names,
        'stored_at' => is_array($meta) ? (int) ($meta['stored_at'] ?? 0) : 0,
        'ttl'       => is_array($meta) ? (int) ($meta['ttl'] ?? 0) : 0,
    );
    printf("%-9s ids=%-4d snapshot_stored_at=%s\n", $group, count($ids),
        is_array($meta) && !empty($meta['stored_at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) $meta['stored_at']) : '?');
}

/* ---- sanity: refuse to write a suspiciously small group -------------------- */
$problems = array();
if (count($derived['weather']['ids']) < 30) {
    $problems[] = 'weather group has fewer than 30 ids';
}
if (count($derived['amateur']['ids']) < 40) {
    $problems[] = 'amateur group has fewer than 40 ids';
}
if ($problems !== array()) {
    fwrite(STDERR, "refusing to write: " . implode('; ', $problems) . "\n");
    exit(1);
}

$stamp = gmdate('Y-m-d\TH:i:s\Z');
$block = array();
$block[] = '<?php';
$block[] = '';
$block[] = '/**';
$block[] = ' * TLE group membership, used to query providers that have no group presets.';
$block[] = ' *';
$block[] = ' * CelesTrak serves these groups directly (GROUP=weather / GROUP=amateur). SpaceTrack';
$block[] = ' * does not (class/gp/GROUP/... answers HTTP 500), so the fallback provider is queried by';
$block[] = ' * NORAD id and needs this list.';
$block[] = ' *';
$block[] = ' * Generated from the last successful CelesTrak snapshot on the deployment host:';
foreach ($derived as $group => $d) {
    $block[] = sprintf(' *   %-8s %3d ids, snapshot %s', $group, count($d['ids']),
        $d['stored_at'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $d['stored_at']) : 'unknown');
}
$block[] = ' *';
$block[] = ' * Regenerate with:  php bin/tle-groups-from-cache.php --write';
$block[] = ' * Review the diff before deploying: a group that shrinks unexpectedly is a signal that';
$block[] = ' * the snapshot it was derived from was incomplete.';
$block[] = ' *';
$block[] = ' * Generated at: ' . $stamp;
$block[] = ' */';
$block[] = '';
$block[] = 'return array(';

foreach ($derived as $group => $d) {
    $block[] = "    '$group' => array(";
    $block[] = '        // NORAD catalogue numbers, ascending.';
    $block[] = '        \'ids\' => array(';
    $line = '            ';
    foreach ($d['ids'] as $i => $id) {
        $piece = "'" . $id . "', ";
        if (strlen($line) + strlen($piece) > 116) {
            $block[] = rtrim($line);
            $line = '            ';
        }
        $line .= $piece;
    }
    if (trim($line) !== '') {
        $block[] = rtrim($line, ', ');
    }
    $block[] = '        ),';
    $block[] = '        // Human readable names, for operator output only. Not used to query.';
    $block[] = '        \'names\' => array(';
    foreach ($d['names'] as $id => $name) {
        $block[] = "            '$id' => '" . str_replace("'", "\\'", $name) . "',";
    }
    $block[] = '        ),';
    $block[] = '    ),';
    $block[] = '';
}
$block[] = ');';
$content = implode("\n", $block) . "\n";

if (!$write) {
    echo "\n--- derived ids (dry run) ---\n";
    foreach ($derived as $group => $d) {
        printf("%-9s %s\n", $group, implode(',', array_slice($d['ids'], 0, 12)) . ' …');
    }
    printf("\nfile would be %d bytes; re-run with --write\n", strlen($content));
    exit(0);
}

$target = \SatApi\Config::path('conf/tle-groups.php');
if (is_file($target)) {
    $backup = $target . '.' . gmdate('Ymd-His') . '.bak';
    copy($target, $backup);
    echo "backed up -> $backup\n";
}
file_put_contents($target, $content);
printf("\nwrote %s (%d bytes, %d groups, %d ids total)\n", $target, strlen($content),
    count($derived), array_sum(array_map(static function ($d) {
        return count($d['ids']);
    }, $derived)));
