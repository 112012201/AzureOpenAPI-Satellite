<?php

namespace SatApi\Data;

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
                            // notice, which self-heals after two quiet hours.
                            // Retrying inside that window only extends it.
                            if (preg_match('/\b(403|429)\b/', (string) $result['error'])) {
                                SourceMerge::startCooldown($id, null, (string) $result['error']);
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

        // Group by Accept header so each parallel batch is uniform.
        $grouped = array();
        foreach ($missing as $id => $url) {
            $source = Config::source($id);
            $accept = isset($source['accept']) ? $source['accept'] : '';
            $grouped[$accept][] = $id;
        }

        foreach ($grouped as $accept => $groupIds) {
            $batch = array();
            foreach ($groupIds as $id) {
                $batch[$id] = $missing[$id];
            }
            $results = Client::getMany($batch, array('accept' => $accept));
            foreach ($results as $id => $result) {
                Metrics::upstream($id, $result['ok'], $result['ms'], $result['error']);
                if (!$result['ok']) {
                    self::$fetchFailures[$id] = $result['error'];
                    Logger::warning('upstream_failed', array('source' => $id, 'error' => $result['error'], 'status' => $result['status']));
                    $summary[$id] = array('ok' => false, 'count' => 0, 'error' => $result['error'], 'ms' => $result['ms']);
                    continue;
                }
                $source = Config::source($id);
                $records = self::parse($id, $source, $result['body']);
                if (empty($records)) {
                    self::$fetchFailures[$id] = 'no parseable records';
                    Logger::warning('upstream_parse_empty', array('source' => $id));
                    $summary[$id] = array('ok' => false, 'count' => 0, 'error' => 'no parseable records', 'ms' => $result['ms']);
                    continue;
                }
                Store::write(self::key($id), $records, (int) $source['ttl'] + 2592000, 'serialize', (int) $source['ttl']);
                unset(self::$results[$id], self::$fetchFailures[$id]);
                $summary[$id] = array('ok' => true, 'count' => count($records), 'error' => '', 'ms' => $result['ms']);
            }
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
            if (!empty($result['refresh'])) {
                $refresh[] = $result['refresh'];
            }
            if (empty($result['data'])) {
                $warnings[] = 'source "' . $id . '" has no data';
            }
        }

        $records = Parser::merge($datasets);
        if (empty($records)) {
            return array('data' => array(), 'sources' => $sources, 'warnings' => $warnings, 'refresh' => $refresh);
        }

        return array('data' => $records, 'sources' => $sources, 'warnings' => $warnings, 'refresh' => $refresh);
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
