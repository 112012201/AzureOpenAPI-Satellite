<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * The announcement board: Markdown files on disk, compiled once, rendered into
 * the document, and acknowledged per visitor in a cookie.
 *
 * WHY A BUILD STEP AT ALL
 *
 * Two of the requirements pull in opposite directions:
 *
 *   - the operator wants to edit a Markdown file in the site directory and then
 *     run one command to publish it;
 *   - the page is served `public, s-maxage=600` through two CDNs, so it must be
 *     BYTE-IDENTICAL for every visitor (see the long note in partials/shell.php
 *     about the cookie dialog: baking per-visitor state into a shared cache entry
 *     is the bug this project has already fixed twice).
 *
 * Compiling resolves both. The expensive, error-prone half (parse front matter,
 * render Markdown, validate) runs in the CLI, at the moment the operator asks for
 * it; the request path only reads a small PHP array and filters it by the server
 * clock. Nothing that varies per visitor is ever compiled into it, and nothing
 * that varies over time is frozen into it either -- `starts_at` / `expires_at` are
 * timestamps evaluated at request time, so an announcement can expire without
 * anybody touching the server.
 *
 * WHY THE COMPILED ARTIFACT IS A .php FILE
 *
 * `data/announcements.php` is `<?php return array(...);`. A .json file in the same
 * place would be served verbatim by the static-file location in the nginx config;
 * a PHP file that only returns an array answers with an empty body if it is ever
 * requested directly. The artifact is also a build product, never edited by hand.
 *
 * WHAT IS NOT HERE
 *
 * The older, config-driven top bar lives in SatApi\Support\Announcement. It is a
 * different mechanism (one sentence, no acknowledgement) and is deliberately left
 * alone; this class is the dialog board.
 */
final class AnnouncementBoard
{
    /** Severity, most severe first. The order is the display order too. */
    const LEVELS = array('urgent', 'important', 'normal', 'minor');

    /**
     * Accepted spellings for a level.
     *
     * The operator writes these files, so the parser accepts what a person would
     * naturally type rather than forcing one literal: English, Chinese and the
     * "critical/high/low" vocabulary that every incident tool uses. Anything else
     * is reported and falls back to `normal` instead of dropping the announcement
     * -- silently losing a notice is worse than showing it at the wrong weight.
     */
    const LEVEL_ALIASES = array(
        'urgent'    => 'urgent',
        'critical'  => 'urgent',
        'crit'      => 'urgent',
        '紧急'      => 'urgent',
        '緊急'      => 'urgent',
        'important' => 'important',
        'high'      => 'important',
        'major'     => 'important',
        '重要'      => 'important',
        'normal'    => 'normal',
        'info'      => 'normal',
        '一般'      => 'normal',
        '普通'      => 'normal',
        'minor'     => 'minor',
        'low'       => 'minor',
        '次要'      => 'minor',
    );

    /** Button wording, decided by the announcement rather than by the UI. */
    const ACK_AGREE = 'agree';
    const ACK_READ = 'read';

    /** @var array<int,array<string,mixed>>|null memoised compiled payload */
    private static $loaded = null;

    /* ------------------------------------------------------------------ paths */

    /**
     * Where the Markdown sources live.
     *
     * `content/announcements` is inside the web root on purpose: the operator
     * asked to edit files "in the site directory", and that is where they will
     * look. It is not web-readable -- `.md` matches neither the static-asset
     * location nor any rewrite rule, so nginx answers 404 (verified with a live
     * request after deployment; see .ai-memory/handoff.md).
     */
    public static function sourceDir()
    {
        return Config::path((string) Config::get('announcements.source', 'content/announcements'));
    }

    /** Where the compiled artifact is written. */
    public static function compiledFile()
    {
        return Config::path((string) Config::get('announcements.compiled', 'data/announcements.php'));
    }

    /* ---------------------------------------------------------------- compile */

