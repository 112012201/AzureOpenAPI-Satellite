<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\HomePage;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * Holiday themes: the same site in a different coat, for a fixed period.
 *
 * The theme table lives in conf/themes.php. This class answers three questions about
 * it: which theme a request renders in, what that theme adds to the document, and
 * whether the answer is per-visitor.
 *
 * WHERE THE ANSWER COMES FROM
 *
 *   1. `?season=<name>|default`  an explicit choice: how the operator previews a theme
 *                                outside its window, and how a visitor turns the
 *                                current one off for themselves.
 *   2. the `satapi-season` cookie  written by the "leave the theme" badge. It records
 *                                the theme that was refused, NOT "no themes ever", so
 *                                the next holiday still shows up.
 *   3. the WINDOW in conf/themes.php  the schedule. This is what makes the feature
 *                                work without anybody deploying anything on the day.
 *   4. config `theme.season`  a manual override for testing or for pinning the site to
 *                                one theme outside its window.
 *
 * CACHING. `window` and `config` answers are the same document for every visitor and
 * stay cacheable. `query` and `cookie` answers are per-visitor, so callers mark the
 * response `no-store` -- the rule the region and language decisions follow too.
 *
 * WHAT A THEME MAY TOUCH
 *
 * A stylesheet, one canvas effect, optionally music, and the loading screen's tagline.
 * Nothing else: not the markup, not the order of anything, not a single control. A
 * themed page that loses a button is a worse page, not a festive one.
 */
final class Season
{
    /** Cookie that records the theme this visitor refused. */
    const COOKIE = 'satapi-season';

    const DEFAULT_THEME = 'default';

    /** Every window in conf/themes.php is written in this zone. */
    const ZONE = 'Asia/Shanghai';

    /** Where the audio lives, relative to the site root. */
    const MUSIC_PATH = '/assets/music/';

    /** Where the first-screen video lives, relative to the site root. */
    const FILM_PATH = '/assets/film/';

    /** @var array<string,array<string,mixed>>|null */
    private static $themes = null;

    /** @var array{name:string,source:string}|null */
    private static $current = null;

    /** @var array<string,array<string,mixed>|null> memoised films, keyed by theme + locale */
    private static $films = array();

    /** @var array{languages:array<string,mixed>,slogans:array<string,mixed>}|null */
    private static $slogans = null;

    /** @var string|null the locale this request renders in (pinned by boot) */
    private static $locale = null;

    /**
     * The theme table.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function themes()
    {
        if (self::$themes === null) {
            $file = Config::path('conf/themes.php');
            $data = is_file($file) ? require $file : array();
            self::$themes = array();
            foreach ((array) $data as $name => $theme) {
                if (!is_array($theme)) {
                    continue;
                }
                $theme += array(
                    'window'   => null,
                    'css'      => '',
                    'effect'   => '',
                    'greeting' => '',
                    'music'    => array(),
                    'film'     => array(),
                );
                self::$themes[(string) $name] = $theme;
            }
            if (!isset(self::$themes[self::DEFAULT_THEME])) {
                self::$themes[self::DEFAULT_THEME] = array(
                    'window' => null, 'css' => '', 'effect' => '', 'greeting' => '',
                    'music' => array(), 'film' => array(),
                );
            }
        }

        return self::$themes;
    }

    /** @return string[] every theme name, `default` included */
    public static function known()
    {
        return array_keys(self::themes());
    }

    /**
     * The theme the calendar says is running, without any visitor choice.
     *
     * The LAST matching window wins, so a table may be extended with a more specific
     * entry later in the file and the override falls out of the order.
     *
     * @param \DateTimeImmutable|null $now Asia/Shanghai; defaults to now
     */
    public static function schedule($now = null)
    {
        $zone = new \DateTimeZone(self::ZONE);
        $at = $now === null ? new \DateTimeImmutable('now', $zone) : $now;
        $matched = self::DEFAULT_THEME;

        foreach (self::themes() as $name => $theme) {
            $window = $theme['window'];
            if (!is_array($window) || count($window) !== 2) {
                continue;
            }
            $start = self::moment((string) $window[0], $zone);
            $end = self::moment((string) $window[1], $zone);
            if ($start === null || $end === null) {
                continue;
            }
            if ($at >= $start && $at < $end) {
                $matched = (string) $name;
            }
        }

        return $matched;
    }

