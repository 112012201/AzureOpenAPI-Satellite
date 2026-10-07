<?php

namespace SatApi;

use SatApi\Cache\Store;
use SatApi\Data\Repository;
use SatApi\Support\Maintenance;
use SatApi\Support\Metrics;

/**
 * CLI entry point: cache warmup, health self-check and cache maintenance.
 *
 * Usage:
 *   php index.php refresh [--target=tle|weather|amateur|amsat|satnogs|satellites|transmitters|amsat-status]
 *   php index.php warmup
 *   php index.php health [--json]
 *   php index.php prune
 *   php index.php maintenance --enable=<endpoint>|--disable=<endpoint> [--message=...] [--ttl=3600]
 *
 * The historical `php tle_merge.php` / `php xxx.php refresh` invocations keep
 * working: the legacy shims forward to this runner.
 */
final class Cli
{
    public static function run(array $argv)
    {
        $command = isset($argv[1]) ? (string) $argv[1] : 'help';
        $options = self::parseOptions(array_slice($argv, 2));

        // Legacy invocation: `php tle_weather.php refresh`
        if (defined('SATAPI_LEGACY_ROUTE')) {
            $command = $command === 'refresh' || $command === 'warmup' ? 'refresh' : $command;
            if (!isset($options['target'])) {
                $options['target'] = self::targetForLegacyRoute(SATAPI_LEGACY_ROUTE);
            }
        }

        switch ($command) {
            case 'refresh':
            case 'warmup':
                return self::refresh($options);
            case 'health':
                return self::health($options);
            case 'prune':
                return self::prune($options);
            case 'maintenance':
                return self::maintenance($options);
            case 'help':
            case '--help':
            case '-h':
            default:
                return self::help();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function parseOptions(array $args)
    {
        $options = array('targets' => array());
        foreach ($args as $arg) {
            if (strpos($arg, '--') !== 0) {
                continue;
            }
            $arg = substr($arg, 2);
            $value = true;
            if (strpos($arg, '=') !== false) {
                list($arg, $value) = explode('=', $arg, 2);
            }
            if ($arg === 'target' || $arg === 'targets') {
                foreach (explode(',', (string) $value) as $target) {
                    $target = trim($target);
                    if ($target !== '') {
                        $options['targets'][] = $target;
                    }
                }
                $options['target'] = $value;
                continue;
            }
            $options[$arg] = $value;
        }

        return $options;
    }

    private static function targetForLegacyRoute($route)
    {
        $map = array(
            'tle_merge'            => 'tle',
            'tle_weather'          => 'weather',
            'tle_amateur'          => 'amateur',
            'tle_amsat'            => 'amsat',
            'tle_satnogs'          => 'satnogs',
            'satnogs_satellites'   => 'satellites',
            'satnogs_transmitters' => 'transmitters',
            'amsat_status'         => 'amsat-status',
            'health'               => 'health',
            'status'               => 'health',
        );

        return isset($map[$route]) ? $map[$route] : 'tle';
    }

    private static function refresh(array $options)
    {
        $targets = !empty($options['targets']) ? $options['targets'] : array('tle', 'satellites', 'transmitters', 'amsat-status');
        if (!empty($options['target']) && !is_array($options['target']) && $options['target'] !== true) {
            $targets = array();
            foreach (explode(',', (string) $options['target']) as $target) {
                $targets[] = trim($target);
            }
        }
        $targets = array_values(array_filter($targets, 'strlen'));

        $tleIds = array();
        $dataSets = array();
        foreach ($targets as $target) {
            switch ($target) {
                case 'tle':
                case 'all':
                    $tleIds = array_merge($tleIds, Repository::TLE_SOURCES);
                    break;
                case 'weather':
                case 'amateur':
                case 'amsat':
                case 'satnogs':
                    $tleIds[] = $target;
                    break;
                case 'satellites':
                    $dataSets[] = 'satnogs_satellites';
                    break;
                case 'transmitters':
                    $dataSets[] = 'satnogs_transmitters';
                    break;
                case 'amsat-status':
                case 'amsat_status':
                    $dataSets[] = 'amsat_status';
                    break;
                case 'health':
                    break;
                default:
                    self::log('Unknown target "' . $target . '", skipping.');
            }
        }

        $report = array('started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'tle' => array(), 'datasets' => array());
        $failed = 0;

        if (!empty($tleIds)) {
            $summary = Repository::refresh(array_values(array_unique($tleIds)));
            foreach ($summary as $id => $result) {
                $report['tle'][$id] = $result;
                self::log(sprintf('  tle/%-9s %-4s %6d records  %5d ms  %s', $id, $result['ok'] ? 'ok' : 'FAIL', $result['count'], $result['ms'], $result['error']));
                if (!$result['ok']) {
                    $failed++;
                }
            }
        }

        foreach ($dataSets as $datasetId) {
            $started = microtime(true);
            if ($datasetId === 'amsat_status') {
                $result = Repository::amsatReports(true);
            } else {
                $result = Repository::dataset($datasetId, true);
            }
            $count = is_array($result['data']) ? count($result['data']) : 0;
            $ok = $count > 0 && empty($result['error']);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $report['datasets'][$datasetId] = array('ok' => $ok, 'count' => $count, 'error' => $result['error'], 'ms' => $ms);
            self::log(sprintf('  %-22s %-4s %6d records  %5d ms  %s', $datasetId, $ok ? 'ok' : 'FAIL', $count, $ms, $result['error']));
            if (!$ok) {
                $failed++;
            }
        }

        $report['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $report['failed'] = $failed;

        if (!empty($options['json'])) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        Metrics::flush();

        return $failed === 0 ? 0 : 1;
    }

    private static function health(array $options)
    {
        $health = \SatApi\Api\Endpoints\System::collect();
        if (!empty($options['json'])) {
            echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            self::log('overall: ' . $health['overall']);
            self::log('redis:   ' . $health['checks']['redis']);
            foreach ($health['checks']['cache_files'] as $name => $entry) {
                self::log(sprintf(
                    '  %-22s %-8s age=%-12s size=%s',
                    $name,
                    $entry['exists'] ? ($entry['fresh'] ? 'fresh' : 'stale') : 'missing',
                    $entry['exists'] ? $entry['age_human'] : '-',
                    $entry['exists'] ? $entry['size'] . 'B' : '-'
                ));
            }
        }

        return $health['overall'] === 'critical' ? 1 : 0;
    }

    private static function prune(array $options)
    {
        $removed = 0;
        $dir = (string) Config::get('ratelimit.dir');
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*.json') as $file) {
                if (is_file($file) && filemtime($file) < time() - 7200) {
                    @unlink($file);
                    $removed++;
                }
            }
        }

        $logDir = (string) Config::get('logs.dir');
        if (is_dir($logDir)) {
            foreach ((array) glob($logDir . '/*.log.[0-9]') as $file) {
                if (is_file($file) && filemtime($file) < time() - 30 * 86400) {
                    @unlink($file);
                    $removed++;
                }
            }
        }

        self::log('pruned ' . $removed . ' stale files');

        return 0;
    }

    private static function maintenance(array $options)
    {
        $endpoint = null;
        $enabled = null;
        if (isset($options['enable'])) {
            $endpoint = (string) $options['enable'];
            $enabled = true;
        } elseif (isset($options['disable'])) {
            $endpoint = (string) $options['disable'];
            $enabled = false;
        }

        if ($endpoint === null || $enabled === null) {
            self::log('usage: php index.php maintenance --enable=<endpoint>|--disable=<endpoint> [--message=...] [--ttl=3600]');
            self::log('endpoints: all, ' . implode(', ', Maintenance::ENDPOINTS));

            return 2;
        }

        // `all` expands to every known endpoint (same contract as the HTTP API).
        $targets = strtolower($endpoint) === 'all' ? Maintenance::ENDPOINTS : array($endpoint);
        $invalid = array();
        foreach ($targets as $target) {
            if (!Maintenance::isKnown($target)) {
                $invalid[] = $target;
            }
        }
        if (!empty($invalid)) {
            self::log('FAIL: unknown endpoint(s): ' . implode(', ', $invalid));
            self::log('endpoints: all, ' . implode(', ', Maintenance::ENDPOINTS));

            return 2;
        }

        $message = isset($options['message']) ? (string) $options['message'] : '';
        $ttl = isset($options['ttl']) ? (int) $options['ttl'] : 3600;

        $failed = 0;
        foreach ($targets as $target) {
            $ok = Maintenance::set($target, $enabled, $message, $ttl);
            if (!$ok) {
                $failed++;
            }
            self::log(($ok ? 'ok  ' : 'FAIL') . ': ' . $target . ' maintenance=' . ($enabled ? 'on' : 'off'));
        }

        if (count($targets) > 1) {
            self::log(($failed === 0 ? 'ok' : 'FAIL') . ': ' . count($targets) . ' endpoint(s), ' . $failed . ' failed');
        }

        return $failed === 0 ? 0 : 1;
    }

    private static function help()
    {
        self::log('Satellite Data API CLI');
        self::log('  php index.php refresh [--target=tle,satellites,...] [--json]');
        self::log('  php index.php health [--json]');
        self::log('  php index.php prune');
        self::log('  php index.php maintenance --enable=<endpoint>|--disable=<endpoint|all> [--message=...] [--ttl=3600]');
        self::log('cache backend: ' . Store::backendName());

        return 0;
    }

    private static function log($line)
    {
        $text = '[' . gmdate('H:i:s') . '] ' . $line . "\n";
        // STDOUT is not defined in every CLI-ish SAPI (e.g. the embedded one).
        if (defined('STDOUT')) {
            fwrite(STDOUT, $text);

            return;
        }
        echo $text;
    }
}