    /**
     * Parse every Markdown file and write the compiled artifact.
     *
     * @param string|null $dir     override the source directory (tests)
     * @param bool        $dryRun  validate without writing
     * @param int|null    $now     override "now" for the report
     * @param bool        $force   publish an empty board (see below)
     * @return array<string,mixed> report for the CLI
     */
    public static function compile($dir = null, $dryRun = false, $now = null, $force = false)
    {
        $dir = $dir === null ? self::sourceDir() : rtrim((string) $dir, '/\\');
        $now = $now === null ? time() : (int) $now;

        $report = array(
            'source'   => $dir,
            'target'   => self::compiledFile(),
            'files'    => 0,
            'compiled' => 0,
            'skipped'  => 0,
            'errors'   => array(),
            'warnings' => array(),
            'items'    => array(),
            'written'  => false,
        );

        $files = array();
        foreach ((array) glob($dir . '/*.md') as $path) {
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);
        $report['files'] = count($files);

        // An empty source directory is almost always a typo in the path, and it
        // would silently UNPUBLISH every notice. Refuse unless the operator says
        // they mean it, so the failure is loud at the moment it is caused.
        if ($report['files'] === 0 && !$force) {
            $report['errors'][] = 'no Markdown files in ' . $dir . ' (use --force to publish an empty board)';

            return $report;
        }

        $seen = array();
        $items = array();
        foreach ($files as $path) {
            $name = basename($path);
            $parsed = self::parse($path);
            if ($parsed === null) {
                $report['errors'][] = $name . ': cannot be read';
                $report['skipped']++;
                continue;
            }

            $item = self::normalise($parsed, $name, $report);
            if ($item === null) {
                $report['skipped']++;
                continue;
            }
            // One id may appear once PER LANGUAGE: the variants of a notice share the
            // id on purpose, because the id is what the dismissal cookie records.
            $seenKey = $item['id'] . '|' . $item['lang'];
            if (isset($seen[$seenKey])) {
                $report['errors'][] = $name . ': duplicate id "' . $item['id'] . '" for lang "' . $item['lang']
                    . '" (already used by ' . $seen[$seenKey] . ')';
                $report['skipped']++;
                continue;
            }
            $seen[$seenKey] = $name;

            // Drafts never reach the artifact, so a half-written file cannot leak
            // through a direct request for the compiled file either.
            if ($item['draft']) {
                $report['warnings'][] = $name . ': draft, not compiled';
                $report['skipped']++;
                continue;
            }

            $report['compiled']++;
            $items[] = $item;
        }

        // Newest first is how a person reads a board; severity ordering is applied
        // at render time, because that depends on what is still active.
        usort($items, static function ($a, $b) {
            if ($a['starts_at'] === $b['starts_at']) {
                return strcmp($a['id'], $b['id']);
            }

            return $a['starts_at'] < $b['starts_at'] ? 1 : -1;
        });

        $report['items'] = $items;

        // All-or-nothing. A file with a typo must not take the whole board down by
        // being skipped: the operator would see the other notices and never notice
        // that one had silently disappeared. Nothing is written while any file has
        // an error, so the previously published board stays exactly as it was.
        if (!$dryRun && empty($report['errors'])) {
            $report['written'] = self::write($items, $now);
            if (!$report['written']) {
                $report['errors'][] = 'cannot write ' . self::compiledFile();
            }
            // The request path memoises the payload; a rebuild inside one process
            // (tests, or a future admin endpoint) has to see the new one.
            self::$loaded = null;
        }

        return $report;
    }

    /**
     * Write the artifact atomically.
     *
     * tmp + rename, the same discipline the cache uses: a request must never read
     * a half-written file, because the failure would look like "the announcement
     * vanished" rather than like a write error.
     */
    private static function write(array $items, $now)
    {
        $target = self::compiledFile();
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            return false;
        }

        $payload = array(
            'built_at' => (int) $now,
            'items'    => array_values($items),
        );
        $php = "<?php\n"
            . "/* Generated by `php index.php announcements`. Do not edit.\n"
            . "   Source: content/announcements/*.md -- rebuild rather than patch. */\n"
            . 'return ' . var_export($payload, true) . ";\n";

        $tmp = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /* ------------------------------------------------------------------ parse */

    /**
     * Split one file into front matter and body.
     *
     * Front matter is the minimal `key: value` form between `---` fences: it is a
     * hand-edited file, so the format has to be readable at a glance and must not
     * need a YAML library (this project has no build chain and no Composer).
     *
     * @return array{meta:array<string,string>,body:string,file:string}|null
     */
    public static function parse($path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        // Strip a UTF-8 BOM: an editor on Windows adds one, and it would otherwise
        // become part of the first front-matter key and silently drop the title.
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }
        $raw = str_replace(array("\r\n", "\r"), "\n", $raw);

