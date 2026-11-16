<?php

namespace SatApi\Data;

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Logger;
use SatApi\Tle\Parser;

/**
 * Information preprocessing: clean, complete and index the upstream feeds.
 *
 * WHY THIS IS A SEPARATE STEP
 *
 * Parser is a *syntax* layer: it turns text into records and rejects what cannot
 * be a TLE at all. What it deliberately does not do is judge the contents -- a
 * record with a plausible shape is accepted even when its catalogue number is
 * missing, its name still carries the 3LE prefix, or its two "lines" describe two
 * different satellites.
 *
 * This class is the *semantic* layer. It runs once per rebuild (not once per
 * request), writes a snapshot, and the request path serves the snapshot. That
 * ordering is the whole point: the merge is the expensive part, and doing it on
 * every request is work that produces the same answer.
 *
 * EVERY REPAIR IS COUNTED.
 *
 * A cleaning pass that silently changes data is indistinguishable from a source
 * that is lying. So each rule returns a count, the counts are published in
 * /v1/status and in the reload report, and a rule never invents information: it
 * either normalises a representation (NORAD padding, whitespace), fills a field
 * from a documented lookup (norad_fallback), or drops a record it cannot stand
 * behind. It never recomputes a checksum over corrupt data, because that would
 * launder corruption into apparent validity.
 *
 * The rules are one method each so the pipeline reads as a list.
 */
final class Prepared
{
    /**
     * @var array<string,int>
     */
    private static $stats = array();

    /**
     * Build the cleaned record set from per-source datasets.
     *
     * @param array<string,array<string,array>> $datasets sourceId => records keyed by norad
     * @return array{records:array<string,array>,stats:array<string,int>,sources:array<string,int>}
     */
    public static function build(array $datasets)
    {
        self::$stats = array(
            'input_records'      => 0,
            'dropped_invalid'    => 0,
            'dropped_norad'      => 0,
            'name_normalised'    => 0,
            'norad_completed'    => 0,
            'checksum_failed'    => 0,
            'checksum_verified'  => 0,
            'duplicates_merged'  => 0,
            'epoch_missing'      => 0,
            'orbit_incomplete'   => 0,
            'capped'             => 0,
        );
        $sources = array();

        $maxRecords = max(1, (int) Config::get('preprocess.max_records', 60000));
        $verify = (bool) Config::get('preprocess.verify_checksum', true);
        $names = (bool) Config::get('preprocess.normalise_names', true);
        $completeNorad = (bool) Config::get('preprocess.complete_norad', true);
        $rejectMismatch = (bool) Config::get('preprocess.reject_norad_mismatch', true);

        $noradByName = self::noradIndex();

        /** @var array<string,array> $merged */
        $merged = array();

        foreach ($datasets as $sourceId => $records) {
            if (!is_array($records)) {
                continue;
            }
            $sourceId = (string) $sourceId;
            $sources[$sourceId] = 0;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                self::$stats['input_records']++;

                $clean = self::clean(array(
                    'norad_id'   => isset($record['norad_id']) ? $record['norad_id'] : '',
                    'name'       => isset($record['name']) ? $record['name'] : '',
                    'line1'      => isset($record['line1']) ? $record['line1'] : '',
                    'line2'      => isset($record['line2']) ? $record['line2'] : '',
                    'epoch_unix' => isset($record['epoch_unix']) ? $record['epoch_unix'] : 0,
                    'epoch_iso'  => isset($record['epoch_iso']) ? $record['epoch_iso'] : '',
                    'source'     => $sourceId,
                ), $names, $rejectMismatch);

                if ($clean === null) {
                    continue;
                }

                // ---- completion: catalogue number from the name --------------
                if ($clean['norad_id'] === '' && $completeNorad) {
                    $guess = self::noradForName($clean['name'], $noradByName);
                    if ($guess !== '') {
                        $clean['norad_id'] = $guess;
                        self::$stats['norad_completed']++;
                    }
                }
                if ($clean['norad_id'] === '') {
                    self::$stats['dropped_norad']++;
                    continue;
                }

                // ---- verification: checksum ---------------------------------
                if ($verify) {
                    $ok1 = Parser::checksumOk($clean['line1']);
                    $ok2 = Parser::checksumOk($clean['line2']);
                    $clean['checksum_ok'] = $ok1 && $ok2;
                    if ($clean['checksum_ok']) {
                        self::$stats['checksum_verified']++;
                    } else {
                        self::$stats['checksum_failed']++;
                        // Published so a client can see WHICH digit the line claims
                        // versus what the line's own bytes add up to. Never applied.
                        $clean['checksum_expected'] = array(
                            'line1' => self::checksumDigit($clean['line1']),
                            'line2' => self::checksumDigit($clean['line2']),
                        );
                    }
                }

                if ((int) $clean['epoch_unix'] <= 0) {
                    self::$stats['epoch_missing']++;
                }
                if ($clean['orbit'] === array()) {
                    self::$stats['orbit_incomplete']++;
                }

                // ---- de-duplication -----------------------------------------
                $key = $clean['norad_id'];
                if (!isset($merged[$key])) {
                    $clean['sources'] = array($sourceId);
                    $merged[$key] = $clean;
                    $sources[$sourceId]++;
                    continue;
                }

                self::$stats['duplicates_merged']++;
                $previous = $merged[$key];
                $previousSources = isset($previous['sources']) ? (array) $previous['sources'] : array();

                // Newest element set wins; a tie prefers the longer name, which is
                // how "ISS (ZARYA)" beats "ISS". Ported from Parser::merge so the
                // ordering behaviour does not change underneath existing clients.
                $replace = false;
                if ((int) $clean['epoch_unix'] > (int) $previous['epoch_unix']) {
                    $replace = true;
                } elseif ((int) $clean['epoch_unix'] === (int) $previous['epoch_unix']
                    && strlen((string) $clean['name']) > strlen((string) $previous['name'])) {
                    $replace = true;
                }

                $winner = $replace ? $clean : $previous;
                $winner['sources'] = array_values(array_unique(array_merge(
                    $previousSources,
                    array($sourceId)
                )));
                sort($winner['sources']);
                $merged[$key] = $winner;
                $sources[$sourceId]++;
            }
        }

