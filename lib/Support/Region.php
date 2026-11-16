<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * Which jurisdiction a request is served under.
 *
 * The service publishes two versions of its terms and privacy policy: one for
 * mainland China (excluding Hong Kong, Macao and Taiwan) and one for everywhere
 * else. Which one a visitor gets is decided from the CLIENT ADDRESS, never from a
 * query parameter and never from the CDN's opinion alone.
 *
 * WHY THE ADDRESS AND NOT A HEADER
 *
 * A CDN that "knows" the country is convenient and occasionally wrong: the
 * authoritative answer is the address of the connection that reached us. So the
 * address is resolved from the edge headers when they exist (they carry the real
 * client, not the edge) and matched against our own copy of the APNIC registry,
 * rebuilt by bin/build-cn-ranges.php. No third-party geolocation call at request
 * time, no network dependency, and the answer is reproducible: the same address
 * always gets the same document.
 *
 * Hong Kong, Macao and Taiwan are separate APNIC allocations, so "in the CN table"
 * is exactly "mainland" -- the exclusion the policy requires falls out of the data
 * rather than out of a second rule that could drift.
 *
 * THREE ZONES, TWO RULES
 *
 * The site's language rule is not the same cut as its jurisdiction rule:
 *
 *   mainland        mainland documents   Simplified Chinese
 *   Hong Kong/Macao/Taiwan  international documents   Traditional Chinese
 *   everywhere else          international documents   English (US or GB)
 *
 * So the address is matched against two tables. Being outside the CN table is not
 * enough to decide the language -- an address in neither table gets English.
 *
 * WHAT THIS IS NOT
 *
 * This is a routing decision for which document to publish, not an access control.
 * Nothing is blocked by it: the mainland visitor reads the mainland policy and the
 * note about links that are not offered there, and every API route behaves the same
 * for everybody.
 */
final class Region
{
    /**
     * Jurisdiction and language zone for a request.
     *
     *   cn    mainland China (CC=CN allocations) -- mainland documents, zh-Hans
     *   hmt   Hong Kong, Macao, Taiwan -- international documents, zh-Hant
     *   intl  everywhere else -- international documents, en-US or en-GB
     *
     * The middle one exists because the LANGUAGE rule and the JURISDICTION rule
     * are different cuts of the same map: Hong Kong, Macao and Taiwan are outside
     * the mainland policy (so they read the international terms) yet they read
     * Traditional Chinese. Folding them into `intl` would send them English.
     */
    const CN = 'cn';
    const HMT = 'hmt';
    const INTL = 'intl';

    /** @var array<string,array<int,array{0:int,1:int}|array{0:string,1:int}>> loaded tables */
    private static $tables = array();

    /** @var array<string,string> resolved address cache for this request */
    private static $ipCache = array();

    /**
     * Headers that carry the real client address when a CDN sits in front.
     *
     * Listed in the order the vendors this deployment actually uses write them:
     * EdgeOne sets EO-Connecting-IP, the SCDN sets X-Client-IP, and every proxy in
     * between appends to X-Forwarded-For. A header is only believed when it parses
     * as a public address; a private or malformed value is skipped rather than
     * trusted, because that is what an attacker would send first.
     */
    const ADDRESS_HEADERS = array(
        'eo-connecting-ip',
        'x-client-ip',
        'cf-connecting-ip',
        'true-client-ip',
        'x-real-ip',
    );

    /**
     * The client address, or '' when nothing usable was supplied.
     */
    public static function clientIp(Request $request)
    {
        foreach (self::ADDRESS_HEADERS as $header) {
            $value = trim((string) $request->header($header, ''));
            $ip = self::firstPublic($value);
            if ($ip !== '') {
                self::$ipCache[$header] = $ip;

                return $ip;
            }
        }

        // X-Forwarded-For is a chain, oldest first. The RIGHT-most public entry is the
        // one the nearest trusted proxy appended; the left-most is whatever the client
        // chose to send, so it is only used when nothing else is available.
        $forwarded = trim((string) $request->header('x-forwarded-for', ''));
        $ip = self::firstPublic($forwarded, true);
        if ($ip !== '') {
            return $ip;
        }

        $remote = trim((string) $request->header('remote-addr', ''));
        if ($remote === '') {
            $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        }
        $ip = self::firstPublic($remote);

        return $ip;
    }