        $meta = array();
        $body = $raw;
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m) === 1) {
            $body = $m[2];
            foreach (explode("\n", $m[1]) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('/\A([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)\z/', $line, $kv) !== 1) {
                    continue;
                }
                $meta[strtolower($kv[1])] = self::unquote(trim($kv[2]));
            }
        }

        return array('meta' => $meta, 'body' => trim($body), 'file' => basename($path));
    }

    /** Strip one layer of matching quotes, so `title: "A: B"` survives the colon. */
    private static function unquote($value)
    {
        $value = trim((string) $value);
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private static function truthy($value)
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, array('1', 'true', 'yes', 'on', 'y'), true);
    }

    /**
     * Turn one parsed file into the stored record, or null when it is unusable.
     *
     * Errors are collected on the report rather than thrown: the CLI has to show
     * EVERY problem in one run, because the operator is standing at a terminal and
     * fixing them one reload at a time is the failure mode this avoids.
     */
    private static function normalise(array $parsed, $file, array &$report)
    {
        $meta = $parsed['meta'];

        $id = isset($meta['id']) ? trim($meta['id']) : '';
        if ($id === '') {
            // Fall back to the file name, minus a leading date, so a forgotten id
            // does not stop a publication. It is still stable across rebuilds.
            $id = preg_replace('/\.md$/', '', $file);
            $id = preg_replace('/^\d{4}-\d{2}-\d{2}[-_]?/', '', $id);
        }
        $id = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $id));
        $id = trim($id, '-');
        if ($id === '' || strlen($id) > 64) {
            $report['errors'][] = $file . ': id must be 1-64 characters of [a-z0-9-]';

            return null;
        }

        $title = isset($meta['title']) ? trim($meta['title']) : '';
        if ($title === '') {
            $report['errors'][] = $file . ': "title" is required';

            return null;
        }

        $dateRaw = isset($meta['date']) ? trim($meta['date']) : '';
        $startsAt = $dateRaw === '' ? false : strtotime($dateRaw);
        if ($startsAt === false) {
            $report['errors'][] = $file . ': "date" is required and must be a date (YYYY-MM-DD)';

            return null;
        }
        // A date with no time means "publish at the start of that day", which is
        // what an operator writing `date: 2026-11-03` intends.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
            $startsAt = strtotime($dateRaw . ' 00:00:00');
        }

        $levelRaw = isset($meta['level']) ? strtolower(trim($meta['level'])) : 'normal';
        $level = isset(self::LEVEL_ALIASES[$levelRaw]) ? self::LEVEL_ALIASES[$levelRaw] : '';
        if ($level === '') {
            $report['warnings'][] = $file . ': unknown level "' . $levelRaw . '", shown as normal';
            $level = 'normal';
        }

        $expiresAt = 0;
        $expiresRaw = isset($meta['expires']) ? trim($meta['expires']) : '';
        if ($expiresRaw !== '') {
            $expiresAt = strtotime($expiresRaw);
            if ($expiresAt === false) {
                $report['errors'][] = $file . ': "expires" is not a date';

                return null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresRaw) === 1) {
                // An expiry date is inclusive: a notice "until 2027-01-05" is still
                // up at 23:59 on that day.
                $expiresAt = strtotime($expiresRaw . ' 23:59:59');
            }
            if ($expiresAt <= $startsAt) {
                $report['errors'][] = $file . ': "expires" must be after "date"';

                return null;
            }
        }

        /*
         * `lang` — which reading language this version is written in.
         *
         * A notice is published once per language, with the SAME id: the id is also
         * the dismissal key, so a reader who acknowledges the Chinese version has
         * acknowledged the notice, not just one of its translations. Multiple
         * versions of one id are all rendered into the document and the client picks
         * the visitor's language (assets/announce.js), which is how the rest of this
         * site keeps a language-dependent document cacheable.
         *
         * Missing means `all`: an existing single-language notice keeps being shown
         * to everybody, which is the behaviour it had before this field existed.
         */
        $langRaw = '';
        foreach (array('lang', 'language', 'locale') as $langKey) {
            if (isset($meta[$langKey]) && trim($meta[$langKey]) !== '') {
                $langRaw = strtolower(trim($meta[$langKey]));
                break;
            }
        }
        $lang = 'all';
        if ($langRaw !== '' && $langRaw !== 'all' && $langRaw !== '*') {
            $mapped = array(
                'zh-hans' => 'zh-Hans', 'zh-cn' => 'zh-Hans', 'zh' => 'zh-Hans',
                'zh-sg' => 'zh-Hans', 'sc' => 'zh-Hans', 'chs' => 'zh-Hans',
                'zh-hant' => 'zh-Hant', 'zh-tw' => 'zh-Hant', 'zh-hk' => 'zh-Hant',
                'zh-mo' => 'zh-Hant', 'tc' => 'zh-Hant', 'cht' => 'zh-Hant',
                'en' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
            );
            if (isset($mapped[$langRaw])) {
                $lang = $mapped[$langRaw];
            } else {
                $report['warnings'][] = $file . ': unknown lang "' . $langRaw . '", shown to every language';
            }
        }

        $refusable = isset($meta['refusable']) ? self::truthy($meta['refusable']) : false;
        $ack = isset($meta['ack']) ? strtolower(trim($meta['ack'])) : '';
        if (!in_array($ack, array(self::ACK_AGREE, self::ACK_READ), true)) {
            // A refusal is only meaningful when agreeing is a real condition, so the
            // default wording follows the refusal flag: policy-style notices get
            // "agree", plain news gets "read".
            $ack = $refusable ? self::ACK_AGREE : self::ACK_READ;
        }
        // Refusing only makes sense for something the visitor must accept, so the
        // two settings are kept consistent in one direction: refusable implies the
        // "agree" wording.
        if ($refusable) {
            $ack = self::ACK_AGREE;
        }

        if (trim(self::plain($parsed['body'])) === '') {
            $report['errors'][] = $file . ': body is empty';

            return null;
        }

        return array(
            'id'         => $id,
            'file'       => $file,
            'title'      => $title,
            'author'     => isset($meta['author']) && trim($meta['author']) !== ''
                ? trim($meta['author'])
                : (string) Config::get('announcements.default_author', 'Announcement system'),
            'level'      => $level,
            'lang'       => $lang,
            'refusable'  => $refusable,
            'ack'        => $ack,
            'draft'      => isset($meta['draft']) ? self::truthy($meta['draft']) : false,
            'date'       => date('Y-m-d', $startsAt),
            'starts_at'  => (int) $startsAt,
            'expires_at' => (int) $expiresAt,
            'expires'    => $expiresAt > 0 ? date('Y-m-d', $expiresAt) : '',
            'html'       => self::markdown($parsed['body']),
        );
    }

    /* ------------------------------------------------------------------- load */

    /**
     * The compiled payload, or an empty list when nothing has been built.
     *
     * Missing is NOT an error: a fresh checkout, a first deploy, or an operator who
     * deleted the artifact all have to render a site with no announcements rather
     * than a 500. The CLI is what tells them the artifact is missing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all()
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $file = self::compiledFile();
        if (!is_file($file)) {
            self::$loaded = array();

            return self::$loaded;
        }

        // `include` is deliberate: OPcache caches the parsed array, so a request
        // costs one stat instead of a parse. The file is generated, never uploaded
        // by hand, and its first statement is a literal return.
        $payload = @include $file;
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            Logger::warning('announcements_bad_artifact', array('file' => $file));
            self::$loaded = array();

            return self::$loaded;
        }

        self::$loaded = array_values($payload['items']);

        return self::$loaded;
    }

    /** Forget the memoised payload (tests, and after a rebuild in the same process). */
    public static function reset()
    {
        self::$loaded = null;
    }

    /**
     * The announcements that apply right now, most severe first.
     *
     * Pure with respect to its inputs: `$items` and `$now` can be supplied by a test
     * without touching the filesystem or the clock, which is the only way the expiry
     * boundary can be asserted honestly.
     *
     * @param array<int,array<string,mixed>>|null $items
     * @return array<int,array<string,mixed>>
     */
    public static function select($items = null, $now = null)
    {
        $items = $items === null ? self::all() : $items;
        $now = $now === null ? time() : (int) $now;

        $live = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            // A future-dated announcement is scheduled, not live: the date is the
            // publication time, and the server decides it so a client clock cannot
            // publish or expire anything.
            if (isset($item['starts_at']) && (int) $item['starts_at'] > $now) {
                continue;
            }
            if (isset($item['expires_at']) && (int) $item['expires_at'] > 0 && (int) $item['expires_at'] < $now) {
                continue;
            }
            $live[] = $item;
        }

        usort($live, static function ($a, $b) {
            return self::compare($a, $b);
        });

        return $live;
    }

    /** Severity first, then newest. Used for both the board and the refusal gate. */
    private static function compare(array $a, array $b)
    {
        $rank = array_flip(self::LEVELS);
        $ra = isset($rank[$a['level']]) ? $rank[$a['level']] : count(self::LEVELS);
        $rb = isset($rank[$b['level']]) ? $rank[$b['level']] : count(self::LEVELS);
        if ($ra !== $rb) {
            return $ra < $rb ? -1 : 1;
        }
        $da = isset($a['starts_at']) ? (int) $a['starts_at'] : 0;
        $db = isset($b['starts_at']) ? (int) $b['starts_at'] : 0;
        if ($da !== $db) {
            return $da > $db ? -1 : 1;
        }

        return strcmp((string) $a['id'], (string) $b['id']);
    }

    /**
     * What the shell renders: live announcements for THIS address, capped.
     *
     * The cap is a rendering decision, not a policy one: every live announcement is
     * in the compiled artifact, but a page that embeds ten dialogs is a page nobody
     * wants to download. The ones dropped are still visible to the CLI.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function active(?Request $request = null)
    {
        if (!(bool) Config::get('announcements.enabled', true)) {
            return array();
        }

        if ($request !== null) {
            // The board is a property of the address, like the endpoint notice: the
            // operator asked for it on the accelerated and direct addresses only.
            $endpoint = Endpoint::current($request);
            if (isset($endpoint['announcements']) && !$endpoint['announcements']) {
                return array();
            }
        }

        $items = self::select();
        /*
         * The cap counts NOTICES, not files. A notice published in two languages is
         * two records, so slicing the flat list would let one bilingual notice eat the
         * whole budget and hide the next one. Keep every variant of the notices that
         * made the cut.
         */
        $max = (int) Config::get('announcements.max_shown', 3);
        if ($max > 0) {
            $ids = array();
            $kept = array();
            foreach ($items as $item) {
                $id = (string) $item['id'];
                if (!isset($ids[$id])) {
                    if (count($ids) >= $max) {
                        continue;
                    }
                    $ids[$id] = true;
                }
                $kept[] = $item;
            }
            $items = $kept;
        }

        $views = array();
        foreach ($items as $item) {
            $views[] = self::view($item);
        }

        return $views;
    }

    /** The subset of the record the template and the script need. */
    private static function view(array $item)
    {
        return array(
            'id'        => (string) $item['id'],
            'title'     => (string) $item['title'],
            'author'    => (string) $item['author'],
            'level'     => (string) $item['level'],
            'lang'      => (string) (isset($item['lang']) ? $item['lang'] : 'all'),
            'refusable' => !empty($item['refusable']),
            'ack'       => (string) $item['ack'],
            'date'      => (string) $item['date'],
            'expires'   => (string) (isset($item['expires']) ? $item['expires'] : ''),
            'html'      => (string) $item['html'],
        );
    }

    /**
     * The refusable announcement a visitor refused, for the gate.
     *
     * The cookie is read in the BROWSER (see assets/announce.js), because the page is
     * cached and the server cannot know this visitor's choice. This helper exists so
     * the same ordering rule that picks the dialog also picks the gate: the most
     * severe outstanding refusal is the one worth blocking for.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string>              $declined
     * @return array<string,mixed>|null
     */
    public static function gateFor(array $items, array $declined)
    {
        foreach ($items as $item) {
            if (!empty($item['refusable']) && in_array((string) $item['id'], $declined, true)) {
                return $item;
            }
        }

        return null;
    }

    /* -------------------------------------------------------------- markdown */

    /**
     * Render the announcement body.
     *
     * Deliberately small and deliberately safe: the whole body is HTML-escaped
     * FIRST and only a fixed set of block/inline patterns is re-introduced
     * afterwards. That ordering is what makes raw HTML in a Markdown file inert --
     * an announcement is written by whoever can edit the site directory, and a
     * `.md` that renders `<script>` is a stored-XSS primitive, not a feature.
     *
     * Supported: headings (# to ######), paragraphs, `-`/`*` lists, `1.` lists,
     * blockquotes, fenced code, `**bold**`, `*italic*`, `` `code` ``,
     * `[text](url)` with an http(s) or site-relative target.
     */
    public static function markdown($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $out = array();
        $paragraph = array();
        $list = '';
        $li = array();
        $inFence = false;
        $fence = array();

        $flushParagraph = static function () use (&$paragraph, &$out) {
            if ($paragraph === array()) {
                return;
            }
            $out[] = '<p>' . self::inline(implode("\n", $paragraph)) . '</p>';
            $paragraph = array();
        };
        $flushList = static function () use (&$list, &$li, &$out) {
            if ($list === '' || $li === array()) {
                $list = '';
                $li = array();

                return;
            }
            $body = '';
            foreach ($li as $entry) {
                $body .= '<li>' . self::inline($entry) . '</li>';
            }
            $out[] = '<' . $list . '>' . $body . '</' . $list . '>';
            $list = '';
            $li = array();
        };

        foreach (explode("\n", $escaped) as $line) {
            $trimmed = trim($line);

            // Fenced code: everything between ``` markers is literal.
            if (strpos($trimmed, '```') === 0) {
                if ($inFence) {
                    $out[] = '<pre><code>' . implode("\n", $fence) . '</code></pre>';
                    $fence = array();
                    $inFence = false;
                } else {
                    $flushParagraph();
                    $flushList();
                    $inFence = true;
                }
                continue;
            }
            if ($inFence) {
                $fence[] = $line;
                continue;
            }

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (preg_match('/\A(#{1,6})\s+(.*)\z/', $trimmed, $h) === 1) {
                $flushParagraph();
                $flushList();
                $level = min(6, strlen($h[1]) + 1); // a panel body starts at h2; `#` becomes h2
                $out[] = '<h' . $level . '>' . self::inline($h[2]) . '</h' . $level . '>';

                continue;
            }

            if (preg_match('/\A[-*]\s+(.*)\z/', $trimmed, $bullet) === 1) {
                $flushParagraph();
                if ($list !== 'ul') {
                    $flushList();
                    $list = 'ul';
                }
                $li[] = $bullet[1];

                continue;
            }

            if (preg_match('/\A\d+[.)]\s+(.*)\z/', $trimmed, $ordered) === 1) {
                $flushParagraph();
                if ($list !== 'ol') {
                    $flushList();
                    $list = 'ol';
                }
                $li[] = $ordered[1];

                continue;
            }

            if (preg_match('/\A&gt;\s?(.*)\z/', $trimmed, $quote) === 1) {
                $flushParagraph();
                $flushList();
                $out[] = '<blockquote><p>' . self::inline($quote[1]) . '</p></blockquote>';

                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();
        if ($inFence && $fence !== array()) {
            $out[] = '<pre><code>' . implode("\n", $fence) . '</code></pre>';
        }

        return implode('', $out);
    }

    /**
     * Inline formatting, applied to already-escaped text.
     *
     * Order matters: links first (their label may contain emphasis), then bold,
     * then italic, then code last so a backtick span is never re-read as emphasis.
     */
    private static function inline($text)
    {
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static function ($m) {
                $href = self::safeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));

                return $href === null
                    ? $m[1]
                    : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">' . $m[1] . '</a>';
            },
            $text
        );

        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Soft line breaks inside a paragraph are kept: an operator writing two
        // lines in one paragraph means two lines.
        return str_replace("\n", '<br>', $text);
    }

    /** Only http(s) and site-relative links are allowed; everything else degrades to text. */
    private static function safeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if ($url[0] === '/') {
            // A protocol-relative URL ("//evil.example") is not site-relative.
            return isset($url[1]) && $url[1] === '/' ? null : $url;
        }
        if (preg_match('~^https?://[^\s]+$~i', $url) === 1) {
            return $url;
        }

        return null;
    }

    /** The body with all markup stripped, for emptiness checks. */
    public static function plain($text)
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    }
}
