<?php

namespace SatApi;

use SatApi\Cache\Store;
use SatApi\Data\Repository;
use SatApi\Support\AnnouncementBoard;
use SatApi\Support\Maintenance;
use SatApi\Support\Metrics;

/**
 * CLI entry point: cache warmup, health self-check and cache maintenance.
 *
 * Usage:
 *   php index.php refresh [--target=tle|weather|amateur|amsat|satnogs|satellites|transmitters|amsat-status|all]
 *   php index.php reload  [--target=...]   (same targets; ignores TTL and overwrites)
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
            case 'reload':
                return self::reload($options);
            case 'health':
                return self::health($options);
            case 'prune':
                return self::prune($options);
            case 'maintenance':
                return self::maintenance($options);
            case 'announcements':
            case 'announcement':
            case 'announce':
                return self::announcements($options);
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
                    $tleIds = array_merge($tleIds, Repository::TLE_SOURCES);
                    break;
                case 'all':
                    // `all` used to fall through to the `tle` case, so a "refresh
                    // everything" silently left satellites, transmitters and the
                    // AMSAT status page on whatever old copy they already had. It now
                    // means every dataset, which is what the word promises.
                    $tleIds = array_merge($tleIds, Repository::TLE_SOURCES);
                    $dataSets = array_merge($dataSets, array('satnogs_satellites', 'satnogs_transmitters', 'amsat_status'));
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

    /**
     * Hot reload: re-pull every source and OVERWRITE the cached copy.
     *
     * Distinct from `refresh` in one way that matters operationally: `refresh`
     * skips a source whose cache is still fresh, so running it after a bad deploy
     * is a no-op. `reload` ignores TTL by construction (Repository::reloadAll) and
     * rebuilds the prepared snapshot from the new data.
     *
     * @return int exit code
     */
    private static function reload(array $options)
    {
        $only = self::datasetIds(self::targetList($options));

        // An unresolvable target must NOT fall through to "reload everything".
        // Repository::reloadAll(array()) means all by contract, so a typo reaching it
        // turned `--target=bogus` into a full seven-source sweep -- measured: it ran a
        // 24s reload against every upstream. Failing here makes the typo cost nothing.
        if ($only === array()) {
            self::log('FAIL: no valid target.');
            self::log('targets: all, ' . implode(', ', Repository::DATASETS));

            return 2;
        }

        $report = Repository::reloadAll($only);

        if (!empty($report['locked'])) {
            self::log('FAIL: ' . $report['error']);

            return 1;
        }

        foreach ((array) (isset($report['datasets']) ? $report['datasets'] : array()) as $id => $entry) {
            self::log(sprintf(
                '  %-22s %-4s %6d records  %5d ms  written=%-7s %s',
                $id,
                !empty($entry['ok']) ? 'ok' : 'FAIL',
                isset($entry['count']) ? (int) $entry['count'] : 0,
                isset($entry['ms']) ? (int) $entry['ms'] : 0,
                isset($entry['written']) && $entry['written'] !== '' ? $entry['written'] : '-',
                isset($entry['error']) ? (string) $entry['error'] : ''
            ));
        }

        if (isset($report['prepared']) && is_array($report['prepared'])) {
            $stats = $report['prepared'];
            self::log(sprintf(
                '  preprocessed: %d in, %d out, %d merged, %d dropped, %d completed, %d checksum failures (%d ms)',
                isset($stats['input_records']) ? $stats['input_records'] : 0,
                isset($report['merged_count']) ? $report['merged_count'] : 0,
                isset($stats['duplicates_merged']) ? $stats['duplicates_merged'] : 0,
                (isset($stats['dropped_invalid']) ? $stats['dropped_invalid'] : 0)
                    + (isset($stats['dropped_norad']) ? $stats['dropped_norad'] : 0),
                isset($stats['norad_completed']) ? $stats['norad_completed'] : 0,
                isset($stats['checksum_failed']) ? $stats['checksum_failed'] : 0,
                isset($report['elapsed_ms']) ? $report['elapsed_ms'] : 0
            ));
        }

        if (!empty($report['time_limit_reached'])) {
            self::log('WARN: time limit reached; skipped ' . implode(', ', (array) (isset($report['skipped']) ? $report['skipped'] : array())));
        }

        self::log(($report['ok'] ? 'ok' : 'FAIL') . ': reload finished in '
            . (isset($report['elapsed_ms']) ? (int) $report['elapsed_ms'] : 0) . ' ms, '
            . (isset($report['failed']) ? (int) $report['failed'] : 0) . ' failed');

        if (!empty($options['json'])) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        Metrics::flush();

        return $report['ok'] ? 0 : 1;
    }

    /**
     * @return string[]
     */
    private static function targetList(array $options)
    {
        $targets = !empty($options['targets']) ? $options['targets'] : array('all');
        if (!empty($options['target']) && !is_array($options['target']) && $options['target'] !== true) {
            $targets = array();
            foreach (explode(',', (string) $options['target']) as $target) {
                $targets[] = trim($target);
            }
        }

        return array_values(array_filter($targets, 'strlen'));
    }

    /**
     * Translate CLI target names to the dataset ids Repository knows.
     *
     * @param string[] $targets
     * @return string[]
     */
    private static function datasetIds(array $targets)
    {
        $map = array(
            'weather'            => array('weather'),
            'amateur'            => array('amateur'),
            'amsat'              => array('amsat'),
            'satnogs'            => array('satnogs'),
            'satellites'         => array('satnogs_satellites'),
            'satnogs_satellites' => array('satnogs_satellites'),
            'transmitters'       => array('satnogs_transmitters'),
            'satnogs_transmitters' => array('satnogs_transmitters'),
            'amsat-status'       => array('amsat_status'),
            'amsat_status'       => array('amsat_status'),
        );

        $out = array();
        foreach ($targets as $target) {
            $target = strtolower(trim((string) $target));
            if ($target === 'all' || $target === '') {
                return Repository::DATASETS;
            }
            if ($target === 'tle') {
                $out = array_merge($out, Repository::TLE_SOURCES);
                continue;
            }
            if (isset($map[$target])) {
                $out = array_merge($out, $map[$target]);
                continue;
            }
            self::log('Unknown target "' . $target . '", skipping.');
        }

        return array_values(array_unique($out));
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

    /**
     * Rebuild the announcement board from content/announcements/*.md.
     *
     * The only supported way to publish: the operator edits a Markdown file, runs
     * this, and the compiled artifact the request path reads is replaced atomically.
     * `--check` validates without writing, which is what a deploy should run first.
     * Everything else (title, date, author, level, lang, refusable, expires) lives in
     * the file's front matter -- there is no second place to keep it in sync.
     *
     * `lang` is printed next to the id because a notice published in two languages is
     * two files with ONE id, and an operator looking at this report needs to see that
     * both variants made it in.
     */
    private static function announcements(array $options)
    {
        $check = !empty($options['check']);
        $force = !empty($options['force']);
        $dir = isset($options['source']) && is_string($options['source']) ? (string) $options['source'] : null;
        $report = AnnouncementBoard::compile($dir, $check, null, $force);

        if (!empty($options['json'])) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            return empty($report['errors']) ? 0 : 1;
        }

        self::log($check ? 'announcements: check only, nothing written' : 'announcements: rebuild');
        self::log('  source  ' . $report['source']);
        self::log('  target  ' . $report['target'] . ($report['written'] ? '  (written)' : ''));
        self::log('  files   ' . $report['files'] . '   compiled ' . $report['compiled'] . '   skipped ' . $report['skipped']);

        // Say which ones visitors will actually see, and which are waiting. Without
        // this a scheduled notice looks published because it is in the artifact.
        $live = array();
        foreach (AnnouncementBoard::select($report['items']) as $item) {
            $live[(string) $item['id']] = true;
        }
        $now = time();
        foreach ($report['items'] as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            if (isset($live[(string) $item['id']])) {
                $state = 'live';
            } elseif (!empty($item['starts_at']) && (int) $item['starts_at'] > $now) {
                $state = 'scheduled ' . $item['date'];
            } else {
                $state = 'expired ' . (isset($item['expires']) ? $item['expires'] : '');
            }
            self::log(sprintf(
                '  %-9s %-8s %-10s %s',
                '[' . (isset($item['level']) ? $item['level'] : '?') . ']',
                $state,
                isset($item['date']) ? $item['date'] : '',
                isset($item['title']) ? $item['title'] : ''
            ));
            self::log(sprintf(
                '  %-9s %-8s %-10s %s',
                '',
                '',
                '',
                $item['id'] . '  ' . (isset($item['lang']) ? $item['lang'] : 'all')
                    . (empty($item['refusable']) ? '' : '  (refusable)')
            ));
        }

        foreach ($report['warnings'] as $warning) {
            self::log('  WARN   ' . $warning);
        }
        foreach ($report['errors'] as $error) {
            self::log('  ERROR  ' . $error);
        }

        if (empty($report['errors'])) {
            self::log($check ? 'ok: the board validates' : 'ok: the board is published');
        } else {
            self::log('FAILED: ' . count($report['errors']) . ' error(s), nothing was published');
        }

        return empty($report['errors']) ? 0 : 1;
    }

    private static function help()
    {
        self::log((string) Config::get('service.name', 'API') . ' CLI v' . (string) Config::get('service.version', ''));
        self::log('  php index.php refresh [--target=tle,satellites,...|all] [--json]');
        self::log('  php index.php reload  [--target=all|tle|weather|...] [--json]   (ignore TTL, overwrite)');
        self::log('  php index.php health [--json]');
        self::log('  php index.php prune');
        self::log('  php index.php maintenance --enable=<endpoint>|--disable=<endpoint|all> [--message=...] [--ttl=3600]');
        self::log('  php index.php announcements [--check] [--force] [--source=dir] [--json]');
        self::log('     publishes content/announcements/*.md as the site announcement board');
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
