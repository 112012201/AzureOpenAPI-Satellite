<?php

namespace SatApi\Tle;

/**
 * TLE parsing / merging.
 *
 * Fixes applied relative to the previous implementation:
 *   - NORAD identifiers are normalised (zero padded) before they are used as
 *     the dedup key, so CelesTrak "00965" and SatNOGS "965" are the same
 *     satellite (before, they produced duplicates in the merged feed),
 *   - the leading "0 " line-number prefix used by 3LE feeds is stripped from
 *     satellite names, so names are identical across every upstream,
 *   - epochs are converted to real Unix timestamps instead of a float that
 *     silently wrapped across the 57/2000 year boundary,
 *   - malformed checksum lines are reported instead of silently corrupting
 *     the feed,
 *   - records are validated (line length, line numbers, NORAD match) before
 *     being accepted.
 */
final class Parser
{
    /**
     * Options accepted by every parse method.
     *
     * @var array
     */
    private static $warnings = array();

    /**
     * Parse classical 2LE/3LE text (CelesTrak, AMSAT, most vendors).
     *
     * @param string $raw
     * @param string $sourceId
     * @param bool   $strict Drop records with an invalid checksum.
     * @return array<string,array>
     */
    public static function parseText($raw, $sourceId = 'unknown', $strict = false)
    {
        $records = array();
        if (!is_string($raw) || $raw === '') {
            return $records;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return $records;
        }

        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = trim($lines[$i]);

            if ($line === '' || $line[0] === '#') {
                $i++;
                continue;
            }

            // 3LE name line: "0 ISS (ZARYA)" or plain "ISS (ZARYA)".
            if ($line[0] !== '1' && isset($lines[$i + 1]) && self::isLine(trim($lines[$i + 1]), '1')) {
                $name = self::cleanName($line);
                $line1 = trim($lines[$i + 1]);
                $line2 = isset($lines[$i + 2]) ? trim($lines[$i + 2]) : '';
                if (!self::isLine($line2, '2')) {
                    $i++;
                    continue;
                }
                self::addRecord($records, $name, $line1, $line2, $sourceId, $strict);
                $i += 3;
                continue;
            }

            // 2LE without a name line.
            if (self::isLine($line, '1') && isset($lines[$i + 1]) && self::isLine(trim($lines[$i + 1]), '2')) {
                $line1 = $line;
                $line2 = trim($lines[$i + 1]);
                $norad = self::extractNorad($line1);
                $name = $norad !== '' ? 'NORAD ' . $norad : 'UNKNOWN';
                self::addRecord($records, $name, $line1, $line2, $sourceId, $strict);
                $i += 2;
                continue;
            }

            $i++;
        }

