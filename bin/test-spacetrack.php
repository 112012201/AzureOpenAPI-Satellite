<?php
/**
 * 楠岃瘉 SpaceTrack 鎺ュ叆閾捐矾锛堝彧璇伙紝涓嶅啓缂撳瓨锛夈€? *   SourceMerge::fetchViaSpacetrack -> Parser::parseText -> 璁板綍瀛楁瀹屾暣鎬? */
require __DIR__ . '/../bootstrap.php';

use SatApi\Data\SourceMerge;
use SatApi\Data\Repository;
use SatApi\Tle\Parser;

$t0 = microtime(true);
echo "=== A. group list\n";
foreach (array('weather', 'amateur') as $g) {
    $ids = SourceMerge::groupIds($g);
    printf("  %-9s ids=%-4d first=%s\n", $g, count($ids), $ids === array() ? '-' : implode(',', array_slice($ids, 0, 5)));
}

echo "\n=== B. primary / fallback mapping\n";
foreach (array('weather', 'amateur', 'amsat', 'satnogs') as $s) {
    printf("  %-9s primary=%-12s fallback=%-12s cooldown=%s\n", $s,
        var_export(SourceMerge::primaryProvider($s), true),
        var_export(SourceMerge::fallbackProvider($s), true),
        SourceMerge::inCooldown($s) ? 'yes(' . SourceMerge::remainingCooldown($s) . 's)' : 'no');
}

echo "\n=== C. SpaceTrack fetch for weather\n";
$res = SourceMerge::fetchViaSpacetrack('weather', array('SatApi\\Data\\Repository', 'parseBody'));
printf("  ok=%s error=%s\n", var_export($res['ok'], true), $res['error']);
printf("  detail=%s\n", json_encode($res['detail']));
$records = $res['records'];
printf("  records=%d\n", count($records));
if ($records !== array()) {
    $first = reset($records);
    printf("  first=%s\n", json_encode(array(
        'norad_id'  => $first['norad_id'] ?? null,
        'name'      => $first['name'] ?? null,
        'epoch_iso' => $first['epoch_iso'] ?? null,
        'epoch_unix' => $first['epoch_unix'] ?? null,
        'line1_len' => isset($first['line1']) ? strlen((string) $first['line1']) : null,
        'line2_len' => isset($first['line2']) ? strlen((string) $first['line2']) : null,
        'checksum_ok' => $first['checksum_ok'] ?? null,
    )));
    // Completeness gate
    $bad = 0; $noName = 0; $noEpoch = 0;
    foreach ($records as $r) {
        if (strlen((string) ($r['line1'] ?? '')) !== 69 || strlen((string) ($r['line2'] ?? '')) !== 69) {
            $bad++;
        }
        if ((string) ($r['name'] ?? '') === '') {
            $noName++;
        }
        if ((int) ($r['epoch_unix'] ?? 0) <= 0) {
            $noEpoch++;
        }
    }
    printf("  malformed=%d missingName=%d missingEpoch=%d\n", $bad, $noName, $noEpoch);
    $expected = count(SourceMerge::groupIds('weather'));
    printf("  coverage=%d/%d (%.0f%%)\n", count($records), $expected, $expected ? count($records) / $expected * 100 : 0);
    $epochs = array();
    foreach ($records as $r) {
        if ((int) ($r['epoch_unix'] ?? 0) > 0) { $epochs[] = (int) $r['epoch_unix']; }
    }
    sort($epochs);
    printf("  epoch p50=%s newest=%s\n",
        $epochs ? gmdate('m-d H:i', $epochs[(int) (count($epochs) / 2)]) : '-',
        $epochs ? gmdate('m-d H:i', end($epochs)) : '-');
}

echo "\n=== D. 閲囨牱 3 鏉″師濮?3le 琛屼互纭鍚嶇О琛岃В鏋怽n";
echo "  锛堢敱 Parser 澶勭悊锛涗笂闈?missingName 涓?0 鍗宠〃绀哄悕绉拌琚纭崟鑾凤級\n";

printf("\nelapsed %.1fs\n", microtime(true) - $t0);
