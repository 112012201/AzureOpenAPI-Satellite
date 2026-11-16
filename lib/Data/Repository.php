<?php

namespace SatApi\Data;

use SatApi\Cache\Lock;
use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Http\Client;
use SatApi\Logger;
use SatApi\Support\Metrics;
use SatApi\Tle\Parser;

/**
 * Single entry point for every data set the API exposes.
 *
 * All caching, upstream fetching and parsing lives here so endpoints only
 * deal with presentation. Compared with the previous code base:
 *   - one implementation instead of four copy-pasted fetch/cache blocks,
 *   - stale-while-revalidate instead of "must block on an expired cache",
 *   - parallel upstream fetch for the merged TLE feed,
 *   - parsed data sets are cached (serialize) so big JSON payloads are not
 *     re-decoded on every request.
 */
final class Repository
{
    const TLE_SOURCES = array('weather', 'amateur', 'amsat', 'satnogs');

    /** @var array<string,string> */
    private static $fetchFailures = array();

    /** @var array<string,array> */
    private static $results = array();

    /**
     * Cache keys, used by the health dashboard.
     *
     * @return array<string,string>
     */
    public static function cacheKeys()
    {
        return array(
            'tle_weather'            => 'dataset:tle:weather',
            'tle_amateur'            => 'dataset:tle:amateur',
            'tle_amsat'              => 'dataset:tle:amsat',
            'tle_satnogs'            => 'dataset:tle:satnogs',
            'satnogs_satellites'     => 'dataset:satnogs_satellites',
            'satnogs_transmitters'   => 'dataset:satnogs_transmitters',
            'amsat_status'           => 'dataset:amsat_status',
        );
    }

