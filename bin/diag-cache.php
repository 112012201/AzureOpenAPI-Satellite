<?php
/**
 * 诊断：文件缓存逐个列出（为什么 weather 快照没被识别）。
 */
require __DIR__ . '/../bootstrap.php';
use SatApi\Cache\Store;

$files = (array) glob(\SatApi\Config::path('data/cache/*.cache'));
printf("cache dir: %s\n", \SatApi\Config::path('data/cache'));
printf("files    : %d\n\n", count($files));

foreach ($files as $f) {
    $raw = @file_get_contents($f);
    $size = strlen((string) $raw);
    $env = is_string($raw) ? strncmp($raw, Store::ENVELOPE, strlen(Store::ENVELOPE)) === 0 : false;
    $codec = '?';
    $count = 0;
    $firstKeys = '';
    $looks = '';
    if ($env) {
        $rest = substr($raw, strlen(Store::ENVELOPE));
        $b = strpos($rest, "\n");
        $meta = $b === false ? null : json_decode(substr($rest, 0, $b), true);
        $codec = is_array($meta) ? (string) ($meta['codec'] ?? '?') : '?';
        $payload = $b === false ? '' : substr($rest, $b + 1);
        if ($codec === 'serialize') {
            $recs = @unserialize($payload, array('allowed_classes' => false));
            if (is_array($recs)) {
                $count = count($recs);
                $first = reset($recs);
                if (is_array($first)) {
                    $firstKeys = implode(',', array_slice(array_keys($first), 0, 6));
                    $looks = (array_key_exists('norad_id', $first) ? 'TLE' : '')
                        . (array_key_exists('sat_id', $first) ? 'SatNOGS' : '')
                        . (array_key_exists('uuid', $first) ? 'Transmitters' : '')
                        . (array_key_exists('callsign', $first) ? 'AmsatStatus' : '');
                } else {
                    $looks = 'scalar:' . gettype($first);
                }
            } else {
                $looks = 'unserialize-failed';
            }
        } else {
            $looks = 'payload-codec-' . $codec . ' len=' . strlen($payload);
        }
    } else {
        $looks = 'no-envelope first16=' . bin2hex(substr((string) $raw, 0, 16));
    }
    printf("%-68s %7dB env=%-5s codec=%-10s n=%-5d %-22s %s\n",
        basename($f), $size, $env ? 'yes' : 'no', $codec, $count, $looks, $firstKeys);
}