        // ---- stable ordering -------------------------------------------------
        // Numeric by catalogue number, which is what every downstream consumer
        // (a pass predictor, a diff, a human reading the file) expects. strcmp on
        // zero-padded ids happens to agree, but relying on the padding to produce
        // numeric order is the kind of coincidence that breaks when the padding does.
        uksort($merged, static function ($a, $b) {
            return ((int) $a) <=> ((int) $b);
        });

        // ---- hard ceiling ----------------------------------------------------
        if (count($merged) > $maxRecords) {
            self::$stats['capped'] = count($merged) - $maxRecords;
            $merged = array_slice($merged, 0, $maxRecords, true);
        }

        return array('records' => $merged, 'stats' => self::$stats, 'sources' => $sources);
    }

    /**
     * The cleaned snapshot, built on demand and cached.
     *
     * @param callable $producer fn(): array<string,array> -- the per-source datasets;
     *                           only called on a miss, so a warm snapshot costs no
     *                           parsing and no merging at all
     * @param bool     $force    rebuild even when the snapshot is fresh
     * @param string   $key      cache key; callers derive it from the underlying
     *                           source timestamps so a source refresh invalidates the
     *                           snapshot by construction (see Repository::merged)
     * @return array{records:array,stats:array,sources:array,cache:array,built_at:int}
     */
    public static function snapshot(callable $producer, $force = false, $key = null)
    {
        if (!(bool) Config::get('preprocess.enabled', true)) {
            // Disabled: no snapshot, no caching. The caller still gets a correct
            // (if slower) answer, which is what makes this a safe switch to flip
            // during an incident.
            $built = call_user_func($producer);

            return self::wrap(self::build($built), array(
                'status' => 'BYPASS', 'age' => 0, 'backend' => '', 'ttl' => 0,
            ), time());
        }

        $key = $key !== null && $key !== ''
            ? (string) $key
            : (string) Config::get('preprocess.key', 'prepared:tle');
        $ttl = max(60, (int) Config::get('preprocess.ttl', 43200));

        $entry = Store::remember($key, $ttl, static function () use ($producer) {
            $built = call_user_func($producer);
            $result = self::build($built);

            // Only the record set and the counters are stored. The raw per-source
            // datasets live under their own keys; duplicating them here would double
            // the cache footprint for no benefit.
            return array(
                'records'  => $result['records'],
                'stats'    => $result['stats'],
                'sources'  => $result['sources'],
                'built_at' => time(),
            );
        }, array('codec' => 'serialize', 'force' => $force));

        $value = is_array($entry['value']) ? $entry['value'] : array();

        return array(
            'records'  => isset($value['records']) ? (array) $value['records'] : array(),
            'stats'    => isset($value['stats']) ? (array) $value['stats'] : array(),
            'sources'  => isset($value['sources']) ? (array) $value['sources'] : array(),
            'built_at' => isset($value['built_at']) ? (int) $value['built_at'] : 0,
            'cache'    => array(
                'status'  => isset($entry['status']) ? (string) $entry['status'] : '',
                'age'     => isset($entry['age']) ? (int) $entry['age'] : 0,
                'ttl'     => isset($entry['ttl']) ? (int) $entry['ttl'] : 0,
                'backend' => isset($entry['backend']) ? (string) $entry['backend'] : '',
            ),
        );
    }

    /**
     * Statistics from the most recent build in this process (diagnostics).
     *
     * @return array<string,int>
     */
    public static function lastStats()
    {
        return self::$stats;
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    /**
     * Normalise one record, or return null when it cannot be served.
     *
     * @return array|null
     */
    private static function clean(array $record, $normaliseNames, $rejectMismatch)
    {
        $line1 = trim((string) $record['line1']);
        $line2 = trim((string) $record['line2']);
        $name = trim((string) $record['name']);

        $valid = $line1 !== '' && $line2 !== ''
            && strlen($line1) >= 60 && strlen($line2) >= 60
            && $line1[0] === '1' && $line2[0] === '2';

        if (!$valid) {
            // Not a policy question, so there is no switch for it: a record whose two
            // lines are not two TLE lines cannot be rendered into the text feed
            // without emitting garbage, and a consumer has no way to tell it apart
            // from a real element set once it is in the file.
            self::$stats['dropped_invalid']++;

            return null;
        }

        $norad = Parser::normalizeNorad($record['norad_id']);
        if ($norad === '' && strlen($line1) >= 7) {
            $norad = Parser::normalizeNorad(substr($line1, 2, 5));
        }

        if ($rejectMismatch && $norad !== '' && strlen($line2) >= 7) {
            $norad2 = Parser::normalizeNorad(substr($line2, 2, 5));
            if ($norad2 !== '' && $norad2 !== '00000' && $norad2 !== $norad) {
                self::$stats['dropped_invalid']++;

                return null;
            }
        }

        if ($normaliseNames) {
            $clean = Parser::cleanName($name);
            if ($clean !== $name) {
                self::$stats['name_normalised']++;
            }
            $name = $clean;
        }
        if ($name === '' && $norad !== '') {
            $name = 'NORAD ' . $norad;
        }

        $epochUnix = (int) $record['epoch_unix'];
        $epochIso = (string) $record['epoch_iso'];
        if ($epochUnix > 0 && $epochIso === '') {
            $epochIso = gmdate('Y-m-d\TH:i:s\Z', $epochUnix);
        }

        return array(
            'norad_id'    => $norad,
            'name'        => $name,
            'line1'       => $line1,
            'line2'       => $line2,
            'epoch_unix'  => $epochUnix,
            'epoch_iso'   => $epochIso,
            'source'      => (string) $record['source'],
            'checksum_ok' => isset($record['checksum_ok']) ? (bool) $record['checksum_ok'] : true,
            'orbit'       => self::orbit($line1, $line2),
        );
    }

    /**
     * The orbital elements carried by the two lines, as numbers.
     *
     * This is the "field-level completion" half of preprocessing: the raw lines
     * are the wire format, but every consumer that wants to know an inclination
     * has to know the column offsets, and the offsets are easy to get wrong (the
     * eccentricity field omits its leading "0.", and the mean motion field is
     * right-aligned). Doing it once here means no client has to re-derive them.
     *
     * Missing or unparseable fields are omitted rather than zero-filled: a zero
     * inclination is a real orbit type, so a fabricated 0 is a lie a consumer
     * cannot detect.
     *
     * @return array<string,mixed>
     */
    private static function orbit($line1, $line2)
    {
        $orbit = array();

        if (strlen($line2) >= 63) {
            $orbit['inclination_deg']   = self::number(substr($line2, 8, 8));
            $orbit['raan_deg']          = self::number(substr($line2, 17, 8));
            $orbit['eccentricity']      = self::number('0.' . trim(substr($line2, 26, 7)));
            $orbit['argp_deg']          = self::number(substr($line2, 34, 8));
            $orbit['mean_anomaly_deg']  = self::number(substr($line2, 43, 8));
            $orbit['mean_motion']       = self::number(substr($line2, 52, 11));
            $orbit['rev_number']        = self::integer(substr($line2, 63, 5));
        }

        if (strlen($line1) >= 61) {
            $orbit['bstar']             = self::number(substr($line1, 53, 8));
            $orbit['element_set']       = self::integer(substr($line1, 64, 4));
            $orbit['classification']    = trim(substr($line1, 7, 1));
        }

        // Drop the ones that did not parse, so `orbit` only ever contains real data.
        foreach ($orbit as $key => $value) {
            if ($value === null) {
                unset($orbit[$key]);
            }
        }

        return $orbit;
    }

    /**
     * Float from a right-aligned TLE field, or null when it is not a number.
     *
     * @return float|null
     */
    private static function number($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-' || $value === '+') {
            return null;
        }

        // TLE "assumed decimal point" exponent form: "12345-4" means 0.12345e-4,
        // and " 00000+0" means zero. Only lines WITHOUT a literal decimal point use
        // it, and the sign must come after at least one digit -- otherwise "-1.5"
        // (a plain negative float) would be misread as mantissa "-1" e-5.
        if (strpos($value, '.') === false && preg_match('/^([+-]?)(\d+)([+-]\d+)$/', $value, $m)) {
            $mantissa = (float) ('0.' . $m[2]);
            if ($m[1] === '-') {
                $mantissa = -$mantissa;
            }

            return $mantissa * pow(10, (int) $m[3]);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return int|null
     */
    private static function integer($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The modulo-10 checksum digit a line's own bytes produce.
     *
     * PHP 8.2+ safe: no string offset arithmetic on a short line, and no reliance
     * on the caller having padded to 69 characters.
     */
    private static function checksumDigit($line)
    {
        $line = str_pad((string) $line, 68);
        $sum = 0;
        for ($i = 0; $i < 68; $i++) {
            $c = $line[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
        }

        return $sum % 10;
    }

    /**
     * name => norad, from the configured fallback plus the live catalogue.
     *
     * @return array<string,string>
     */
    private static function noradIndex()
    {
        $index = array();
        foreach ((array) Config::get('norad_fallback', array()) as $name => $norad) {
            $key = self::nameKey((string) $name);
            $normalised = Parser::normalizeNorad($norad);
            if ($key !== '' && $normalised !== '') {
                $index[$key] = $normalised;
            }
        }

        return $index;
    }

    /**
     * Resolve a satellite name to a catalogue number, or '' when unsure.
     *
     * Exact (normalised) match only. A substring match here would map "AO-7" onto
     * "AO-70" the moment the catalogue grows a satellite with that name, and a
     * wrong NORAD is worse than a missing one.
     *
     * @param array<string,string> $index
     */
    private static function noradForName($name, array $index)
    {
        $key = self::nameKey($name);
        if ($key === '') {
            return '';
        }

        return isset($index[$key]) ? $index[$key] : '';
    }

    private static function nameKey($name)
    {
        $name = strtoupper(trim((string) $name));
        // "ISS (ZARYA)" and "ISS" are the same satellite; the parenthesised part is
        // a sub-designation, not part of the identity.
        $name = preg_replace('/\s*\([^)]*\)/', '', $name);
        $name = preg_replace('/[^A-Z0-9\-]/', '', (string) $name);

        return trim((string) $name);
    }

    /**
     * @param array{records:array,stats:array,sources:array} $built
     * @param array<string,mixed>                            $cache
     * @return array{records:array,stats:array,sources:array,cache:array,built_at:int}
     */
    private static function wrap(array $built, array $cache, $builtAt)
    {
        return array(
            'records'  => $built['records'],
            'stats'    => $built['stats'],
            'sources'  => $built['sources'],
            'built_at' => (int) $builtAt,
            'cache'    => $cache,
        );
    }
}
