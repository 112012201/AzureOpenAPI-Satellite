<?php

namespace SatApi\Data;

use SatApi\Config;
use SatApi\Http\SpacetrackClient;
use SatApi\Logger;
use SatApi\Support\Metrics;

/**
 * Primary-then-fallback source resolution for the TLE groups.
 *
 * Why primary/fallback and not a parallel merge of both providers:
 *
 *   Measurement on 2026-10-07 compared the same NORAD ids from CelesTrak and
 *   SpaceTrack. Weather: 71 of 72 objects had a byte-identical TLE epoch (the one
 *   difference was a single object whose epoch was 13 hours newer on SpaceTrack);
 *   amateur: 94 of 95 identical, to the second. The two providers deliver the same
 *   upstream data, so fetching both and choosing by epoch would double the request
 *   volume without making anything fresher. What actually differs is reachability:
 *   CelesTrak blocks an IP that downloads "excessively" (its own wording, restored
 *   automatically after two quiet hours) while SpaceTrack is credential-gated but
 *   not IP-blocked.
 *
 * So CelesTrak stays primary, SpaceTrack is the fallback, and the two only
 * complement each other for ids the primary did not return.
 */
final class SourceMerge
{
    /** @var SpacetrackClient|null */
    private static $spacetrack = null;

    /**
     * Source id => provider group name for the static membership list.
     */
    const GROUP_FOR_SOURCE = array(
        'weather' => 'weather',
        'amateur' => 'amateur',
    );

    private static function spacetrack()
    {
        if (self::$spacetrack === null) {
            self::$spacetrack = new SpacetrackClient();
        }

        return self::$spacetrack;
    }

    /**
     * The fallback provider for a source, or null when it has none.
     *
     * @return string|null
     */
    public static function fallbackProvider($sourceId)
    {
        $map = (array) Config::get('source_policy.fallback', array());

        return isset($map[$sourceId]) ? (string) $map[$sourceId] : null;
    }

    public static function primaryProvider($sourceId)
    {
        $map = (array) Config::get('source_policy.primary', array());

        return isset($map[$sourceId]) ? (string) $map[$sourceId] : null;
    }

    /**
     * Should this primary failure open a cooldown window?
     *
     * Decided from STRUCTURED fields (the HTTP status) rather than by matching digits
     * inside a free-text error string. That distinction is not academic: CelesTrak's
     * block first arrived as HTTP 403, then as a connection timeout after the host
     * stopped answering this IP at all. Both mean "do not keep trying", but only the
     * first carried a status code, so a pattern match against the message opened a
     * cooldown for the 403 case and none for the timeout -- leaving every warmup run
     * to wait out the full connect timeout, indefinitely, with no backoff.
     *
     * @param array $result the Http\Client result: ok, ms, error, and status when known
     * @return array{0:bool,1:int} [shouldCooldown, suggestedSeconds]
     */
    public static function classifyPrimaryFailure(array $result)
    {
        $status = isset($result['status']) ? (int) $result['status'] : 0;
        $error = strtolower((string) (isset($result['error']) ? $result['error'] : ''));

        // An explicit rate-limit or block answer.
        if ($status === 403 || $status === 429) {
            return array(true, 0);
        }
        if (preg_match('/\b(403|429)\b/', $error) === 1) {
            return array(true, 0);
        }

        // A transport-level failure: timeout, DNS, TLS, connection refused. Treat it
        // as a shorter block so an ordinary blip does not silence a healthy source for
        // two hours, while a genuinely unreachable host stops costing every run the
        // full timeout.
        if ($status === 0 && $error !== '') {
            return array(true, 900);
        }
        if (preg_match('/timeout|timed out|failed to connect|resolve host|connection refused|ssl/i', $error) === 1) {
            return array(true, 900);
        }

        return array(false, 0);
    }

