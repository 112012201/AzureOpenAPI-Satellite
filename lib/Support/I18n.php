<?php

namespace SatApi\Support;

use SatApi\Config;

/**
 * Locale detection and string lookup for the three shipped locales.
 *
 * Resolution order (first hit wins):
 *
 *   1. `?lang=`            -- an explicit choice, including one made by the
 *                             language switcher, which stores it in a cookie
 *                             (`satapi-lang`) read here as a fallback;
 *   2. `Accept-Language`   -- what the browser actually asked for;
 *   3. `en`.
 *
 * Chinese deserves a note: a bare `zh` is treated as Simplified, and the region
 * decides between Simplified and Traditional (`zh-TW`, `zh-HK`, `zh-MO` and the
 * explicit `zh-Hant` scripts are Traditional; `zh-CN`, `zh-SG`, `zh-Hans` are
 * Simplified). Getting this wrong sends a Taiwanese visitor mainland vocabulary,
 * which is the most visible way to look careless in that market.
 *
 * The page renders ALL locales into the document and marks one active, so:
 *   - a visitor with JavaScript disabled still reads the right language, because
 *     the server already picked one;
 *   - switching language is instant and needs no reload or extra request;
 *   - each locale's text is present for crawlers rather than injected after load.
 * The cost is document size, which for this content is a few kilobytes of text.
 */
final class I18n
{
    const COOKIE_NAME = 'satapi-lang';

    /** @var array<string,array<string,string>>|null */
    private static $strings = null;

    /** @var array<string,string>|null */
    private static $locale = null;

    /** Locales that are Traditional Chinese rather than Simplified. */
    const TRADITIONAL_REGIONS = array('tw', 'hk', 'mo', 'hant');

    public static function locales()
    {
        return array('zh-Hans', 'zh-Hant', 'en');
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function strings()
    {
        if (self::$strings === null) {
            $file = Config::path('conf/i18n.php');
            $data = is_file($file) ? require $file : array();
            self::$strings = is_array($data) ? $data : array();
        }

        return self::$strings;
    }

    public static function translate($locale, $key, $fallback = '')
    {
        $all = self::strings();
        if (isset($all[$locale][$key])) {
            return (string) $all[$locale][$key];
        }
        if (isset($all['en'][$key])) {
            return (string) $all['en'][$key];
        }

        return $fallback === '' ? (string) $key : (string) $fallback;
    }

    /**
     * Resolve the locale for a request.
     *
     * @param \SatApi\Http\Request|null $request
     * @return array{code:string, source:string, requested:string}
     */
    public static function resolve($request = null)
    {
        if (self::$locale !== null && $request === null) {
            return self::$locale;
        }

        $explicit = '';
        $source = 'default';
        $requested = '';

        if ($request !== null) {
            $q = trim((string) $request->param('lang', ''));
            if ($q !== '') {
                $explicit = $q;
                $source = 'query';
            } else {
                foreach (explode(';', (string) $request->header('cookie', '')) as $piece) {
                    $piece = trim($piece);
                    if (strpos($piece, self::COOKIE_NAME . '=') === 0) {
                        $explicit = rawurldecode(substr($piece, strlen(self::COOKIE_NAME) + 1));
                        $source = 'cookie';
                    }
                }
            }
            $requested = (string) $request->header('accept-language', '');
        }

        if ($explicit !== '') {
            $resolved = self::normalise($explicit);
            if ($resolved !== null) {
                $resolved = array('code' => $resolved, 'source' => $source, 'requested' => $requested);
                if ($request === null) {
                    self::$locale = $resolved;
                }

                return $resolved;
            }
        }

        $fromHeader = self::fromAcceptLanguage($requested);
        if ($fromHeader !== null) {
            $resolved = array('code' => $fromHeader, 'source' => 'accept-language', 'requested' => $requested);
            if ($request === null) {
                self::$locale = $resolved;
            }

            return $resolved;
        }

        $resolved = array('code' => 'en', 'source' => 'default', 'requested' => $requested);
        if ($request === null) {
            self::$locale = $resolved;
        }

        return $resolved;
    }

    /**
     * Map one locale tag onto a shipped locale, or null when it is not Chinese
     * or English at all.
     *
     * @return string|null
     */
    public static function normalise($tag)
    {
        $tag = strtolower(trim((string) $tag));
        if ($tag === '') {
            return null;
        }
        // Accept "zh_Hant_TW", "zh-Hant-TW", "zh-TW", "zh".
        $tag = str_replace('_', '-', $tag);
        $parts = explode('-', $tag);
        $primary = $parts[0];

        if ($primary === 'en') {
            return 'en';
        }
        if ($primary !== 'zh') {
            return null;
        }
        foreach ($parts as $part) {
            if (in_array($part, self::TRADITIONAL_REGIONS, true)) {
                return 'zh-Hant';
            }
            if (in_array($part, array('cn', 'sg', 'hans', 'my'), true)) {
                return 'zh-Hans';
            }
        }
        // A script subtag we did not recognise, or a bare "zh": Simplified.
        return 'zh-Hans';
    }

    /**
     * Parse an Accept-Language header, honouring q-values.
     *
     * @return string|null
     */
    private static function fromAcceptLanguage($header)
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        $candidates = array();
        foreach (explode(',', $header) as $index => $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $q = 1.0;
            $tag = $piece;
            if (strpos($piece, ';') !== false) {
                $bits = explode(';', $piece);
                $tag = trim($bits[0]);
                foreach (array_slice($bits, 1) as $param) {
                    $param = trim($param);
                    if (strpos($param, 'q=') === 0) {
                        $q = (float) substr($param, 2);
                    }
                }
            }
            $candidates[] = array('tag' => $tag, 'q' => $q, 'i' => $index);
        }
        usort($candidates, static function ($a, $b) {
            if ($a['q'] === $b['q']) {
                return $a['i'] <=> $b['i'];
            }

            return $b['q'] <=> $a['q'];
        });
        foreach ($candidates as $candidate) {
            $resolved = self::normalise($candidate['tag']);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * The Set-Cookie value for an explicit choice.
     */
    public static function localeCookie($locale)
    {
        $locale = self::normalise($locale);
        if ($locale === null) {
            return '';
        }
        $days = max(1, (int) Config::get('cookies.lifetime_days', 180));

        return self::COOKIE_NAME . '=' . rawurlencode($locale)
            . '; Path=/; Max-Age=' . ($days * 86400) . '; SameSite=Lax; Secure';
    }

    /**
     * Every locale rendered into the document, in display order, for the switcher
     * and for the per-locale content blocks.
     *
     * @return array<int,array{code:string,name:string,html_lang:string,dir:string}>
     */
    public static function switcher()
    {
        $out = array();
        foreach (self::locales() as $code) {
            $out[] = array(
                'code'      => $code,
                'name'      => self::translate($code, 'locale.name', $code),
                // BCP 47 as it should appear in the html lang attribute.
                'html_lang' => $code,
                'dir'       => 'ltr',
            );
        }

        return $out;
    }
}