    /**
     * Cached record set for one TLE source.
     *
     * @return array{data:array<string,array>,cache:array}
     */
    public static function sourceRecords($id, $force = false)
    {
        if (!$force && isset(self::$results[$id])) {
            return self::$results[$id];
        }

        $source = Config::source($id);
        if ($source === null) {
            return array('data' => array(), 'cache' => array('status' => 'UNKNOWN', 'age' => 0, 'ttl' => 0, 'backend' => ''), 'error' => 'unknown source');
        }

        if (!$force && isset(self::$fetchFailures[$id])) {
            // A parallel prefetch already failed; do not hit the network twice.
            $stale = Store::read(self::key($id), 'serialize');
            $result = array(
                'data'  => $stale !== null ? $stale['value'] : array(),
                'cache' => array(
                    'status'    => $stale !== null ? 'STALE' : 'EXPIRED',
                    'age'       => $stale !== null ? $stale['age'] : 0,
                    'ttl'       => (int) $source['ttl'],
                    'backend'   => $stale !== null ? $stale['backend'] : '',
                    // Preserved so Last-Modified / ETag stay meaningful while
                    // an outage is being served from the stale copy.
                    'stored_at' => $stale !== null ? $stale['stored_at'] : 0,
                ),
                'error' => self::$fetchFailures[$id],
            );
            self::$results[$id] = $result;

            return $result;
        }

        try {
            $entry = Store::remember(
                self::key($id),
                (int) $source['ttl'],
                static function () use ($id, $source) {
                    // ---- primary provider (CelesTrak for weather/amateur) -----
                    $primaryRecords = null;
                    $primaryError = '';

                    if (!SourceMerge::inCooldown($id)) {
                        $result = Client::get($source['url'], array('accept' => isset($source['accept']) ? $source['accept'] : ''));
                        Metrics::upstream($id, $result['ok'], $result['ms'], $result['error']);

                        if ($result['ok']) {
                            $parsed = self::parse($id, $source, $result['body']);
                            if (!empty($parsed)) {
                                // A source that suddenly returns a fraction of its
                                // usual count is damaged, not authoritative.
                                if (SourceMerge::looksTruncated($parsed, SourceMerge::lastCount($id))) {
                                    $primaryError = 'primary returned suspiciously few records ('
                                        . count($parsed) . ' vs previous ' . SourceMerge::lastCount($id) . ')';
                                    Logger::warning('source_truncated', array(
                                        'source' => $id,
                                        'got'    => count($parsed),
                                        'before' => SourceMerge::lastCount($id),
                                    ));
                                } else {
                                    $primaryRecords = $parsed;
                                }
                            } else {
                                $primaryError = 'upstream returned no parseable TLE records';
                            }
                        } else {
                            $primaryError = 'upstream unavailable: ' . $result['error'];
                            // CelesTrak answers 403 with its "excessive downloads"
                            // notice, which self-heals after two quiet hours, and it
                            // later stopped answering this IP at all (a connect
                            // timeout). Both mean "stop trying for a while": retrying
                            // inside the window extends the block, and past it every
                            // run pays the full connect timeout for nothing. The
                            // decision is made from the structured status rather than
                            // by matching digits in the message.
                            $verdict = SourceMerge::classifyPrimaryFailure($result);
                            if ($verdict[0]) {
                                SourceMerge::startCooldown(
                                    $id,
                                    $verdict[1] > 0 ? $verdict[1] : null,
                                    (string) $result['error']
                                );
                            }
                        }
                    } else {
                        $primaryError = 'primary in cooldown for another '
                            . SourceMerge::remainingCooldown($id) . 's';
                    }

                    if ($primaryRecords !== null) {
                        // Complement only: the primary stays authoritative for every
                        // id it returned, and the fallback fills genuine gaps.
                        $merged = SourceMerge::complementMissing($id, $primaryRecords, array(__CLASS__, 'parseBody'));
                        if (!empty($merged['added'])) {
                            SourceMerge::rememberCount($id, count($merged['records']));

                            return $merged['records'];
                        }
                        SourceMerge::rememberCount($id, count($primaryRecords));

                        return $primaryRecords;
                    }

                    // ---- fallback provider (SpaceTrack) ----------------------
                    if (SourceMerge::fallbackProvider($id) === 'spacetrack') {
                        $fb = SourceMerge::fetchViaSpacetrack($id, array(__CLASS__, 'parseBody'));
                        if ($fb['ok']) {
                            Logger::info('source_fallback_used', array(
                                'source'   => $id,
                                'provider' => 'spacetrack',
                                'primary'  => $primaryError,
                                'returned' => count($fb['records']),
                                'detail'   => $fb['detail'],
                            ));
                            SourceMerge::rememberCount($id, count($fb['records']));

                            return $fb['records'];
                        }
                        throw new \RuntimeException(
                            'primary: ' . $primaryError . ' | fallback spacetrack: ' . $fb['error']
                        );
                    }

                    throw new \RuntimeException($primaryError === '' ? 'upstream unavailable' : $primaryError);
                },
                array('codec' => 'serialize', 'force' => $force)
            );
        } catch (\Throwable $e) {
            // No cache and the upstream failed: degrade to an empty data set so
            // the endpoint can answer with a proper 503 problem document.
            $result = self::failureResult(self::key($id), (int) $source['ttl'], $e->getMessage());
            self::$results[$id] = $result;

            return $result;
        }

        $result = array(
            'data'  => $entry['value'],
            'cache' => array(
                'status'     => $entry['status'],
                'age'        => $entry['age'],
                'ttl'        => $entry['ttl'],
                'backend'    => $entry['backend'],
                'stored_at'  => isset($entry['stored_at']) ? $entry['stored_at'] : time() - (int) $entry['age'],
            ),
            'error'   => isset($entry['error']) ? $entry['error'] : '',
            'refresh' => isset($entry['refresh']) ? $entry['refresh'] : null,
        );
        self::$results[$id] = $result;

        return $result;
    }

    private static function key($id)
    {
        return 'dataset:tle:' . $id;
    }

    /**
     * Graceful "no data" result: keeps the stale copy (if any) and records why
     * the refresh failed, so endpoints can emit 503 + problem+json instead of
     * letting an exception escape.
     *
     * @return array{data:array,cache:array,error:string,refresh:null}
     */
    private static function failureResult($cacheKey, $ttl, $message)
    {
        $stale = Store::read($cacheKey, 'serialize');
        Logger::warning('dataset_unavailable', array('key' => $cacheKey, 'error' => $message));

        return array(
            'data'    => $stale !== null ? $stale['value'] : array(),
            'cache'   => array(
                'status'    => $stale !== null ? 'STALE' : 'EXPIRED',
                'age'       => $stale !== null ? $stale['age'] : 0,
                'ttl'       => (int) $ttl,
                'backend'   => $stale !== null ? $stale['backend'] : '',
                'stored_at' => $stale !== null ? $stale['stored_at'] : 0,
            ),
            'error'   => $message,
            'refresh' => null,
        );
    }