    /** Parse one window edge, or null when the config is wrong. */
    private static function moment($value, \DateTimeZone $zone)
    {
        try {
            return new \DateTimeImmutable($value, $zone);
        } catch (\Throwable $e) {
            Logger::warning('theme_window_invalid', array('value' => $value));

            return null;
        }
    }

    /**
     * Which theme this request renders in, and where that decision came from.
     *
     * @return array{name:string,source:string}
     */
    public static function forRequest(?Request $request = null)
    {
        $themes = self::themes();
        $auto = self::schedule();
        $name = $auto;
        $source = $auto === self::DEFAULT_THEME ? 'schedule' : 'window';

        // A manual pin in config wins over the calendar but not over the visitor.
        $pinned = strtolower(trim((string) Config::get('theme.season', self::DEFAULT_THEME)));
        if ($pinned !== '' && $pinned !== 'auto' && isset($themes[$pinned])) {
            $name = $pinned;
            $source = 'config';
        }

        if ($request === null) {
            return array('name' => $name, 'source' => $source);
        }

        $query = strtolower(trim((string) $request->param('season', '')));
        if ($query !== '') {
            if ($query === self::DEFAULT_THEME) {
                return array('name' => self::DEFAULT_THEME, 'source' => 'query');
            }
            if (isset($themes[$query])) {
                return array('name' => $query, 'source' => 'query');
            }

            // An unknown name is not a theme; fall through to the normal answer.
            return array('name' => $name, 'source' => $source);
        }

        foreach (explode(';', (string) $request->header('cookie', '')) as $piece) {
            $piece = trim($piece);
            if (strpos($piece, self::COOKIE . '=') !== 0) {
                continue;
            }
            $refused = strtolower(rawurldecode(substr($piece, strlen(self::COOKIE) + 1)));
            // Only the theme it names is refused: a visitor who turned Christmas off
            // has not asked to never see the Spring Festival one.
            if ($refused !== '' && $refused === $name && $name !== self::DEFAULT_THEME) {
                return array('name' => self::DEFAULT_THEME, 'source' => 'cookie');
            }
        }

        return array('name' => $name, 'source' => $source);
    }

    /** Resolve once per request and remember it (shell, renderers, tests agree). */
    public static function boot(?Request $request = null)
    {
        /*
         * THE LOCALE IS PINNED HERE, AND THAT IS A BUG FIX.
         *
         * Every string this class writes -- the badge's name and its way out, the music
         * labels, the loading screen's greeting, the band's slogan and the language
         * caption beside it -- used to ask I18n::resolve() with no request. I18n only
         * caches a resolution made WITHOUT one, so that call fell through to a
         * header-less default and answered "en" for everybody: a Chinese document whose
         * corner badge said "Christmas" and whose loading screen said "Merry Christmas!".
         *
         * Resolving once, from the request already being booted, is the whole fix. The
         * page renderers still pass their own locale down to the partials, so this
         * cannot disagree with the document's own language.
         */
        self::$current = self::forRequest($request);
        self::$locale = (string) I18n::resolve($request)['code'];
        // The film is memoised per locale, and this request may have changed it.
        self::$films = array();

        return self::$current;
    }

    /** The locale this request is being rendered in. */
    private static function localeCode()
    {
        if (self::$locale === null) {
            self::$locale = (string) I18n::resolve()['code'];
        }

        return self::$locale;
    }

    /** @return array{name:string,source:string} */
    public static function current()
    {
        if (self::$current === null) {
            self::$current = self::forRequest(null);
        }

        return self::$current;
    }

    /** The active theme's name. */
    public static function name()
    {
        $season = self::current();

        return (string) $season['name'];
    }

    /** True when anything other than the plain site is on. */
    public static function active()
    {
        return self::name() !== self::DEFAULT_THEME;
    }

    /** The active theme's configuration (the default entry when nothing is on). */
    public static function theme()
    {
        $themes = self::themes();
        $name = self::name();

        return isset($themes[$name]) ? $themes[$name] : $themes[self::DEFAULT_THEME];
    }

    /**
     * True when this visitor's theme was chosen by them (query or cookie) rather than
     * by the calendar or by configuration -- the caller must not let a cache key that
     * away.
     */
    public static function pinned()
    {
        $season = self::current();

        return $season['source'] === 'query' || $season['source'] === 'cookie';
    }

