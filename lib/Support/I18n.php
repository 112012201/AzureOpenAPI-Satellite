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

    /** What the documents are actually written in. One block per entry in the DOM. */
    const CONTENT_LOCALES = array('zh-Hans', 'zh-Hant', 'en');

    /** The two English flavours the site presents. Same text, different tag. */
    const ENGLISH_VARIANTS = array('en-US', 'en-GB');

    /**
     * English region subtags that read British rather than American: spelling and
     * date order follow en-GB for these, en-US for everything else. Only the TAG
     * changes -- there is one English text, deliberately, because inventing
     * "organisation"/"organization" variants of every sentence would double the
     * document to say the same thing.
     */
    const GB_SUBTAGS = array('gb', 'uk', 'ie', 'au', 'nz', 'za', 'in', 'sg', 'my', 'hk', 'mt', 'cy');

    /** @var array<string,array<string,string>>|null */
    private static $strings = null;

    /** @var array<string,mixed>|null */
    private static $locale = null;

    /** Locales that are Traditional Chinese rather than Simplified. */
    const TRADITIONAL_REGIONS = array('tw', 'hk', 'mo', 'hant');

    public static function locales()
    {
        return self::CONTENT_LOCALES;
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

            // The landing page's prose lives in its own table. It is an order of
            // magnitude larger than the chrome strings, changes for different
            // reasons, and merging it here keeps every caller on one lookup.
            $home = Config::path('conf/i18n-home.php');
            if (is_file($home)) {
                $extra = require $home;
                if (is_array($extra)) {
                    foreach ($extra as $code => $pairs) {
                        if (!is_array($pairs)) {
                            continue;
                        }
                        $base = isset(self::$strings[$code]) && is_array(self::$strings[$code])
                            ? self::$strings[$code]
                            : array();
                        self::$strings[$code] = array_merge($base, $pairs);
                    }
                }
            }
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
     * One key rendered as a block of per-locale spans, one of them active.
     *
     * The document carries every language and the script only toggles visibility, so
     * a visitor without JavaScript still reads the language the server picked and a
     * switch needs no request. The markup contract is the one assets/consent.js
     * binds to: `data-locale` on each span, `is-active` on the visible one.
     */
    public static function block($active, $key, $fallback = '')
    {
        $out = '';
        foreach (self::CONTENT_LOCALES as $code) {
            $text = self::translate($code, $key, $fallback === '' ? $key : $fallback);
            $isActive = $code === $active;
            $out .= '<span class="i18n' . ($isActive ? ' is-active' : '') . '"'
                . ' lang="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
                . ($isActive ? '' : ' hidden') . '>'
                . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                . '</span>';
        }

        return $out;
    }

    /**
     * Resolve the locale for a request.
     *
     * The ADDRESS decides the language:
     *
     *   mainland China            zh-Hans
     *   Hong Kong / Macao / Taiwan  zh-Hant
     *   everywhere else           en-US or en-GB, by the browser's English subtag
     *
     * A visitor's own choice (`?lang=`, then the cookie) wins over the address, but
     * only among the languages that zone offers: a mainland reader may read English,
     * a reader outside the mainland may read either Chinese script. Accept-Language
     * no longer chooses the language -- it only says which flavour of English an
     * English reader expects. That is the point of serving three zones: what a
     * visitor is shown should follow where they are, not which browser they installed.
     *
     * @param \SatApi\Http\Request|null $request
     * @return array{code:string, tag:string, source:string, requested:string, zone:string, allowed:array<int,string>, en_tag:string}
     */
    public static function resolve($request = null)
    {
        if (self::$locale !== null && $request === null) {
            return self::$locale;
        }

        $zone = Region::INTL;
        $requested = '';
        if ($request !== null) {
            $region = Region::forRequest($request);
            $zone = (string) $region['zone'];
            $requested = (string) $request->header('accept-language', '');
        }
        $allowed = self::allowed($zone);
        $enTag = self::englishVariant($requested);

        $explicit = '';
        $source = 'region';
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
        }

        if ($explicit !== '') {
            $tag = self::canonicalTag($explicit);
            // A choice the zone does not offer is not honoured: a mainland visitor
            // holding a zh-Hant cookie from before this rule existed still gets the
            // mainland document, and so does everyone else with no valid choice.
            if ($tag !== null && in_array(self::contentOf($tag), $allowed, true)) {
                return self::remember(array(
                    'code'      => self::contentOf($tag),
                    'tag'       => $tag,
                    'source'    => $source,
                    'requested' => $requested,
                    'zone'      => $zone,
                    'allowed'   => $allowed,
                    'en_tag'    => $enTag,
                ), $request === null);
            }
        }

        if ($zone === Region::CN) {
            $tag = 'zh-Hans';
        } elseif ($zone === Region::HMT) {
            $tag = 'zh-Hant';
        } else {
            $tag = $enTag;
        }

        return self::remember(array(
            'code'      => self::contentOf($tag),
            'tag'       => $tag,
            'source'    => 'region',
            'requested' => $requested,
            'zone'      => $zone,
            'allowed'   => $allowed,
            'en_tag'    => $enTag,
        ), $request === null);
    }

    /**
     * Cache a resolution for the no-request case only.
     *
     * With a request the answer belongs to that request, and a static would serve it
     * to the next one -- which is exactly how a test suite starts reporting another
     * case's answer.
     */
    private static function remember(array $resolved, $cache)
    {
        if ($cache) {
            self::$locale = $resolved;
        }

        return $resolved;
    }

    /** The languages one zone offers, in switcher order. */
    public static function allowed($zone)
    {
        if ($zone === Region::CN) {
            return array('zh-Hans', 'en');
        }

        return self::CONTENT_LOCALES;
    }

    /** The content locale behind a tag ('en-GB' -> 'en'). */
    public static function contentOf($tag)
    {
        $tag = (string) $tag;
        if (strpos($tag, 'en') === 0) {
            return 'en';
        }
        if (in_array($tag, self::CONTENT_LOCALES, true)) {
            return $tag;
        }
        $content = self::normalise($tag);

        return $content === null ? 'en' : $content;
    }

    /**
     * Canonicalise a requested tag, keeping an English region subtag.
     *
     * 'en-GB' -> 'en-GB', 'en' / 'en-CA' / 'en' -> 'en-US', 'zh-TW' -> 'zh-Hant',
     * 'fr' -> null.
     *
     * @return string|null
     */
    public static function canonicalTag($tag)
    {
        $content = self::normalise($tag);
        if ($content === null) {
            return null;
        }
        if ($content !== 'en') {
            return $content;
        }
        $lower = strtolower(str_replace('_', '-', trim((string) $tag)));
        foreach (explode('-', $lower) as $part) {
            if (in_array($part, self::GB_SUBTAGS, true)) {
                return 'en-GB';
            }
            if ($part === 'us' || $part === 'ca') {
                return 'en-US';
            }
        }

        return 'en-US';
    }

    /** Which English flavour this browser expects: 'en-GB' or 'en-US'. */
    public static function englishVariant($acceptLanguage)
    {
        $region = self::bestEnglishRegion($acceptLanguage);

        return in_array($region, self::GB_SUBTAGS, true) ? 'en-GB' : 'en-US';
    }

    /**
     * The region subtag of the highest-weighted English tag, or '' for a bare "en".
     *
     * Only English entries are considered: a browser that prefers German and lists
     * en-US second still reads English here, and the flavour should come from the
     * English entry, not from the German one.
     */
    private static function bestEnglishRegion($header)
    {
        $header = trim((string) $header);
        if ($header === '') {
            return '';
        }
        $best = '';
        $bestQ = -1.0;
        foreach (explode(',', $header) as $piece) {
            $bits = explode(';', trim($piece));
            $tag = strtolower(str_replace('_', '-', trim($bits[0])));
            if ($tag === '' || strpos($tag, 'en') !== 0) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                $param = trim($param);
                if (strpos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }
            if ($q <= $bestQ) {
                continue;
            }
            $parts = explode('-', $tag);
            $best = isset($parts[1]) ? $parts[1] : '';
            $bestQ = $q;
        }

        return $best;
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
     * The Set-Cookie value for an explicit choice.
     *
     * The canonical TAG is stored, not the content locale, so a visitor who chose
     * "English (UK)" still gets en-GB on the next request.
     */
    public static function localeCookie($locale)
    {
        $tag = self::canonicalTag($locale);
        if ($tag === null) {
            return '';
        }
        $days = max(1, (int) Config::get('cookies.lifetime_days', 180));

        return self::COOKIE_NAME . '=' . rawurlencode($tag)
            . '; Path=/; Max-Age=' . ($days * 86400) . '; SameSite=Lax; Secure';
    }

    /**
     * The entries a switcher may show, in display order.
     *
     * Filtered by zone: the mainland page offers Simplified Chinese and English,
     * everything else offers all three. The English entry carries the TAG this
     * request resolves to (en-US or en-GB) so choosing it keeps the flavour.
     *
     * @param array<string,mixed>|null $locale a value from resolve()
     * @return array<int,array{code:string,content:string,name:string,html_lang:string,dir:string}>
     */
    public static function switcher($locale = null)
    {
        $allowed = is_array($locale) && isset($locale['allowed']) && is_array($locale['allowed'])
            ? $locale['allowed']
            : self::CONTENT_LOCALES;
        $enTag = is_array($locale) && isset($locale['en_tag']) ? (string) $locale['en_tag'] : 'en-US';

        $out = array();
        foreach ($allowed as $content) {
            $tag = $content === 'en' ? $enTag : $content;
            $out[] = array(
                'code'      => $tag,
                'content'   => $content,
                // Endonym: a language picker in English is a picker only some
                // visitors can read.
                'name'      => self::label($tag),
                // BCP 47 as it should appear in the html lang attribute.
                'html_lang' => $tag,
                'dir'       => 'ltr',
            );
        }

        return $out;
    }

    /** The name of a tag in its own language, as the switcher shows it. */
    public static function label($tag)
    {
        if ($tag === 'en-GB') {
            return 'English (UK)';
        }
        if ($tag === 'en-US') {
            return 'English (US)';
        }

        return self::translate(self::contentOf($tag), 'locale.name', (string) $tag);
    }
}