    private static function parse($id, array $source, $body)
    {
        $parser = isset($source['parser']) ? $source['parser'] : 'tle-text';
        switch ($parser) {
            case 'satnogs-json':
                return Parser::parseSatnogsJson($body, $id);
            case 'tle-text':
            default:
                $records = Parser::parseText($body, $id);
                if (empty($records)) {
                    // Some mirrors serve the flattened one-line AMSAT variant.
                    $records = Parser::parseFlattened($body, $id);
                }

                return $records;
        }
    }

    /**
     * Parse a TLE text body for a source, using that source's configured parser.
     *
     * Exposed so the fallback provider path (SourceMerge -> SpaceTrack) can reuse
     * exactly the same parsing rules as the primary path. A fallback that parsed
     * differently would be a silent source of shape drift.
     *
     * @param string $body
     * @param string $sourceId
     * @return array<int,array>
     */
    public static function parseBody($body, $sourceId)
    {
        $source = Config::source($sourceId);
        if ($source === null) {
            return array();
        }

        return self::parse($sourceId, $source, $body);
    }

    /**
     * Fetch several sources at once when their cache is cold or expired.
     *
     * @param string[] $ids
     */
    public static function prefetch(array $ids)
    {
        self::fetchInto($ids, false);
    }

    /**
     * Force a refresh regardless of cache freshness (CLI / warmup).
     *
     * @param string[] $ids
     * @return array<string,array> id => ['ok'=>bool,'count'=>int,'error'=>string,'ms'=>int]
     */
    public static function refresh(array $ids)
    {
        return self::fetchInto($ids, true);
    }

    /**
     * @param string[] $ids
     * @param bool     $ignoreCache
     * @return array<string,array>
     */
    private static function fetchInto(array $ids, $ignoreCache)
    {
        $summary = array();
        $missing = array();
        foreach ($ids as $id) {
            $source = Config::source($id);
            if ($source === null) {
                $summary[$id] = array('ok' => false, 'count' => 0, 'error' => 'unknown source', 'ms' => 0);
                continue;
            }
            $entry = $ignoreCache ? null : Store::read(self::key($id), 'serialize');
            $ttl = (int) $source['ttl'];
            if ($entry === null || $entry['age'] >= $ttl) {
                $missing[$id] = $source['url'];
                continue;
            }
            $summary[$id] = array('ok' => true, 'count' => count((array) $entry['value']), 'error' => '', 'ms' => 0, 'cache' => $entry['status']);
        }

        if (empty($missing)) {
            return $summary;
        }

        // Fetch each missing dataset through the SAME path the site serves from.
        //
        // This used to call Client::getMany() directly against $source['url'] and then
        // write the cache itself, which made the warmup disagree with the request path
        // in three ways that all mattered:
        //
        //   * no primary/fallback. When CelesTrak was blocked the cron reported
        //     "FAIL 0 records" even though the request path was serving 72 records from
        //     SpaceTrack, so the only operational signal said the dataset was dead;
        //   * no cooldown. A blocked source was retried on every run, paying the full
        //     connect timeout each time.
        //   * a hardcoded URL means a url change in configuration never took effect on
        //     the cron path, which is exactly the kind of split-brain a warmup must not
        //     have.
        //
        // The cost is that the four TLE sets are fetched sequentially instead of in one
        // parallel batch. That is a deliberate trade: correctness of the operational
        // signal beats a few seconds of wall clock in a job that runs every six hours.
        foreach (array_keys($missing) as $id) {
            $started = microtime(true);
            $result = self::source($id, true);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $count = count((array) (isset($result['data']) ? $result['data'] : array()));
            $error = isset($result['error']) ? (string) $result['error'] : '';

            if ($count === 0) {
                $summary[$id] = array(
                    'ok'      => false,
                    'count'   => 0,
                    'error'   => $error === '' ? 'no records resolved' : $error,
                    'ms'      => $ms,
                    'cache'   => isset($result['cache']['status']) ? $result['cache']['status'] : '',
                );
                continue;
            }

            $summary[$id] = array(
                'ok'    => true,
                'count' => $count,
                'error' => '',
                'ms'    => $ms,
                'cache' => isset($result['cache']['status']) ? $result['cache']['status'] : '',
            );
        }

        return $summary;
    }