    /**
     * First address in a comma separated list that is a valid, public IP.
     *
     * @param bool $rightToLeft read the chain from the proxy end
     */
    private static function firstPublic($value, $rightToLeft = false)
    {
        if ($value === '') {
            return '';
        }
        $parts = array_map('trim', explode(',', $value));
        if ($rightToLeft) {
            $parts = array_reverse($parts);
        }
        foreach ($parts as $candidate) {
            // Strip a port ("1.2.3.4:5678") and any IPv6 brackets.
            $candidate = trim($candidate, "[]");
            if (preg_match('/^(.+):\d+$/', $candidate, $m) === 1 && strpos($candidate, ':') === strrpos($candidate, ':')) {
                $candidate = $m[1];
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!self::isPublic($candidate)) {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    /** Private, loopback, link-local and reserved space is never a client address. */
    public static function isPublic($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    /**
     * The zone for this request.
     *
     * `?region=cn|hmt|intl` exists so an operator (or a mainland reader behind a
     * proxy) can deliberately read another version; it is a document selector,
     * not a security boundary, and the response is never cached when it is used.
     *
     * @return array{zone:string,region:string,ip:string,source:string,mainland:bool,ranges_seen:bool,hmt_seen:bool}
     */
    public static function forRequest(Request $request)
    {
        $override = strtolower(trim((string) $request->param('region', '')));
        if ($override === 'hk' || $override === 'mo' || $override === 'tw') {
            $override = self::HMT;
        }
        if ($override === self::CN || $override === self::HMT || $override === self::INTL) {
            return array(
                'zone'        => $override,
                'region'      => $override === self::CN ? self::CN : self::INTL,
                'ip'          => self::clientIp($request),
                'source'      => 'query',
                'mainland'    => $override === self::CN,
                'ranges_seen' => self::rangesSource() !== '',
                'hmt_seen'    => self::hmtRangesSource() !== '',
            );
        }

        $ip = self::clientIp($request);
        $zone = self::zoneForIp($ip);

        return array(
            'zone'        => $zone,
            'region'      => $zone === self::CN ? self::CN : self::INTL,
            'ip'          => $ip,
            'source'      => $ip === '' ? 'none' : 'address',
            'mainland'    => $zone === self::CN,
            'ranges_seen' => self::rangesSource() !== '',
            'hmt_seen'    => self::hmtRangesSource() !== '',
        );
    }

    /**
     * The zone an address belongs to: mainland, Greater China outside it, or the
     * rest of the world. An address in neither table is `intl`, which is the safe
     * direction: the mainland documents carry local obligations and must not be
     * shown outside that jurisdiction by accident.
     */
    public static function zoneForIp($ip)
    {
        if ($ip === '') {
            return self::INTL;
        }
        if (self::inMainland($ip)) {
            return self::CN;
        }
        if (self::inHmt($ip)) {
            return self::HMT;
        }

        return self::INTL;
    }

    /** True when the address falls inside a mainland allocation. */
    public static function inMainland($ip)
    {
        return self::inTable($ip, self::CN);
    }

    /** True when the address falls inside a Hong Kong, Macao or Taiwan allocation. */
    public static function inHmt($ip)
    {
        return self::inTable($ip, self::HMT);
    }

    /** Match one address against one compiled table. */
    private static function inTable($ip, $which)
    {
        $ranges = self::table($which);
        if ($ranges === null) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $value = unpack('N', $packed)[1];
            foreach ($ranges[0] as $range) {
                if ($value >= $range[0] && $value <= $range[1]) {
                    return true;
                }
            }

            return false;
        }

        foreach ($ranges[1] as $range) {
            if (self::prefixMatch($packed, $range[0], $range[1])) {
                return true;
            }
        }

        return false;
    }

    /** Compare the first $bits bits of two packed addresses. */
    private static function prefixMatch($packed, $network, $bits)
    {
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (strncmp($packed, $network, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $rest) & 0xFF;
        $a = ord($packed[$bytes]) & $mask;
        $b = ord($network[$bytes]) & $mask;

        return $a === $b;
    }

    /**
     * One compiled table, or null when it has not been built.
     *
     * Missing is not an error: it means every request that would have matched it
     * resolves to the next zone out, which is the safe direction (the
     * international document is the one with no local obligations attached).
     */
    private static function table($which)
    {
        if (array_key_exists($which, self::$tables)) {
            return self::$tables[$which];
        }
        $file = $which === self::HMT ? self::hmtRangesSource() : self::rangesSource();
        if ($file === '' || !is_file($file)) {
            Logger::debug('region_table_missing', array('table' => $which, 'file' => $file));
            self::$tables[$which] = null;

            return null;
        }
        $data = @include $file;
        if (!is_array($data) || !isset($data[0], $data[1]) || !is_array($data[0])) {
            Logger::warning('region_table_invalid', array('table' => $which, 'file' => $file));
            self::$tables[$which] = null;

            return null;
        }
        self::$tables[$which] = $data;

        return $data;
    }

    private static function rangesSource()
    {
        $path = (string) Config::get('region.ranges', 'data/cn-ranges.php');

        return $path === '' ? '' : Config::path($path);
    }

    private static function hmtRangesSource()
    {
        $path = (string) Config::get('region.hmt_ranges', 'data/hmt-ranges.php');

        return $path === '' ? '' : Config::path($path);
    }

    /** The compiled mainland table's path (for the CLI report). */
    public static function rangesPath()
    {
        return self::rangesSource();
    }

    /** The compiled Hong Kong / Macao / Taiwan table's path. */
    public static function hmtRangesPath()
    {
        return self::hmtRangesSource();
    }

    /** How many prefixes the mainland table holds, for diagnostics. */
    public static function rangeCount()
    {
        return self::countOf(self::CN);
    }

    /** How many prefixes the Hong Kong / Macao / Taiwan table holds. */
    public static function hmtRangeCount()
    {
        return self::countOf(self::HMT);
    }

    private static function countOf($which)
    {
        $ranges = self::table($which);
        if ($ranges === null) {
            return array(0, 0);
        }

        return array(count($ranges[0]), count($ranges[1]));
    }
}