    /**
     * Is the source currently in its "do not retry" cooldown?
     *
     * CelesTrak's block notice states access is restored automatically after two
     * hours of quiet. Retrying during the window only extends it, so the window is
     * recorded and respected.
     */
    public static function inCooldown($sourceId)
    {
        $store = \SatApi\Cache\Store::redisClient();
        $key = 'cooldown:' . $sourceId;
        if ($store !== false) {
            try {
                $until = $store->get($key);
                if ($until !== false && $until !== null && (int) $until > time()) {
                    return true;
                }

                return false;
            } catch (\Throwable $e) {
                // fall through to the file probe
            }
        }

        return is_file(self::cooldownFile($sourceId)) && (int) @file_get_contents(self::cooldownFile($sourceId)) > time();
    }

    public static function remainingCooldown($sourceId)
    {
        $store = \SatApi\Cache\Store::redisClient();
        if ($store !== false) {
            try {
                $until = (int) $store->get('cooldown:' . $sourceId);
                return max(0, $until - time());
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $file = self::cooldownFile($sourceId);
        $until = is_file($file) ? (int) @file_get_contents($file) : 0;

        return max(0, $until - time());
    }

    /**
     * Record a cooldown window. Called when the primary answers 403/429.
     */
    public static function startCooldown($sourceId, $seconds = null, $reason = '')
    {
        $seconds = $seconds === null
            ? max(60, (int) Config::get('source_policy.block_cooldown', 7200))
            : max(60, (int) $seconds);
        $until = time() + $seconds;

        $store = \SatApi\Cache\Store::redisClient();
        if ($store !== false) {
            try {
                $store->setex('cooldown:' . $sourceId, $seconds, (string) $until);
            } catch (\Throwable $e) {
                // fall back to the file
            }
        }
        @file_put_contents(self::cooldownFile($sourceId), (string) $until, LOCK_EX);

        Logger::warning('source_cooldown_started', array(
            'source'  => $sourceId,
            'seconds' => $seconds,
            'until'   => gmdate('Y-m-d\TH:i:s\Z', $until),
            'reason'  => $reason,
        ));
    }

    public static function clearCooldown($sourceId)
    {
        $store = \SatApi\Cache\Store::redisClient();
        if ($store !== false) {
            try {
                $store->del('cooldown:' . $sourceId);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        @unlink(self::cooldownFile($sourceId));
    }

    private static function cooldownFile($sourceId)
    {
        return Config::path('data/cooldown_' . preg_replace('/[^a-z0-9_]/i', '', (string) $sourceId));
    }

    /**
     * NORAD ids for a group, from conf/tle-groups.php.
     *
     * @return string[]
     */
    public static function groupIds($group)
    {
        static $cache = array();
        if (isset($cache[$group])) {
            return $cache[$group];
        }
        $file = Config::path('conf/tle-groups.php');
        $cache[$group] = array();
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data) && isset($data[$group]['ids']) && is_array($data[$group]['ids'])) {
                $cache[$group] = array_map('strval', $data[$group]['ids']);
            }
        }

        return $cache[$group];
    }

    /**
     * Fetch a TLE group from SpaceTrack.
     *
     * @param string   $sourceId e.g. 'weather'
     * @param callable $parse    fn(string $raw, string $sourceId): array
     * @return array{ok:bool,records:array,error:string,detail:array}
     */
    public static function fetchViaSpacetrack($sourceId, callable $parse)
    {
        $group = isset(self::GROUP_FOR_SOURCE[$sourceId]) ? self::GROUP_FOR_SOURCE[$sourceId] : null;
        if ($group === null) {
            return array('ok' => false, 'records' => array(), 'error' => 'no group mapping for ' . $sourceId, 'detail' => array());
        }
        $ids = self::groupIds($group);
        if ($ids === array()) {
            return array('ok' => false, 'records' => array(), 'error' => 'empty group list for ' . $group, 'detail' => array());
        }

        $client = self::spacetrack();
        if (!$client->configured()) {
            return array('ok' => false, 'records' => array(), 'error' => 'spacetrack not configured', 'detail' => array());
        }

        $started = microtime(true);
        // 3le carries the object name on its own line, which the TLE parser keeps
        // for the `name` field; plain `tle` would lose it.
        $res = $client->tleForIds($ids, '3le');
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (!$res['ok']) {
            Metrics::upstream('spacetrack:' . $sourceId, false, $ms, (string) $res['error']);

            return array(
                'ok'      => false,
                'records' => array(),
                'error'   => (string) $res['error'],
                'detail'  => array('chunks' => $res['chunks'], 'ms' => $ms),
            );
        }

        $records = $parse($res['records'], $sourceId);
        if ($records === array()) {
            Metrics::upstream('spacetrack:' . $sourceId, false, $ms, 'no parseable records');

            return array(
                'ok'      => false,
                'records' => array(),
                'error'   => 'spacetrack returned no parseable TLE records',
                'detail'  => array('chunks' => $res['chunks'], 'ms' => $ms, 'bytes' => strlen($res['records'])),
            );
        }

        Metrics::upstream('spacetrack:' . $sourceId, true, $ms, '');

        return array(
            'ok'      => true,
            'records' => $records,
            'error'   => '',
            'detail'  => array(
                'chunks'   => $res['chunks'],
                'ms'       => $ms,
                'ids'      => count($ids),
                'returned' => count($records),
            ),
        );
    }

    /**
     * Complement `$records` with ids SpaceTrack has that the primary did not
     * return. Only used after a successful primary fetch, so the primary stays
     * authoritative for every id it does provide.
     *
     * @param array<int,array> $records
     * @param callable         $parse
     * @return array{records:array,added:int,error:string}
     */
    public static function complementMissing($sourceId, array $records, callable $parse)
    {
        $group = isset(self::GROUP_FOR_SOURCE[$sourceId]) ? self::GROUP_FOR_SOURCE[$sourceId] : null;
        if ($group === null) {
            return array('records' => $records, 'added' => 0, 'error' => 'no group mapping');
        }
        $known = array();
        foreach ($records as $r) {
            $id = (string) ($r['norad_id'] ?? '');
            if ($id !== '') {
                $known[$id] = true;
            }
        }
        $missing = array();
        foreach (self::groupIds($group) as $id) {
            if (!isset($known[$id])) {
                $missing[] = $id;
            }
        }
        if ($missing === array()) {
            return array('records' => $records, 'added' => 0, 'error' => '');
        }

        $client = self::spacetrack();
        if (!$client->configured()) {
            return array('records' => $records, 'added' => 0, 'error' => 'spacetrack not configured');
        }

        $res = $client->tleForIds($missing, '3le');
        if (!$res['ok']) {
            return array('records' => $records, 'added' => 0, 'error' => (string) $res['error']);
        }
        $extra = $parse($res['records'], $sourceId);
        if ($extra === array()) {
            return array('records' => $records, 'added' => 0, 'error' => 'no parseable records for missing ids');
        }
        $added = 0;
        foreach ($extra as $r) {
            $id = (string) ($r['norad_id'] ?? '');
            if ($id !== '' && !isset($known[$id])) {
                $records[] = $r;
                $known[$id] = true;
                $added++;
            }
        }
        if ($added > 0) {
            Logger::info('source_complemented', array(
                'source' => $sourceId,
                'asked'  => count($missing),
                'added'  => $added,
            ));
        }

        return array('records' => $records, 'added' => $added, 'error' => '');
    }

    /**
     * Decide whether a primary result is worth keeping.
     *
     * A source that suddenly returns a fraction of its usual records is treated as
     * damaged rather than authoritative; that is what the shrink threshold is for.
     */
    public static function looksTruncated(array $records, $previousCount = null)
    {
        $threshold = (float) Config::get('source_policy.shrink_threshold', 0.5);
        if ($previousCount === null || $previousCount <= 0 || $records === array()) {
            return false;
        }

        return count($records) < ((int) $previousCount * $threshold);
    }

    /**
     * Record how many records a source produced, so the next run can detect a
     * suspicious shrink.
     */
    public static function rememberCount($sourceId, $count)
    {
        $file = Config::path('data/last_count_' . preg_replace('/[^a-z0-9_]/i', '', (string) $sourceId) . '.json');
        @file_put_contents($file, json_encode(array('count' => (int) $count, 'at' => time())), LOCK_EX);
    }

    public static function lastCount($sourceId)
    {
        $file = Config::path('data/last_count_' . preg_replace('/[^a-z0-9_]/i', '', (string) $sourceId) . '.json');
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['count'])) {
            return null;
        }

        return (int) $data['count'];
    }
}