    /**
     * Merged, de-duplicated TLE feed.
     *
     * @return array{data:array<string,array>,sources:array<string,array>,warnings:string[]}
     */
    public static function merged($force = false)
    {
        self::prefetch(self::TLE_SOURCES);

        $datasets = array();
        $sources = array();
        $refresh = array();
        $warnings = array();
        // Per-source `stored_at`, used to derive the snapshot key below.
        $stamp = array();

        foreach (self::TLE_SOURCES as $id) {
            $result = self::sourceRecords($id, $force);
            $datasets[$id] = $result['data'];
            $source = Config::source($id);
            $sources[$id] = array(
                'id'          => $id,
                'label'       => isset($source['label']) ? $source['label'] : $id,
                'count'       => count($result['data']),
                'cache'       => $result['cache']['status'],
                'age_seconds' => $result['cache']['age'],
                'ttl'         => $result['cache']['ttl'],
                'stored_at'   => isset($result['cache']['stored_at']) ? $result['cache']['stored_at'] : 0,
                'ok'          => !empty($result['data']),
                'error'       => $result['error'],
            );
            $stamp[$id] = isset($result['cache']['stored_at']) ? (int) $result['cache']['stored_at'] : 0;
            if (!empty($result['refresh'])) {
                $refresh[] = $result['refresh'];
            }
            if (empty($result['data'])) {
                $warnings[] = 'source "' . $id . '" has no data';
            }
        }

        // ------------------------------------------------------------------
        // Preprocessing, then serve the preprocessed result.
        //
        // The snapshot key is DERIVED FROM THE SOURCE TIMESTAMPS rather than being
        // a fixed name with a TTL. A fixed name would have to guess a TTL that
        // outlives the slowest source and still be refreshed when the fastest one
        // changes, and no single number does both: CelesTrak refreshes on a 3h TTL
        // while AMSAT is on 12h, so any fixed choice serves one source's data past
        // its own expiry. Deriving the key means a source refresh produces a new key
        // by construction, and the old snapshot simply ages out.
        // ------------------------------------------------------------------
        $snapshotKey = 'prepared:tle:' . substr(hash('sha256', json_encode($stamp)), 0, 16);
        $prepared = Prepared::snapshot(static function () use ($datasets) {
            return $datasets;
        }, $force, $snapshotKey);

        $records = $prepared['records'];
        if (empty($records)) {
            return array(
                'data'     => array(),
                'sources'  => $sources,
                'warnings' => $warnings,
                'refresh'  => $refresh,
                'prepared' => $prepared['stats'],
            );
        }

        return array(
            'data'     => $records,
            'sources'  => $sources,
            'warnings' => $warnings,
            'refresh'  => $refresh,
            // Counters for every cleaning rule that fired, plus the snapshot's own
            // cache state. Published so "the feed changed" can be attributed to a
            // rule instead of to an upstream.
            'prepared' => $prepared['stats'],
            'snapshot' => array(
                'key'      => $snapshotKey,
                'built_at' => $prepared['built_at'],
                'cache'    => $prepared['cache'],
            ),
        );
    }

    /**
     * Every dataset a hot reload touches.
     *
     * The four TLE sets plus the three JSON/HTML datasets. This list is the reason
     * `--target=all` used to be a false promise: the CLI's `all` case expanded to
     * TLE_SOURCES only, so a "reload everything" left satellites, transmitters and
     * the AMSAT status page untouched on whatever old copy they had.
     */
    const DATASETS = array(
        'weather',
        'amateur',
        'amsat',
        'satnogs',
        'satnogs_satellites',
        'satnogs_transmitters',
        'amsat_status',
    );