    /**
     * The tracks that play right now, as site-relative URLs.
     *
     * A `dates` window replaces the theme's rotation for its duration -- the single-day
     * set pieces (25 December, 6 and 9 February). `now` is injectable so the schedule
     * can be asserted at a real boundary instead of only today.
     *
     * @return string[]
     */
    public static function tracks($now = null)
    {
        $theme = self::theme();
        $music = isset($theme['music']) && is_array($theme['music']) ? $theme['music'] : array();
        $tracks = isset($music['tracks']) && is_array($music['tracks']) ? $music['tracks'] : array();

        $zone = new \DateTimeZone(self::ZONE);
        $at = $now === null ? new \DateTimeImmutable('now', $zone) : $now;
        foreach (isset($music['dates']) && is_array($music['dates']) ? $music['dates'] : array() as $entry) {
            if (!is_array($entry) || count($entry) !== 3 || !is_array($entry[2])) {
                continue;
            }
            $start = self::moment((string) $entry[0], $zone);
            $end = self::moment((string) $entry[1], $zone);
            if ($start === null || $end === null) {
                continue;
            }
            if ($at >= $start && $at < $end) {
                $tracks = $entry[2];
                break;
            }
        }

        $out = self::existing($tracks);
        if ($out === array() && $tracks !== array()) {
            /* A date window whose files are not on disk yet must not silence the theme:
               fall back to the theme's rotation and let the operator drop the file in.
               (Two of the operator's sources arrived as AVS3 audio, which nothing here
               can decode -- see assets/music/README.md.) */
            $out = self::existing(isset($music['tracks']) && is_array($music['tracks']) ? $music['tracks'] : array());
        }

        return $out;
    }

    /**
     * Filter a track list to files that are actually on disk.
     *
     * A missing file is skipped rather than emitted: an <audio> pointing at a 404 is a
     * dead player, and a theme that silently plays nothing is worse than one that plays
     * the track it has.
     *
     * The URL points at the CIPHERTEXT (`<file>.enc`): the plaintext files are not in the
     * web root any more, and the browser decrypts with Web Crypto (assets/media.js) after
     * fetching the key from /media-key. A track is only usable when both halves exist --
     * the ciphertext and its IV in conf/media.php -- so the filter checks both.
     *
     * Audio is not cache-busted by content hash -- hashing a 3 MB file on every request
     * to build a query string is a cost with no benefit, and these files are replaced as
     * a set, never edited in place.
     *
     * @param array<int,mixed> $tracks
     * @return string[]
     */
    private static function existing(array $tracks)
    {
        $out = array();
        foreach ($tracks as $file) {
            $file = trim((string) $file);
            if ($file === '' || strpos($file, '/') !== false || strpos($file, '..') !== false) {
                continue;
            }
            if (!Media::available('assets/music/' . $file, $file)) {
                Logger::debug('theme_track_missing', array('file' => $file));

                continue;
            }
            $out[] = self::MUSIC_PATH . $file . '.enc';
        }

        return $out;
    }

    /**
     * The greeting the loading screen shows, in the request's language.
     */
    public static function greeting()
    {
        $theme = self::theme();
        $key = isset($theme['greeting']) ? (string) $theme['greeting'] : '';
        if ($key === '') {
            return '';
        }
        $code = self::localeCode();

        return (string) I18n::translate($code, $key, '');
    }