        return $records;
    }

    /**
     * Parse the flattened single-line variant that some AMSAT mirrors serve
     * (the whole element set on one line). Rather than trying to match the
     * original text byte for byte, the record is rebuilt as a proper 2LE and
     * validated by the normal path, including a recomputed checksum.
     *
     * @param string $raw
     * @param string $sourceId
     * @return array<string,array>
     */
    public static function parseFlattened($raw, $sourceId = 'unknown')
    {
        $records = array();
        $content = trim((string) preg_replace('/\s+/', ' ', (string) $raw));
        if ($content === '' || $content === null) {
            return $records;
        }

        $pattern = '/([A-Z0-9][A-Z0-9\-\.\(\) ]{0,23}?)\s+1\s+(\d{5})([A-Za-z])\s+'
            . '(\d{5}\.\d{8})\s+([\-\+]?[\d\.]+)\s+([\d\-]+)\s+([\d\-]+)\s+(\d+)\s+(\d+)\s+'
            . '2\s+\2\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)/';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $records;
        }

        foreach ($matches as $m) {
            $norad = self::normalizeNorad($m[2]);
            if ($norad === '') {
                continue;
            }
            $line1 = sprintf(
                '1 %s%s %s %s %s %s %s %s',
                $norad,
                strtoupper($m[3]),
                $m[4],
                $m[5],
                $m[6],
                $m[7],
                $m[8],
                $m[9]
            );
            $line2 = sprintf(
                '2 %s %s %s %s %s %s %s %s',
                $norad,
                $m[10],
                $m[11],
                $m[12],
                $m[13],
                $m[14],
                $m[15],
                $m[16]
            );

            $line1 = self::withChecksum(self::pad($line1, 68));
            $line2 = self::withChecksum(self::pad($line2, 68));

            self::addRecord($records, trim($m[1]), $line1, $line2, $sourceId, false, $norad);
        }

        return $records;
    }

    /**
     * Parse the SatNOGS DB /api/tle/ JSON document.
     *
     * @param string $raw
     * @param string $sourceId
     * @param bool   $strict
     * @return array<string,array>
     */
    public static function parseSatnogsJson($raw, $sourceId = 'satnogs', $strict = false)
    {
        $records = array();
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return $records;
        }

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line1 = isset($item['tle1']) ? trim((string) $item['tle1']) : '';
            $line2 = isset($item['tle2']) ? trim((string) $item['tle2']) : '';
            $name = isset($item['tle0']) ? self::cleanName((string) $item['tle0']) : '';
            if ($name === '' && isset($item['name'])) {
                $name = self::cleanName((string) $item['name']);
            }

            $norad = '';
            if (isset($item['norad_cat_id']) && $item['norad_cat_id'] !== null) {
                $norad = self::normalizeNorad($item['norad_cat_id']);
            } elseif ($line1 !== '') {
                $norad = self::extractNorad($line1);
            }

            if ($name === '') {
                $name = $norad !== '' ? 'NORAD ' . $norad : 'UNKNOWN';
            }

            self::addRecord($records, $name, $line1, $line2, $sourceId, $strict, $norad);
        }

        return $records;
    }

    /**
     * Merge data sets, keeping the newest epoch per NORAD id.
     *
     * @param array<string,array<string,array>> $datasets sourceId => records
     * @return array<string,array>
     */
    public static function merge(array $datasets)
    {
        $merged = array();
        foreach ($datasets as $sourceId => $records) {
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $norad => $record) {
                if (!isset($merged[$norad])) {
                    $merged[$norad] = $record;
                    continue;
                }
                if ($record['epoch_unix'] > $merged[$norad]['epoch_unix']) {
                    $merged[$norad] = $record;
                } elseif ($record['epoch_unix'] === $merged[$norad]['epoch_unix']
                    && strlen($record['name']) > strlen($merged[$norad]['name'])) {
                    // Same epoch: prefer the more descriptive name.
                    $merged[$norad]['name'] = $record['name'];
                }
            }
        }

        uksort($merged, static function ($a, $b) {
            return strcmp((string) $a, (string) $b);
        });

        return $merged;
    }

    /**
     * Render records as plain TLE text.
     *
     * @param array<string,array> $records
     * @param string[]            $commentLines
     * @return string
     */
    public static function toTleText(array $records, array $commentLines = array())
    {
        $out = '';
        foreach ($commentLines as $line) {
            $out .= rtrim('# ' . ltrim((string) $line, '# ')) . "\n";
        }
        if (!empty($commentLines)) {
            $out .= "#\n";
        }
        foreach ($records as $record) {
            $out .= $record['name'] . "\n" . $record['line1'] . "\n" . $record['line2'] . "\n";
        }

        return $out;
    }

    /**
     * Render records as JSON-ready structures.
     *
     * @param array<string,array> $records
     * @return array<int,array>
     */
    public static function toJson(array $records)
    {
        $out = array();
        foreach ($records as $record) {
            $entry = array(
                'norad_id'   => $record['norad_id'],
                'name'       => $record['name'],
                'epoch'      => $record['epoch_iso'],
                'epoch_unix' => $record['epoch_unix'],
                'source'     => $record['source'],
                'tle'        => array($record['line1'], $record['line2']),
                'checksum_ok' => $record['checksum_ok'],
            );

            // ------------------------------------------------------------------
            // Additive fields produced by Data\Prepared.
            //
            // Additive on purpose: a client that ignores them sees exactly the
            // document it saw before, and a client that wants an inclination no
            // longer has to know that the eccentricity column omits its leading
            // "0.". `sources` lists every upstream that carried this catalogue
            // number, which is what makes the merge auditable instead of a silent
            // winner-takes-all.
            //
            // Emitted only when present, so a record that went through the old code
            // path (tests, a call to Parser::merge directly) is byte-identical to
            // how it was before.
            // ------------------------------------------------------------------
            if (isset($record['orbit']) && is_array($record['orbit']) && $record['orbit'] !== array()) {
                $entry['orbit'] = $record['orbit'];
            }
            if (isset($record['sources']) && is_array($record['sources']) && count($record['sources']) > 0) {
                $entry['sources'] = array_values($record['sources']);
            }
            if (isset($record['checksum_expected'])) {
                $entry['checksum_expected'] = $record['checksum_expected'];
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public static function warnings()
    {
        return self::$warnings;
    }

    public static function resetWarnings()
    {
        self::$warnings = array();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function addRecord(array &$records, $name, $line1, $line2, $sourceId, $strict, $noradOverride = '')
    {
        if ($line1 === '' || $line2 === '' || !self::isLine($line1, '1') || !self::isLine($line2, '2')) {
            return;
        }

        $line1 = self::pad($line1, 69);
        $line2 = self::pad($line2, 69);

        $norad = $noradOverride !== '' ? self::normalizeNorad($noradOverride) : self::extractNorad($line1);
        if ($norad === '') {
            return;
        }

        // Consistency: the NORAD id inside line 2 must match line 1.
        $norad2 = self::normalizeNorad(substr($line2, 2, 5));
        if ($norad2 !== '' && $norad2 !== '00000' && $norad2 !== $norad) {
            self::$warnings[] = 'norad mismatch in ' . $sourceId . ' for ' . $norad;

            return;
        }

        $checksumOk = self::checksumOk($line1) && self::checksumOk($line2);
        if ($strict && !$checksumOk) {
            self::$warnings[] = 'checksum failed for ' . $norad . ' (' . $sourceId . ')';

            return;
        }
        if (!$checksumOk) {
            self::$warnings[] = 'checksum failed for ' . $norad . ' (' . $sourceId . ')';
        }

        $epoch = self::epochFromLine($line1);
        if ($epoch['unix'] <= 0) {
            self::$warnings[] = 'unparseable epoch for ' . $norad . ' (' . $sourceId . ')';
        }

        $records[$norad] = array(
            'norad_id'    => $norad,
            'name'        => $name !== '' ? $name : 'NORAD ' . $norad,
            'line1'       => $line1,
            'line2'       => $line2,
            'epoch_unix'  => $epoch['unix'],
            'epoch_iso'   => $epoch['iso'],
            'source'      => $sourceId,
            'checksum_ok' => $checksumOk,
        );
    }

    private static function isLine($line, $number)
    {
        $length = strlen($line);

        return $length >= 60 && $line[0] === $number && $line[1] === ' ';
    }

    private static function pad($line, $length)
    {
        return strlen($line) >= $length ? $line : str_pad($line, $length, ' ');
    }

    /**
     * Append the modulo-10 checksum to a 68 character line.
     */
    private static function withChecksum($line)
    {
        $line = self::pad($line, 68);
        $line = substr($line, 0, 68);
        $sum = 0;
        for ($i = 0; $i < 68; $i++) {
            $c = $line[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
        }

        return $line . (string) ($sum % 10);
    }

    public static function cleanName($name)
    {
        $name = trim((string) $name);
        // Strip the 3LE line number prefix.
        if (strlen($name) > 2 && $name[0] === '0' && $name[1] === ' ') {
            $name = trim(substr($name, 2));
        }

        return preg_replace('/\s+/', ' ', $name);
    }

    public static function normalizeNorad($id)
    {
        if (is_int($id) || is_float($id)) {
            $id = (string) (int) $id;
        }
        $id = trim((string) $id);
        if ($id === '' || !preg_match('/^\d{1,5}$/', $id)) {
            return '';
        }

        return str_pad($id, 5, '0', STR_PAD_LEFT);
    }

    private static function extractNorad($line1)
    {
        if (strlen($line1) < 7) {
            return '';
        }

        return self::normalizeNorad(substr($line1, 2, 5));
    }

    /**
     * Standard TLE modulo-10 checksum over the first 68 characters.
     */
    public static function checksumOk($line)
    {
        $length = strlen($line);
        if ($length < 69) {
            return true; // cannot verify; do not reject data we cannot judge
        }
        $sum = 0;
        for ($i = 0; $i < 68; $i++) {
            $c = $line[$i];
            if ($c >= '0' && $c <= '9') {
                $sum += (int) $c;
            } elseif ($c === '-') {
                $sum += 1;
            }
        }

        return ctype_digit($line[68]) && ((int) $line[68]) === ($sum % 10);
    }

    /**
     * @return array{unix:int,iso:string}
     */
    private static function epochFromLine($line1)
    {
        if (strlen($line1) < 32) {
            return array('unix' => 0, 'iso' => '');
        }
        $yy = substr($line1, 18, 2);
        $doy = substr($line1, 20, 12);
        if (!ctype_digit($yy) || !preg_match('/^\d{1,3}(\.\d+)?$/', trim($doy))) {
            return array('unix' => 0, 'iso' => '');
        }

        $year = (int) $yy;
        $year += $year < 57 ? 2000 : 1900;
        $doy = (float) $doy;
        if ($doy < 1 || $doy > 367) {
            return array('unix' => 0, 'iso' => '');
        }

        $day = (int) floor($doy);
        $fraction = $doy - $day;
        $unix = gmmktime(0, 0, 0, 1, 1, $year) + ($day - 1) * 86400 + (int) round($fraction * 86400);

        return array('unix' => (int) $unix, 'iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $unix));
    }
}