    /**
     * Hot reload: re-pull every source and OVERWRITE the cached copy.
     *
     * Differences from the scheduled warmup, both deliberate:
     *
     *   - TTL is ignored. `prefetch()` skips a source whose cache is still fresh,
     *     which is correct for a cron that runs every few minutes and wrong for an
     *     operator saying "the feed is wrong, refetch it now". A reload 5 seconds
     *     after the last fetch still hits the network.
     *   - The prepared snapshot is rebuilt from the NEW data rather than from the
     *     cache that was just replaced.
     *
     * Serialised behind a lock: two overlapping reloads would double the upstream
     * request rate for an identical result, and CelesTrak already blocks IPs it
     * considers abusive -- a self-inflicted reload storm is how a service loses its
     * only primary source for two hours.
     *
     * @param string[] $only subset of DATASETS; empty means all
     * @return array<string,mixed>
     */
    public static function reloadAll(array $only = array())
    {
        $targets = array_values(array_intersect(self::DATASETS, $only === array() ? self::DATASETS : $only));
        if ($targets === array()) {
            return array('ok' => false, 'error' => 'no known dataset was selected', 'datasets' => array());
        }

        $lock = new Lock('reload');
        $timeout = (int) Config::get('reload.lock_timeout_ms', 5000);
        if (!$lock->acquire($timeout)) {
            return array(
                'ok'      => false,
                'error'   => 'another reload is already running',
                'locked'  => true,
                'datasets' => array(),
            );
        }

        $limit = max(10, (int) Config::get('reload.time_limit', 180));
        $started = microtime(true);
        $report = array('ok' => true, 'started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'datasets' => array());
        $failed = 0;
        $skipped = array();

        try {
            foreach ($targets as $id) {
                if (microtime(true) - $started > $limit) {
                    // Truncating loudly is the only honest option: a reload that
                    // quietly stopped after four of seven sources would report
                    // success for a half-refreshed cache.
                    $skipped = array_slice($targets, array_search($id, $targets, true));
                    $report['ok'] = false;
                    $report['time_limit_reached'] = true;
                    break;
                }

                $at = microtime(true);
                if (in_array($id, self::TLE_SOURCES, true)) {
                    $result = self::source($id, true);
                    $count = count((array) (isset($result['data']) ? $result['data'] : array()));
                    $error = (string) (isset($result['error']) ? $result['error'] : '');
                    $cache = (array) (isset($result['cache']) ? $result['cache'] : array());
                } elseif ($id === 'amsat_status') {
                    $result = self::amsatReports(true);
                    $count = count((array) (isset($result['data']) ? $result['data'] : array()));
                    $error = (string) (isset($result['error']) ? $result['error'] : '');
                    $cache = (array) (isset($result['cache']) ? $result['cache'] : array());
                } else {
                    $result = self::dataset($id, true);
                    $count = count((array) (isset($result['data']) ? $result['data'] : array()));
                    $error = (string) (isset($result['error']) ? $result['error'] : '');
                    $cache = (array) (isset($result['cache']) ? $result['cache'] : array());
                }

                $ok = $count > 0 && $error === '';
                if (!$ok) {
                    $failed++;
                }
                $report['datasets'][$id] = array(
                    'ok'         => $ok,
                    'count'      => $count,
                    'error'      => $error,
                    'ms'         => (int) round((microtime(true) - $at) * 1000),
                    // `written` is the point of a reload: MISS means the producer ran
                    // and Store::write overwrote the previous copy. STALE/EXPIRED
                    // means the fetch failed and the old copy survived -- which must
                    // not be reported as success.
                    'written'    => (isset($cache['status']) ? (string) $cache['status'] : ''),
                    'stored_at'  => isset($cache['stored_at']) ? (int) $cache['stored_at'] : 0,
                );
            }

            // Rebuild the snapshot from the data that was just written. `true` forces
            // it even though the derived key is new, so the report carries the
            // cleaning counters for THIS payload rather than for a cached one.
            if (in_array('weather', $targets, true) || array_intersect(self::TLE_SOURCES, $targets) !== array()) {
                $merged = self::merged(true);
                $report['prepared'] = isset($merged['prepared']) ? $merged['prepared'] : array();
                $report['snapshot'] = isset($merged['snapshot']) ? $merged['snapshot'] : array();
                $report['merged_count'] = count((array) $merged['data']);
            }
        } catch (\Throwable $e) {
            $report['ok'] = false;
            $report['error'] = $e->getMessage();
            Logger::error('reload_failed', array('error' => $e->getMessage()));
        } finally {
            $lock->release();
        }

        $report['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $report['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        $report['failed'] = $failed;
        if ($skipped !== array()) {
            $report['skipped'] = $skipped;
        }
        $report['ok'] = $report['ok'] && $failed === 0;

        return $report;
    }

    /**
     * Satellite / transmitter data sets (SatNOGS).
     *
     * @return array{data:array<int,array>,cache:array,error:string}
     */
    public static function dataset($id, $force = false)
    {
        $source = Config::source($id);
        if ($source === null) {
            return array('data' => array(), 'cache' => array('status' => 'UNKNOWN', 'age' => 0, 'ttl' => 0, 'backend' => ''), 'error' => 'unknown dataset');
        }

        try {
            $entry = Store::remember(
                'dataset:' . $id,
                (int) $source['ttl'],
                static function () use ($id, $source) {
                    $result = Client::get($source['url'], array('accept' => 'application/json'));
                    Metrics::upstream($id, $result['ok'], $result['ms'], $result['error']);
                    if (!$result['ok']) {
                        throw new \RuntimeException('upstream unavailable: ' . $result['error']);
                    }
                    $decoded = json_decode($result['body'], true);
                    if (!is_array($decoded)) {
                        throw new \RuntimeException('upstream returned invalid JSON');
                    }

                    return array_values($decoded);
                },
                array('codec' => 'serialize', 'force' => $force)
            );
        } catch (\Throwable $e) {
            return self::failureResult('dataset:' . $id, (int) $source['ttl'], $e->getMessage());
        }

        return array(
            'data'    => $entry['value'],
            'cache'   => array(
                'status'    => $entry['status'],
                'age'       => $entry['age'],
                'ttl'       => $entry['ttl'],
                'backend'   => $entry['backend'],
                'stored_at' => isset($entry['stored_at']) ? $entry['stored_at'] : time() - (int) $entry['age'],
            ),
            'error'   => isset($entry['error']) ? $entry['error'] : '',
            'refresh' => isset($entry['refresh']) ? $entry['refresh'] : null,
        );
    }

    /**
     * AMSAT live status reports.
     *
     * @return array{data:array<int,array>,cache:array,error:string,warnings:string[]}
     */
    public static function amsatReports($force = false)
    {
        $source = Config::source('amsat_status');
        $map = self::buildNoradMap();

        try {
            $entry = Store::remember(
                'dataset:amsat_status',
                (int) $source['ttl'],
                static function () use ($source, $map) {
                    $result = Client::get($source['url'], array('accept' => 'text/html'));
                    Metrics::upstream('amsat_status', $result['ok'], $result['ms'], $result['error']);
                    if (!$result['ok']) {
                        throw new \RuntimeException('upstream unavailable: ' . $result['error']);
                    }
                    $parsed = Amsat::parse($result['body'], $map);
                    if (empty($parsed['reports'])) {
                        throw new \RuntimeException('no status reports could be extracted');
                    }

                    return $parsed['reports'];
                },
                array('codec' => 'serialize', 'force' => $force)
            );
        } catch (\Throwable $e) {
            return self::failureResult('dataset:amsat_status', (int) $source['ttl'], $e->getMessage());
        }

        return array(
            'data'    => $entry['value'],
            'cache'   => array(
                'status'    => $entry['status'],
                'age'       => $entry['age'],
                'ttl'       => $entry['ttl'],
                'backend'   => $entry['backend'],
                'stored_at' => isset($entry['stored_at']) ? $entry['stored_at'] : time() - (int) $entry['age'],
            ),
            'error'   => isset($entry['error']) ? $entry['error'] : '',
            'refresh' => isset($entry['refresh']) ? $entry['refresh'] : null,
        );
    }

    /**
     * NORAD lookup built from cached TLE data only - never triggers a fetch so
     * the AMSAT parser cannot cause a self-referential HTTP call (the previous
     * implementation curl'ed its own public /tle endpoint).
     *
     * @return array<string,string>
     */
    public static function buildNoradMap()
    {
        $datasets = array();
        foreach (self::TLE_SOURCES as $id) {
            $entry = Store::read(self::key($id), 'serialize');
            if ($entry !== null && !empty($entry['value'])) {
                $datasets[$id] = $entry['value'];
            }
        }

        $records = empty($datasets) ? array() : Parser::merge($datasets);

        return Amsat::mergeMaps(Amsat::noradMapFromRecords($records), Amsat::fallbackMap());
    }

    /**
     * Records for a single TLE source without touching the network when a
     * cache entry exists (used by the /v1/tle/{source} endpoints).
     *
     * @return array{data:array<string,array>,cache:array,error:string,refresh:callable|null}
     */
    public static function source($id, $force = false)
    {
        if (!in_array($id, self::TLE_SOURCES, true)) {
            return array('data' => array(), 'cache' => array('status' => 'UNKNOWN', 'age' => 0, 'ttl' => 0, 'backend' => ''), 'error' => 'unknown source');
        }

        return self::sourceRecords($id, $force);
    }
}