    /**
     * The first-screen video for the active theme, or null when it has none.
     *
     * The landing page shows one full-bleed clip below the header. `window` is the
     * schedule; this is the film.
     *
     * TWO ENCODINGS, PICKED BY THE CLIENT. `lg` for a desktop viewport, `sm` for a
     * phone. It has to be the client: <source media> is a still-image mechanism and a
     * video element ignores it, so the choice lives in assets/film.js.
     *
     * A file that is not on disk is dropped, exactly like a missing track -- a hero
     * that 404s is worse than a hero that is not there, and the page stays whole.
     *
     * The `?v=` token is the file's MTIME, not a content hash. HomePage::assetQuery()
     * hashes the bytes it is given, which is right for a 4 KB stylesheet and wrong for
     * an 8 MB clip on every request; the files are replaced by copying a new one in,
     * and that moves the mtime.
     *
     * Memoised PER THEME, not once: the test suite renders several themes in one
     * process, and a single slot would hand the second theme the first one's film.
     *
     * @return array<string,mixed>|null
     */
    public static function film()
    {
        $name = self::name() . '|' . self::localeCode();
        if (array_key_exists($name, self::$films)) {
            return self::$films[$name];
        }

        $theme = self::theme();
        $config = isset($theme['film']) && is_array($theme['film']) ? $theme['film'] : array();
        $sources = isset($config['sources']) && is_array($config['sources']) ? $config['sources'] : array();

        $files = array();
        foreach (array('sm', 'lg') as $key) {
            $file = isset($sources[$key]) ? self::filmAsset((string) $sources[$key], 'video/mp4') : null;
            if ($file !== null) {
                $files[$key] = $file;
            }
        }

        $film = null;
        if ($files !== array()) {
            /* The poster stays a plain JPEG: it is the still the band paints before the
               clip exists, it carries no audio or video, and encrypting it would mean the
               first screen waits on a key before it can show anything. */
            $poster = isset($config['poster']) ? self::filmPoster((string) $config['poster']) : null;
            $film = array(
                'sources' => $files,
                'poster'  => $poster === null ? '' : (string) $poster['url'],
                'lead'    => self::filmLead(),
                'phrases' => self::filmPhrases(),
            );
        }

        self::$films[$name] = $film;

        return $film;
    }

    /**
     * The band's poster, served as-is (not encrypted -- see film()).
     *
     * @return array{url:string,bytes:int}|null
     */
    private static function filmPoster($name)
    {
        $name = trim($name);
        if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) {
            return null;
        }
        $path = Config::path('assets/film/' . $name);
        if (!is_file($path)) {
            return null;
        }

