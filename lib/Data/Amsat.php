<?php

namespace SatApi\Data;

use SatApi\Config;
use SatApi\Logger;

/**
 * Parser for the AMSAT live status page (https://www.amsat.org/status/).
 *
 * The page is legacy HTML with two parallel structures:
 *   - anchor list  : <a href="#AO-91">AO-91_[FM]</a>  (satellite order)
 *   - tips arrays  : tips.aN = new Array(id, x, y, 'sat<br>call<br>grid<br>date<br>time<br>...')
 *
 * Improvements over the previous implementation:
 *   - satellite names are normalised (mode suffixes, "AO-07" -> "AO-7"),
 *   - NORAD ids come from the freshly fetched feeds first, then a corrected
 *     static fallback (the old table had AO-91 / UO-11 mapped to wrong ids),
 *   - reports are validated and timed in UTC explicitly.
 */
final class Amsat
{
    /**
     * @param string $html
     * @param array  $noradMap
     * @return array{reports:array<int,array>,satellites:array<int,string>,warnings:array<int,string>}
     */
    public static function parse($html, array $noradMap)
    {
        $warnings = array();
        $satellites = self::extractSatelliteList($html);

        if (!preg_match_all(
            '/tips\.a(\d+)\s*=\s*new Array\(\s*(\d+),\s*(\d+),\s*(\d+),\s*\'([^\']*)\'\s*\);/',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return array('reports' => array(), 'satellites' => $satellites, 'warnings' => array('no tips arrays found'));
        }

        $tips = array();
        foreach ($matches as $m) {
            $tips[(int) $m[1]] = $m[5];
        }
        ksort($tips);

        if (!empty($satellites) && count($satellites) !== count($tips)) {
            $warnings[] = 'satellite count (' . count($satellites) . ') differs from report group count (' . count($tips) . ')';
        }

        $reports = array();
        $fallbackSatellite = null;
        foreach ($tips as $index => $content) {
            $satellite = isset($satellites[$index]) ? $satellites[$index] : $fallbackSatellite;
            if ($satellite !== null) {
                $fallbackSatellite = $satellite;
            }

            foreach (self::splitBlocks($content) as $block) {
                $report = self::parseBlock($block, $satellite, $noradMap);
                if ($report !== null) {
                    $reports[] = $report;
                }
            }
        }

        usort($reports, static function ($a, $b) {
            return $b['timestamp_unix'] <=> $a['timestamp_unix'];
        });

        return array('reports' => $reports, 'satellites' => $satellites, 'warnings' => $warnings);
    }

    /**
     * @return string[]
     */
    public static function extractSatelliteList($html)
    {
        $satellites = array();

        if (preg_match_all('/<a\s+href="#[^"]+"[^>]*>([^<]+)<\/a>/i', $html, $matches)) {
            foreach ($matches[1] as $candidate) {
                $name = self::normalizeSatelliteName($candidate);
                if ($name !== '') {
                    $satellites[] = $name;
                }
            }
            $satellites = array_values(array_unique($satellites));
        }

        if (empty($satellites) && preg_match('/<select id="satsel"[^>]*>(.*?)<\/select>/is', $html, $select)) {
            if (preg_match_all('/<option value="([^"]+)"/i', $select[1], $options)) {
                foreach ($options[1] as $value) {
                    $name = self::normalizeSatelliteName($value);
                    if ($name !== '') {
                        $satellites[] = $name;
                    }
                }
            }
        }

        return $satellites;
    }

    public static function normalizeSatelliteName($raw)
    {
        $name = trim(html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8'));
        // Drop the mode suffix used by the page: "AO-91_[FM]".
        $name = preg_replace('/_?\[[^\]]*\]$/', '', $name);
        $name = trim($name, " \t\n\r\0\x0B_");
        if ($name === '') {
            return '';
        }

        // "AO-07" -> "AO-7", keeps the static map and TLE names aligned.
        if (preg_match('/^([A-Z]{2,3})-0*(\d+)([A-Z]?)$/', $name, $m)) {
            return $m[1] . '-' . $m[2] . $m[3];
        }
        if (strcasecmp($name, 'ISS') === 0) {
            return 'ISS';
        }

        return preg_match('/^[A-Za-z0-9\'\-\. ]{1,32}$/', $name) ? $name : '';
    }

    /**
     * @return string[]
     */
    private static function splitBlocks($content)
    {
        $blocks = preg_split('/<br\s*\/?>\s*<br\s*\/?>/i', (string) $content);
        if (!is_array($blocks)) {
            return array();
        }

        return array_filter(array_map('trim', $blocks), static function ($block) {
            return $block !== '';
        });
    }

    private static function parseBlock($block, $satellite, array $noradMap)
    {
        $parts = preg_split('/<br\s*\/?>/i', $block);
        if (!is_array($parts)) {
            return null;
        }
        $parts = array_map(static function ($value) {
            return trim(strip_tags((string) $value));
        }, $parts);
        $parts = array_values(array_filter($parts, static function ($value) {
            return $value !== '';
        }));

        if (count($parts) < 3) {
            return null;
        }

        $status = $parts[0];
        $callsign = isset($parts[1]) ? $parts[1] : '';
        $grid = isset($parts[2]) ? $parts[2] : '';
        $date = isset($parts[3]) ? $parts[3] : '';
        $timeRaw = isset($parts[4]) ? $parts[4] : '';

        $timestamp = self::parseTimestamp($date, $timeRaw);

        $norad = null;
        if ($satellite !== null && isset($noradMap[$satellite])) {
            $norad = $noradMap[$satellite];
        }

        return array(
            'status'         => $status,
            'callsign'       => $callsign,
            'grid'           => $grid,
            'satellite'      => $satellite,
            'norad_id'       => $norad,
            'timestamp'      => $timestamp['iso'],
            'timestamp_unix' => $timestamp['unix'],
        );
    }

    /**
     * @return array{unix:int,iso:string}
     */
    private static function parseTimestamp($date, $timeRaw)
    {
        $date = trim((string) $date);
        $timeRaw = trim((string) $timeRaw);

        $hour = 0;
        $minute = 0;
        $second = 0;
        if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*:(\d{2})/', $timeRaw, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            $second = (int) $m[3];
        } elseif (preg_match('/(\d{1,2}):(\d{2})/', $timeRaw, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        }

        $parsed = strtotime($date . ' UTC');
        if ($parsed === false) {
            $parsed = time();
        }
        $unix = gmmktime($hour, $minute, $second, (int) gmdate('n', $parsed), (int) gmdate('j', $parsed), (int) gmdate('Y', $parsed));
        if ($unix === false) {
            $unix = time();
        }

        return array('unix' => (int) $unix, 'iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $unix));
    }

    /**
     * Build a callsign/short-name -> NORAD map from parsed TLE records.
     *
     * @param array<string,array> $records
     * @return array<string,string>
     */
    public static function noradMapFromRecords(array $records)
    {
        $map = array();
        foreach ($records as $record) {
            $name = isset($record['name']) ? (string) $record['name'] : '';
            if ($name === '') {
                continue;
            }
            $short = self::normalizeSatelliteName(self::firstToken($name));
            if ($short !== '' && !isset($map[$short])) {
                $map[$short] = $record['norad_id'];
            }
            $normalized = self::normalizeSatelliteName($name);
            if ($normalized !== '' && !isset($map[$normalized])) {
                $map[$normalized] = $record['norad_id'];
            }
        }

        return $map;
    }

    private static function firstToken($name)
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        if (!is_array($parts) || empty($parts)) {
            return '';
        }
        $token = $parts[0];
        // Ignore the 3LE line number if it survived the cleanup.
        if ($token === '0' && isset($parts[1])) {
            $token = $parts[1];
        }

        return $token;
    }

    /**
     * Static fallback map (config: norad_fallback).
     *
     * @return array<string,string>
     */
    public static function fallbackMap()
    {
        $map = array();
        foreach ((array) Config::get('norad_fallback', array()) as $name => $norad) {
            $normalized = self::normalizeSatelliteName($name);
            if ($normalized !== '') {
                $map[$normalized] = (string) $norad;
            }
        }

        return $map;
    }

    public static function mergeMaps(array $primary, array $fallback)
    {
        foreach ($fallback as $name => $norad) {
            if (!isset($primary[$name])) {
                $primary[$name] = $norad;
            }
        }
        if (empty($primary)) {
            Logger::warning('amsat_norad_map_empty');
        }

        return $primary;
    }
}