        return array(
            'url'   => self::FILM_PATH . $name . '?v=' . dechex((int) filemtime($path)),
            'bytes' => (int) filesize($path),
        );
    }

    /**
     * One film asset as a URL, or null when it is absent.
     *
     * @return array{url:string,bytes:int}|null
     */
    private static function filmAsset($name, $type)
    {
        $name = trim($name);
        if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) {
            return null;
        }
        /* The URL points at the ciphertext, and the IV travels with the source so
           assets/media.js can decrypt before the element ever sees a src. Both halves
           have to be there: a ciphertext without an IV is a file nobody can open. */
        if (!Media::available('assets/film/' . $name, $name)) {
            Logger::debug('theme_film_missing', array('file' => $name, 'type' => $type));

            return null;
        }
        $entry = Media::entry($name);
        $path = Media::cipherPath('assets/film/' . $name);

        return array(
            'url'   => self::FILM_PATH . $name . '.enc?v=' . dechex((int) filemtime($path)),
            'bytes' => (int) filesize($path),
            'iv'    => (string) $entry['iv'],
            'mime'  => (string) $entry['mime'],
        );
    }

    /**
     * The one line of copy that sits on the film.
     *
     * It is the PAGE-LANGUAGE slogan: "Welcome" on the plain site, the theme's greeting
     * in season. The band types it, deletes it and types the next language, so this
     * value is what a script-less visitor reads and what a screen reader is given --
     * the rotation itself is decoration (see filmPhrases()).
     *
     * Not the loading screen's tagline: that overlay keeps "The Satellite Keeps
     * Spinning..." (or the greeting), and the band says Welcome.
     */
    public static function filmLead($locale = null)
    {
        $phrases = self::filmPhrases($locale);
        if ($phrases !== array()) {
            return (string) $phrases[0]['text'];
        }

        $greeting = self::greeting();
        if ($greeting !== '') {
            return $greeting;
        }

        // The fallback is the same literal as the loading screen's (see partials/shell.php,
        // where the comment explains why that literal, and not config, is the live value).
        return (string) Config::get('service.tagline', 'The Satellite Keeps Spinning...');
    }

    /**
     * The slogan rotation for the active theme, in display order.
     *
     * Each row is `code` (which language), `dir` (which way it reads), `label` (the
     * language's name IN THE PAGE'S LANGUAGE -- "English-US" on the English page,
     * 英语 on the Chinese one) and `text` (the slogan itself).
     *
     * ORDER STARTS ON THE PAGE'S OWN LANGUAGE. A visitor should not have to wait four
     * phrases to read something they understand; the rotation then continues through
     * the table and comes back around. When the page's language is not in the table --
     * an English page is, and every other locale this site serves is one of the three
     * UI locales -- it starts at the top, which is English.
     *
     * `$locale` is injectable so the table can be asserted in a language the CLI never
     * resolves to, the same way tracks() takes a clock.
     *
     * @return array<int,array{code:string,dir:string,label:string,text:string}>
     */
    public static function filmPhrases($locale = null)
    {
        $table = self::slogans();
        $theme = self::name();
        $lines = isset($table['slogans'][$theme]) && is_array($table['slogans'][$theme])
            ? $table['slogans'][$theme]
            : array();
        $locale = $locale === null ? self::localeCode() : (string) $locale;

        $rows = array();
        $mine = null;
        foreach ($table['languages'] as $code => $meta) {
            $code = (string) $code;
            $text = isset($lines[$code]) ? trim((string) $lines[$code]) : '';
            if ($text === '' || !is_array($meta)) {
                continue;
            }
            $label = isset($meta[$locale])
                ? (string) $meta[$locale]
                : (isset($meta['en']) ? (string) $meta['en'] : $code);
            $row = array(
                'code'  => $code,
                'dir'   => (isset($meta['dir']) && $meta['dir'] === 'rtl') ? 'rtl' : 'ltr',
                'label' => $label,
                'text'  => $text,
            );
            if ($mine === null && $code === $locale) {
                $mine = count($rows);
            }
            $rows[] = $row;
        }

        if ($mine !== null && $mine > 0) {
            $first = array_splice($rows, $mine, 1);
            array_unshift($rows, $first[0]);
        }

        return $rows;
    }

    /** The slogan table (conf/slogans.php), loaded once per process. */
    private static function slogans()
    {
        if (self::$slogans === null) {
            $file = Config::path('conf/slogans.php');
            $data = is_file($file) ? require $file : array();
            self::$slogans = array(
                'languages' => isset($data['languages']) && is_array($data['languages']) ? $data['languages'] : array(),
                'slogans'   => isset($data['slogans']) && is_array($data['slogans']) ? $data['slogans'] : array(),
            );
        }

        return self::$slogans;
    }

    /**
     * The <link> lines the head needs for the active theme.
     *
     * @return string[]
     */
    public static function head()
    {
        $theme = self::theme();
        $css = isset($theme['css']) ? trim((string) $theme['css']) : '';
        if (!self::active()) {
            return array();
        }

        /*
         * Three sheets, in this order: the shared chrome (canvas, badge, player), the
         * shared component layer (palette-agnostic rules), then the theme's palette.
         * Last in the cascade, like metro.css -- a theme overrides, it does not
         * negotiate with the layers underneath.
         */
        $out = array(
            '<link rel="stylesheet" href="/assets/theme.css' . HomePage::assetQueryFor('theme.css') . '">',
            '<link rel="stylesheet" href="/assets/holiday.css' . HomePage::assetQueryFor('holiday.css') . '">',
        );
        if ($css !== '') {
            $out[] = '<link rel="stylesheet" href="/assets/' . $css . HomePage::assetQueryFor($css) . '">';
        }

        return $out;
    }

    /** Extra body classes, or '' when the plain site is on. */
    public static function bodyClass()
    {
        return self::active() ? ' ' . self::name() : '';
    }

    /**
     * Extra body attributes for the active theme, or ''.
     *
     * `data-floats="gated"` is the stylesheet's marker that the fixed corner controls -- the
     * theme badge, the music bar and the Cap pill -- start hidden and are faded in by
     * assets/app.js once the visitor scrolls. It is on EVERY page, not only on themed ones:
     * the Cap pill is pinned to the same bottom-left corner on the plain site, where the
     * default film band types its slogan out, so the plain site had exactly the same
     * overlap the theme did.
     *
     * The gate lives in the MARKUP rather than in a class a script adds, because the
     * controls would otherwise paint for one frame before the script takes them away, and
     * because a visitor whose script never runs has no music and no exit button to miss.
     * The verification page builds its own <body> and never calls this, which is what keeps
     * the one page where the widget IS the content out of the gate.
     */
    public static function bodyAttrs()
    {
        return ' data-season="' . htmlspecialchars(self::name(), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-floats="gated"';
    }

    /**
     * The badge that names the theme and lets a visitor leave it.
     *
     * Rendered by the shared shell rather than each page's header: every page has a
     * different header layout, and an escape hatch that exists on three pages out of
     * five is not an escape hatch.
     */
    public static function badge()
    {
        if (!self::active()) {
            return '';
        }
        $code = self::localeCode();
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $name = self::name();

        return '<div class="seasonbadge" data-season-badge role="note">'
            . '<span class="seasonbadge__mark" aria-hidden="true"></span>'
            . '<span class="seasonbadge__name">' . $esc(I18n::translate($code, 'theme.name.' . $name, $name)) . '</span>'
            . '<button class="seasonbadge__exit" type="button" data-season-exit>'
            . $esc(I18n::translate($code, 'theme.exit', 'Leave the theme'))
            . '</button>'
            . '</div>';
    }

    /**
     * The music controls, bottom-left. Empty for a theme without music.
     *
     * The buttons are the only reliable way in: browsers refuse to start audible
     * playback without a gesture, so assets/theme-music.js tries once and then waits
     * for the first interaction. The controls exist either way, which is the point --
     * a visitor who does not want music must be able to stop it.
     */
    public static function player()
    {
        if (!self::active() || self::tracks() === array()) {
            return '';
        }
        $code = self::localeCode();
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        /*
         * THE BOTTOM-LEFT CORNER IS SHARED, AND THE CAP BADGE GOT THERE FIRST.
         *
         * The proof-of-work widget is pinned to the lower-left corner (md3.css,
         * [data-cap-host]) and it stays on screen after loading -- it is the "you are
         * human" pill. The music controls are pinned to the same corner, so with the
         * challenge enabled the pill sat on top of them: on the live home page both the
         * Play button and the replay arrow were covered. The player therefore steps up
         * when the widget is going to be there, and stays put when it is not -- a
         * deployment without the challenge keeps the corner to itself.
         */
        $dodge = \SatApi\Support\Pow::enabled() ? ' themebar--cap' : '';

        return '<div class="themebar' . $dodge . '" data-theme-music role="group" aria-label="'
                . $esc(I18n::translate($code, 'theme.music', 'Music')) . '">'
            . '<button class="themebar__btn" type="button" data-music-toggle aria-pressed="false">'
                . $esc(I18n::translate($code, 'theme.play', 'Play')) . '</button>'
            . '<button class="themebar__btn themebar__btn--icon" type="button" data-music-replay title="'
                . $esc(I18n::translate($code, 'theme.replay', 'Replay')) . '" aria-label="'
                . $esc(I18n::translate($code, 'theme.replay', 'Replay')) . '">&#8635;</button>'
            . '<span class="themebar__hint" data-music-hint hidden></span>'
            . '</div>';
    }

    /** The effect and music scripts, injected once by the shell. */
    public static function scripts()
    {
        if (!self::active()) {
            return '';
        }
        $theme = self::theme();
        $effect = isset($theme['effect']) ? trim((string) $theme['effect']) : '';

        // theme.js always: the exit badge and the opt-out correction are not optional.
        // The effect only when the theme has one.
        $out = '<script src="/assets/theme.js' . HomePage::assetQueryFor('theme.js') . '" defer></script>';
        if ($effect !== '') {
            $out .= "
" . '<script src="/assets/' . $effect . HomePage::assetQueryFor($effect) . '" defer></script>';
        }

        return $out;
    }

    /**
     * What the client needs to know about the theme, for `__SATAPI_BOOT__`.
     *
     * @return array<string,mixed>
     */
    public static function clientPayload()
    {
        $code = self::localeCode();
        $tracks = self::tracks();

        /* The IV per track: the player has to decrypt before it can play, and the IV is
           the only thing it cannot work out on its own. Keyed by URL, because that is
           what the rotation passes around. */
        $media = array();
        foreach ($tracks as $url) {
            $entry = \SatApi\Support\Media::entry(basename((string) $url, '.enc'));
            if ($entry !== null) {
                $media[$url] = array('iv' => (string) $entry['iv'], 'mime' => (string) $entry['mime']);
            }
        }

        return array(
            'name'   => self::name(),
            'cookie' => self::COOKIE,
            'tracks' => $tracks,
            'media'  => $media,
            'strings' => array(
                'play'   => I18n::translate($code, 'theme.play', 'Play'),
                'pause'  => I18n::translate($code, 'theme.pause', 'Pause'),
                'replay' => I18n::translate($code, 'theme.replay', 'Replay'),
                'hint'   => I18n::translate($code, 'theme.autoplay_hint', ''),
            ),
        );
    }
}
