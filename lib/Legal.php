<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Consent;
use SatApi\Support\I18n;
use SatApi\Support\LocalePicker;
use SatApi\Support\Region;

/**
 * Privacy policy, terms of service and the legal index.
 *
 * One class for all three because they share the shell, the locale resolution and
 * the cookie inventory. Three separate templates would have meant three places for
 * the inventory to drift away from `conf/config.php`, and the inventory is exactly
 * the part a reader is entitled to have match reality.
 *
 * The three locales are written FOR each locale rather than converted between each
 * other: the Simplified column uses mainland usage (信息, 网络, 服务器), the
 * Traditional column uses Taiwan/Hong Kong usage (資訊, 網路, 伺服器), and the
 * English column is written as English. A character-level conversion would leave
 * Simplified vocabulary inside Traditional text, which reads as careless to the
 * audience it is meant for.
 *
 * Every locale is rendered into the document and one is marked active, so the
 * switcher needs no request, a visitor without JavaScript reads the language the
 * server chose, and the text is present for crawlers.
 *
 * ⚠ The prose is a template prepared for this service, not legal advice, and every
 * page says so. The operator asked for a legal review before launch, which is why
 * the notice is rendered rather than buried in a comment.
 */
final class Legal
{
    /** Effective date of the current revision of every document here. */
    const UPDATED = '2026-11-12';

    /**
     * @return string|null 'index'|'privacy'|'terms', or null when the path is not ours
     */
    public static function section(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return null;
        }
        $path = rtrim((string) $request->path(), '/');
        if ($path === '/legal' || $path === '/legal/index') {
            return 'index';
        }
        if ($path === '/privacy' || $path === '/legal/privacy') {
            return 'privacy';
        }
        if ($path === '/terms' || $path === '/legal/terms') {
            return 'terms';
        }
        // The "why some links are not offered" notice: a legal document, so it is
        // served on the same path as the other two (no loading screen, no challenge).
        if ($path === '/no-acceleration' || $path === '/legal/no-acceleration') {
            return 'no-accel';
        }

        return null;
    }

    public static function render(Request $request, $section)
    {
        $locale = I18n::resolve($request);
        // Resolve the seasonal skin before anything reads it: the head, the body
        // class and the shell's badge all consult it, and the shell is built later.
        \SatApi\Support\Season::boot($request);

        /*
         * Two sets of documents, one address each.
         *
         * Which set is published is decided by Region (the client address, matched
         * against the APNIC tables), never by a cookie the visitor could not see and
         * never by the CDN's opinion alone. `?region=cn|hmt|intl` exists as an
         * explicit document selector for an operator or a reader behind a proxy; when
         * it is used the response is marked no-store, because a document chosen by
         * the visitor must not become the cached answer for everybody else.
         */
        $region = Region::forRequest($request);
        $zone = (string) $region['zone'];
        $regionCode = (string) $region['region'];
        $mainland = !empty($region['mainland']);
        $pinned = $region['source'] === 'query';

        /*
         * The mainland edition is published in Simplified Chinese only.
         *
         * Not a translation of convenience: it is the edition the local law expects,
         * it is the only one this site publishes for that jurisdiction, and the page
         * therefore offers no language selector at all (there would be nothing to
         * switch to). Forcing the language here rather than merely hiding the picker
         * also stops a stale cookie from leaving a reader on English with no way back
         * -- the international edition is one documented link away instead.
         */
        if ($mainland) {
            $locale['code'] = 'zh-Hans';
            $locale['tag'] = 'zh-Hans';
        }
        $code = (string) $locale['code'];
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $titles = array(
            'index'    => 'legal.title',
            'privacy'  => 'legal.privacy',
            'terms'    => 'legal.terms',
            'no-accel' => 'legal.no_accel',
        );
        /*
         * The section key is not the address. 'no-accel' is the internal name of the
         * acceleration notice, whose canonical URL is /no-acceleration -- and /no-accel
         * is a 301 to it. Building the canonical out of the key pointed the notice at a
         * URL that redirects, which a search engine answers by ignoring the claim.
         */
        $paths = array(
            'index'    => '/legal',
            'privacy'  => '/privacy',
            'terms'    => '/terms',
            'no-accel' => '/no-acceleration',
        );
        $path = $paths[$section];
        $serviceName = (string) Config::get('service.name', '');
        $title = I18n::translate($code, $titles[$section], $section);
        /*
         * One description per document, not one for the section.
         *
         * All four documents used to share a single 74-character sentence, which
         * describes the section rather than any page in it -- and identical meta
         * descriptions across four URLs is a duplicate-description report waiting to
         * happen. The section-wide string stays as the fallback so a future document
         * degrades to a sentence rather than to its bare title.
         */
        $descKeys = array(
            'index'    => 'legal.meta.description',
            'privacy'  => 'legal.meta.description.privacy',
            'terms'    => 'legal.meta.description.terms',
            'no-accel' => 'legal.meta.description.no_accel',
        );
        $description = I18n::translate(
            $code,
            $descKeys[$section],
            I18n::translate($code, 'legal.meta.description', $title)
        );
        $articleTitle = $title . ' · ' . $serviceName;
        $overview = I18n::translate($code, 'legal.overview', 'Legal');

        $seoNodes = array(\SatApi\Support\Seo::webPage($path, $articleTitle, $code, $description));
        if ($section !== 'index') {
            $seoNodes[] = \SatApi\Support\Seo::breadcrumbs($path, array(
                array($overview, '/legal'),
                array($title, $path),
            ));
        }

        $head = array(
            '<!DOCTYPE html>',
            '<html lang="' . $esc($code) . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . $esc($articleTitle) . '</title>',
        );
        /*
         * Indexable now, and deliberately.
         *
         * These documents used to be noindex on the mainland edition while the
         * sitemap listed them at the same time -- a contradiction a crawler resolves
         * by believing the noindex and discarding the entry. They are compliance
         * evidence: a reader looking for the privacy policy should find it, and a
         * site that publishes its terms is easier to trust than one that hides them.
         * The one case that stays noindex is `?region=`, a document the visitor
         * pinned by hand: that is not the address other readers get, so it must not
         * become the indexed representation.
         */
        foreach (\SatApi\Support\Seo::head(array(
            'path'        => $path,
            'title'       => $articleTitle,
            'description' => $description,
            'lang'        => $code,
            'index'       => !$pinned,
            'nodes'       => $seoNodes,
        )) as $seoLine) {
            $head[] = $seoLine;
        }
        $head[] = '<meta name="theme-color" content="#0891b2" media="(prefers-color-scheme: light)">';
        $head[] = '<meta name="theme-color" content="#080f18" media="(prefers-color-scheme: dark)">';
        $head[] = '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">';
        $head[] = '<link rel="stylesheet" href="/assets/app.css">';
        $head[] = '<link rel="stylesheet" href="/assets/metro.css">';
        // Seasonal skin last: a skin overrides the layers under it or it is not a skin.
        foreach (\SatApi\Support\Season::head() as $seasonLine) {
            $head[] = $seasonLine;
        }
        $head[] = '</head>';

        // A legal document is not a challenge: no loading screen and no proof of
        // work, which is what bootOwnOverlay => false tells the shell. The consent
        // dialog and the announcement bar stay, because they are the two things a
        // visitor must be able to reach from a policy page.
        $shell = self::capture(__DIR__ . '/../partials/shell.php', array(
            'bootPage'       => 'legal',
            'bootOwnConsent' => true,
            'bootOwnOverlay' => false,
        ));

        $consent = Consent::current();
        $bodyAttrs = ' data-page="legal" data-locale="' . $esc($code) . '"'
            . ' data-consent-required="' . (is_array($consent) && empty($consent['accepted']) ? '1' : '0') . '"';

        $bodyAttrs = ' class="metro' . \SatApi\Support\Season::bodyClass() . '"'
            . ' data-page="legal" data-locale="' . $esc($code) . '"'
            . ' data-region="' . $esc($zone) . '"'
            . \SatApi\Support\Season::bodyAttrs()
            . ' data-consent-required="' . (is_array($consent) && empty($consent['accepted']) ? '1' : '0') . '"';

        $document = implode("\n", $head) . "\n<body" . $bodyAttrs . ">"
            . $shell
            . '<main class="legal" id="main"><div class="legal__wrap">'
            . self::chrome($section, $locale, $esc)
            . self::articleFor($section, $code, $esc, $mainland, $locale)
            . '</div></main>'
            . '</body>' . "\n" . '</html>' . "\n";

        // A cache that cannot key on the address must not store an answer that
        // depends on it. The international document is cacheable as before; the
        // mainland one (and any explicitly selected one) is not.
        // Same rule as the landing page: a document whose default language is chosen
        // by the address is not stored by a cache that cannot key on it.
        $addressDependent = $zone !== Region::INTL;
        $headers = array(
            'Cache-Control' => ($addressDependent || $pinned || \SatApi\Support\Season::pinned())
                ? 'no-store, max-age=0'
                : 'public, max-age=3600, s-maxage=3600',
            'Vary'          => 'Accept-Language, Accept-Encoding',
            'X-Region'      => $zone,
        );
        // noindex is kept for the one case where the visitor chose the document by
        // hand: ?region= is not the address anybody else receives, so it must not
        // become the indexed representation of the page. The mainland edition is no
        // longer excluded -- it is the edition local readers get, and a policy page
        // that cannot be found is a policy page nobody can cite.
        if ($pinned) {
            $headers['X-Robots-Tag'] = 'noindex';
        }

        Response::raw($document, 'text/html; charset=utf-8', 200, $headers);
    }

    /* ------------------------------------------------------- documents by region */

    /**
     * Which document set applies, and which article renders it.
     *
     * The international documents are the long-form ones this class has always
     * published. The mainland set lives in conf/legal-docs.php because it is not a
     * translation: local law governs, some accelerated links are not offered, and the
     * anti-fraud notice belongs in the document itself. The acceleration notice (2C)
     * is the same text in both sets -- only whether it applies changes with the
     * reader -- so it is served from the same file.
     */
    private static function articleFor($section, $active, callable $esc, $mainland, array $locale = array())
    {
        if ($section === 'no-accel') {
            return self::articleFromBlocks('no-accel', $active, $esc, $section, $mainland);
        }
        if ($mainland && ($section === 'privacy' || $section === 'terms')) {
            return self::articleFromBlocks($section . '-cn', $active, $esc, $section, $mainland);
        }

        return self::article($section, $active, $esc);
    }

    /** 2A for the terms, 2B for the privacy policy, 2C for the notice. */
    private static function versionLabel($section)
    {
        $labels = array('index' => '2', 'privacy' => '2B', 'terms' => '2A', 'no-accel' => '2C');

        return isset($labels[$section]) ? $labels[$section] : '2';
    }

    /** The documents in conf/legal-docs.php, keyed by document id. */
    private static function documents()
    {
        static $docs = null;
        if ($docs === null) {
            $file = __DIR__ . '/../conf/legal-docs.php';
            $docs = is_file($file) ? (array) require $file : array();
        }

        return $docs;
    }

    /**
     * Render one document from conf/legal-docs.php.
     *
     * A document is a list of plain-text blocks; this is the only renderer, so a new
     * document needs no HTML and no escaping care at the call site.
     */
    private static function articleFromBlocks($id, $active, callable $esc, $section = 'index', $mainland = false)
    {
        $docs = self::documents();
        if (!isset($docs[$id])) {
            return '<p class="legal__meta">' . $esc('Document unavailable.') . '</p>';
        }
        $doc = $docs[$id];
        $locale = isset($doc[$active]) ? $doc[$active] : (isset($doc['en']) ? $doc['en'] : reset($doc));

        // self::loc() returns the per-locale <span> markup, so it is concatenated
        // as-is; escaping it here would print the markup instead of the word.
        $out = '<p class="legal__meta">'
            . self::loc(array('en' => 'Version', 'zh-Hans' => '版本', 'zh-Hant' => '版本'), $active)
            . ' ' . $esc((string) $doc['version'])
            . ' &middot; '
            . self::loc(array('en' => 'Effective', 'zh-Hans' => '生效日期', 'zh-Hant' => '生效日期'), $active)
            . ' ' . $esc((string) $doc['updated'])
            . '</p>';

        $out .= '<h1>' . $esc((string) $locale['title']) . '</h1>';
        if (!empty($locale['lede'])) {
            $out .= '<p class="legal__lede">' . $esc((string) $locale['lede']) . '</p>';
        }

        foreach ((array) $locale['blocks'] as $block) {
            if (isset($block['h'])) {
                $out .= '<h2>' . $esc((string) $block['h']) . '</h2>';
            } elseif (isset($block['p'])) {
                $out .= '<p>' . $esc((string) $block['p']) . '</p>';
            } elseif (isset($block['ul'])) {
                $out .= '<ul>';
                foreach ((array) $block['ul'] as $item) {
                    $out .= '<li>' . $esc((string) $item) . '</li>';
                }
                $out .= '</ul>';
            } elseif (isset($block['note'])) {
                $out .= '<aside class="legal__notice" role="note">' . $esc((string) $block['note']) . '</aside>';
            }
        }

        // The two cross-links are the same in every document, so they are not repeated
        // in the content file. The `?region=intl` link is section-aware, and on the
        // mainland edition it is the documented way out: that page offers no language
        // selector, so this is where a reader who needs the English or Traditional text
        // is sent instead.
        $sectionUrl = array(
            'index'    => '/legal',
            'privacy'  => '/privacy',
            'terms'    => '/terms',
            'no-accel' => '/no-acceleration',
        );
        $here = isset($sectionUrl[$section]) ? $sectionUrl[$section] : '/legal';
        $intlLabel = $mainland
            ? array(
                'en'      => 'International edition (English / Traditional Chinese)',
                'zh-Hans' => '国际版（英文／繁體中文）',
                'zh-Hant' => '國際版（英文／簡體中文）',
            )
            : array(
                'en'      => 'International edition',
                'zh-Hans' => '国际版本',
                'zh-Hant' => '國際版本',
            );
        $out .= '<p class="legal__meta">'
            . '<a href="' . $esc($here) . '?region=intl" rel="alternate">'
            . self::loc($intlLabel, $active)
            . '</a> &middot; <a href="/no-acceleration">'
            . self::loc(array('en' => 'Acceleration notice', 'zh-Hans' => '不加速说明', 'zh-Hant' => '不加速說明'), $active)
            . '</a></p>';

        return $out;
    }

    /* ------------------------------------------------------------------ i18n */

    /**
     * Render one text per locale, all present, one active.
     *
     * @param array<string,string> $byLocale
     */
    private static function loc(array $byLocale, $active)
    {
        $out = '';
        $fallback = (string) reset($byLocale);
        foreach (I18n::locales() as $code) {
            $text = isset($byLocale[$code]) && $byLocale[$code] !== '' ? (string) $byLocale[$code] : $fallback;
            $out .= '<span class="i18n' . ($code === $active ? ' is-active' : '') . '"'
                . ' lang="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
                . ($code === $active ? '' : ' hidden') . '>' . $text . '</span>';
        }

        return $out;
    }

    /** A section heading in three languages. */
    private static function h($level, array $byLocale, $active, $id = '')
    {
        $attr = $id === '' ? '' : ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';

        return '<h' . $level . $attr . '>' . self::loc($byLocale, $active) . '</h' . $level . '>';
    }

    /** A paragraph in three languages. */
    private static function p(array $byLocale, $active, $class = '')
    {
        $attr = $class === '' ? '' : ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';

        return '<p' . $attr . '>' . self::loc($byLocale, $active) . '</p>';
    }

    /** A list, one <li> per item, each item translated. */
    private static function ul(array $items, $active)
    {
        $out = '<ul>';
        foreach ($items as $item) {
            $out .= '<li>' . self::loc($item, $active) . '</li>';
        }

        return $out . '</ul>';
    }

    /* -------------------------------------------------------------- chrome */

    /**
     * Render a partial with a controlled variable scope.
     *
     * The partial expects a specific handful of variables; isolating them stops a
     * caller's locals from leaking in and makes the contract between caller and
     * partial explicit rather than accidental.
     *
     * @param string              $file
     * @param array<string,mixed> $vars
     * @return string
     */
    private static function capture($file, array $vars)
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /**
     * The strip above the article: language choice, then section navigation.
     *
     * The mainland edition renders NO language selector. That is the requirement, and
     * it is also the honest UI: that edition exists in one language, so a control with
     * nothing behind it would be a dead end. The international edition keeps the full
     * picker, filtered to what the zone serves by LocalePicker.
     *
     * @param array<string,mixed> $locale a value from I18n::resolve()
     */
    private static function chrome($section, array $locale, callable $esc)
    {
        $active = isset($locale['code']) ? (string) $locale['code'] : 'en';
        $mainland = isset($locale['zone']) && $locale['zone'] === Region::CN;

        $switcher = $mainland ? '' : LocalePicker::render($locale, 'lang');

        // Stated in the document itself, not only implied by the missing picker: a
        // reader who expected English should know why the page is in Chinese and where
        // the other edition is (the link is at the foot of the article).
        $only = $mainland
            ? '<p class="legal__only">'
                . $esc(I18n::translate('zh-Hans', 'legal.mainland_only',
                    '本页为依据中国大陆法律法规发布的版本，仅提供简体中文。'))
                . '</p>'
            : '';

        $nav = '<nav class="legal__nav" aria-label="'
            . $esc(I18n::translate($active, 'legal.title', 'Legal')) . '">';
        $items = array(
            array('/legal', 'legal.overview', 'Overview'),
            array('/privacy', 'legal.privacy', 'Privacy policy'),
            array('/terms', 'legal.terms', 'Terms of service'),
            array('/no-acceleration', 'legal.no_accel', 'Acceleration notice'),
            array('/docs', 'nav.docs', 'API docs'),
        );
        foreach ($items as $item) {
            $isCurrent = ($item[0] === '/legal' && $section === 'index')
                || ($item[0] === '/privacy' && $section === 'privacy')
                || ($item[0] === '/terms' && $section === 'terms')
                || ($item[0] === '/no-acceleration' && $section === 'no-accel');
            $nav .= '<a href="' . $esc($item[0]) . '"' . ($isCurrent ? ' aria-current="page"' : '') . '>'
                . $esc(I18n::translate($active, $item[1], $item[2])) . '</a>';
        }
        $nav .= '</nav>';

        return $switcher . $nav . $only;
    }

    /* ------------------------------------------------------------- article */

    private static function article($section, $active, callable $esc)
    {
        // Version labels are per document: 2A terms, 2B privacy, 2C the
        // acceleration notice. They are not the consent protocol's version, which
        // stays an integer and keeps its own meaning.
        $version = self::versionLabel($section);
        // The consent protocol version is an integer and is NOT the document
        // label: ?consent_version=2 is a wire contract, 2A/2B are revisions.
        $protocolVersion = (int) Config::get('consent.version', 1);
        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        // "The service home" inside the terms is THIS site, not the operator's own
        // site: service.homepage names the operator, and a document that calls that
        // address "the service home" sends a reader somewhere else while telling them
        // it is the same place.
        $homepage = rtrim(\SatApi\Support\Seo::site(), '/');
        // Contact: prefer a real mailbox when one is configured, otherwise fall back
        // to the project's public contact URL, and only then to a generic phrase.
        // Building the HTML here keeps the three locale strings below readable.
        $contactEmail = trim((string) Config::get('service.contact_email', ''));
        $contactUrl = trim((string) Config::get('service.contact', ''));
        if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactHtml = '<a href="mailto:' . $esc($contactEmail) . '">' . $esc($contactEmail) . '</a>';
        } elseif ($contactUrl !== '') {
            $contactHtml = '<a href="' . $esc($contactUrl) . '" rel="noopener">' . $esc($contactUrl) . '</a>';
        } else {
            $contactHtml = self::loc(array(
                'en'      => 'the operator contact form',
                'zh-Hans' => '运营方联系方式',
                'zh-Hant' => '營運方聯絡方式',
            ), $active);
        }

        $meta = '<p class="legal__meta">'
            . self::loc(array('en' => 'Version', 'zh-Hans' => '版本', 'zh-Hant' => '版本'), $active) . ' ' . $version
            . ' &middot; '
            . self::loc(array('en' => 'Last updated', 'zh-Hans' => '最后更新', 'zh-Hant' => '最後更新'), $active) . ' '
            . $esc(self::UPDATED)
            . ' &middot; ' . $esc($name)
            . '</p>';

        if ($section === 'index') {
            return $meta
                . self::h(1, array(
                    'en'      => 'Privacy policy and terms of service',
                    'zh-Hans' => '隐私政策与服务协议',
                    'zh-Hant' => '隱私權政策與服務條款',
                ), $active)
                . self::p(array(
                    'en'      => 'This service publishes satellite orbital data obtained from third-party providers. Two documents describe the arrangement between you and the operator. Both are available in Simplified Chinese, Traditional Chinese and English; the language switcher at the top of the page changes the text without reloading.',
                    'zh-Hans' => '本服务提供来自第三方数据源的卫星轨道数据。以下两份文件说明您与运营方之间的约定。两份文件均提供简体中文、繁体中文与英文版本，页面顶部的语言切换可在不重新加载的情况下切换文字。',
                    'zh-Hant' => '本服務提供來自第三方資料來源的衛星軌道資料。以下兩份文件說明您與營運方之間的約定。兩份文件均提供簡體中文、繁體中文與英文版本，頁面頂端的語言切換可在不重新載入的情況下切換文字。',
                ), $active)
                . self::h(2, array('en' => 'Documents', 'zh-Hans' => '文件', 'zh-Hant' => '文件'), $active)
                . '<ul class="legal__cards">'
                . '<li><a href="/privacy"><strong>' . $esc(I18n::translate($active, 'legal.privacy', 'Privacy policy')) . '</strong></a>'
                . self::loc(array(
                    'en'      => 'What is collected, which cookies exist and why, how long each is kept, and who else sees the data.',
                    'zh-Hans' => '收集哪些信息、存在哪些 Cookie 及其原因、各自保存多久，以及还有谁能看到这些数据。',
                    'zh-Hant' => '蒐集哪些資訊、存在哪些 Cookie 及其原因、各自保存多久，以及還有誰能看到這些資料。',
                ), $active) . '</li>'
                . '<li><a href="/terms"><strong>' . $esc(I18n::translate($active, 'legal.terms', 'Terms of service')) . '</strong></a>'
                . self::loc(array(
                    'en'      => 'Acceptable use, rate limits, what is promised, what is explicitly not promised, and how the service may change.',
                    'zh-Hans' => '可接受的使用方式、速率限制、承诺了什么、明确不承诺什么，以及服务可能如何变更。',
                    'zh-Hant' => '可接受的使用方式、速率限制、承諾了什麼、明確不承諾什麼，以及服務可能如何變更。',
                ), $active) . '</li>'
                . '</ul>'
                . self::h(2, array('en' => 'How acknowledgement works', 'zh-Hans' => '同意如何生效', 'zh-Hant' => '同意如何生效'), $active)
                . self::p(array(
                    'en'      => 'API clients may state their acknowledgement with a query parameter or a request header, so that the statement travels with the request and needs no session:',
                    'zh-Hans' => 'API 客户端可以用查询参数或请求头声明已同意，使该声明随请求一起传递，无需会话：',
                    'zh-Hant' => 'API 用戶端可以用查詢參數或請求標頭聲明已同意，使該聲明隨請求一起傳遞，無需工作階段：',
                ), $active)
                . '<pre class="legal__code"><code>?consent=privacy,terms&amp;consent_version=' . $protocolVersion . "\n"
                . 'X-Consent: privacy,terms;version=' . $protocolVersion . '</code></pre>'
                . self::p(array(
                    'en'      => 'While enforcement is disabled the acknowledgement is recorded and reported in the response (meta.consent, X-Consent-Required) but nothing is blocked. The operator can turn enforcement on, at which point an unacknowledged API request receives HTTP 451 Unavailable For Legal Reasons with the same details in the body.',
                    'zh-Hans' => '强制模式关闭时，同意状态会被记录并在响应中标注（meta.consent、X-Consent-Required），但不会阻断请求。运营方可以开启强制模式，此时未声明的 API 请求会收到 HTTP 451（因法律原因不可用），响应体包含同样的说明信息。',
                    'zh-Hant' => '強制模式關閉時，同意狀態會被記錄並在回應中標註（meta.consent、X-Consent-Required），但不會阻擋請求。營運方可以開啟強制模式，此時未聲明的 API 請求會收到 HTTP 451（因法律原因無法提供），回應內容包含同樣的說明資訊。',
                ), $active)
                . self::p(array(
                    'en'      => 'The acknowledgement is a statement by the client rather than proof: it is not cryptographically signed, because a per-client token would make every cached API response uncacheable. Do not treat it as non-repudiable.',
                    'zh-Hans' => '该同意是客户端的声明而非证明：它没有密码学签名，因为按客户端签发的令牌会使所有 API 响应都无法缓存。请不要把它当作不可否认的凭证。',
                    'zh-Hant' => '該同意是用戶端的聲明而非證明：它沒有密碼學簽章，因為按用戶端簽發的權杖會使所有 API 回應都無法快取。請不要把它當作不可否認的憑證。',
                ), $active, 'legal__meta');
        }

        if ($section === 'privacy') {
            return $meta
                . self::h(1, array('en' => 'Privacy policy', 'zh-Hans' => '隐私政策', 'zh-Hant' => '隱私權政策'), $active)
                . self::h(2, array('en' => '1. What this service does', 'zh-Hans' => '一、本服务做什么', 'zh-Hant' => '一、本服務做什麼'), $active)
                . self::p(array(
                    'en'      => 'This is a read-only HTTP API that republishes satellite orbital element sets and related metadata from third-party providers. It has no user accounts, accepts no user content, and does not store anything you send beyond what is needed to answer the request.',
                    'zh-Hans' => '这是一个只读的 HTTP API，转发第三方数据源的卫星轨道根数及相关元数据。它没有用户账户，不接受用户内容，除答复请求所必需之外不会存储您发送的任何内容。',
                    'zh-Hant' => '這是一個唯讀的 HTTP API，轉送第三方資料來源的衛星軌道根數及相關中介資料。它沒有使用者帳戶，不接受使用者內容，除答覆請求所必需之外不會儲存您傳送的任何內容。',
                ), $active)
                . self::h(2, array('en' => '2. Data we process', 'zh-Hans' => '二、我们处理的数据', 'zh-Hant' => '二、我們處理的資料'), $active)
                . self::ul(array(
                    array(
                        'en'      => '<strong>Request metadata.</strong> IP address, request path and query, User-Agent, and a request identifier are written to a log file so failures can be diagnosed and abuse answered. Kept 14 days, then rotated away.',
                        'zh-Hans' => '<strong>请求元数据。</strong>IP 地址、请求路径与查询串、User-Agent 以及请求标识会写入日志文件，用于排查故障与应对滥用。保留 14 天后轮转清除。',
                        'zh-Hant' => '<strong>請求中介資料。</strong>IP 位址、請求路徑與查詢字串、User-Agent 以及請求識別碼會寫入日誌檔，用於排查故障與應對濫用。保留 14 天後輪替清除。',
                    ),
                    array(
                        'en'      => '<strong>Aggregate counters.</strong> Per-endpoint request counts and upstream timing are kept as numbers. They are not linked to an individual visitor and are not used to build a profile.',
                        'zh-Hans' => '<strong>汇总计数。</strong>按端点的请求量与上游耗时以数字形式保留，不与单个访客关联，也不用于构建画像。',
                        'zh-Hant' => '<strong>彙總計數。</strong>依端點的請求量與上游耗時以數字形式保留，不與個別訪客關聯，也不用於建立側寫。',
                    ),
                    array(
                        'en'      => '<strong>No accounts, no email, no payment data.</strong> The service has none of these, so none is collected.',
                        'zh-Hans' => '<strong>没有账户、邮箱或支付数据。</strong>本服务不提供这些功能，因此也不收集。',
                        'zh-Hant' => '<strong>沒有帳戶、電子郵件或付款資料。</strong>本服務不提供這些功能，因此也不蒐集。',
                    ),
                ), $active)
                . self::h(2, array('en' => '3. Cookies and local storage', 'zh-Hans' => '三、Cookie 与本地存储', 'zh-Hant' => '三、Cookie 與本機儲存'), $active)
                . self::p(array(
                    'en'      => 'The dialog on first visit offers three outcomes: accept everything, allow only what is necessary, or reject everything optional. Categories other than "strictly necessary" are off until you allow them.',
                    'zh-Hans' => '首次访问时的弹窗提供三种选择：全部同意、仅允许必要项、拒绝全部可选项。除"绝对必要"之外的类别在您允许之前均处于关闭状态。',
                    'zh-Hant' => '首次造訪時的視窗提供三種選擇：全部同意、僅允許必要項目、拒絕全部選用項目。除「絕對必要」之外的類別在您允許之前均為關閉狀態。',
                ), $active)
                . self::p(array(
                    'en'      => 'One point deserves to be stated plainly rather than buried: the strictly necessary cookies are written even when you reject everything. Without them your own selection could not be remembered, and a proof-of-work session could not be kept. They carry no identifier that follows you to another site.',
                    'zh-Hans' => '有一点需要明确说明而不是含糊带过：即使您拒绝全部，绝对必要的 Cookie 仍会写入。否则连您自己的选择都无法记住，工作量证明会话也无法保持。它们不包含能跨站跟踪您的标识。',
                    'zh-Hant' => '有一點需要明確說明而不是含糊帶過：即使您拒絕全部，絕對必要的 Cookie 仍會寫入。否則連您自己的選擇都無法記住，工作量證明工作階段也無法保持。它們不包含能跨站追蹤您的識別碼。',
                ), $active, 'legal__meta')
                . self::cookieTable($active, $esc)
                . self::h(2, array('en' => '4. What we do not do', 'zh-Hans' => '四、我们不做的事', 'zh-Hant' => '四、我們不做的事'), $active)
                . self::ul(array(
                    array(
                        'en'      => 'No advertising, no ad networks, no cross-site tracking, no fingerprinting.',
                        'zh-Hans' => '不做广告、不接入广告联盟、不做跨站跟踪、不做设备指纹识别。',
                        'zh-Hant' => '不做廣告、不接入廣告聯播網、不做跨站追蹤、不做裝置指紋識別。',
                    ),
                    array(
                        'en'      => 'No selling or renting of visitor data. There is no visitor data set to sell.',
                        'zh-Hans' => '不出售或出租访客数据。也不存在可供出售的访客数据集。',
                        'zh-Hant' => '不出售或出租訪客資料。也不存在可供出售的訪客資料集。',
                    ),
                    array(
                        'en'      => 'No third-party analytics script is loaded. If that ever changes, the analytics category in the dialog becomes active first and this document is updated with a new version number.',
                        'zh-Hans' => '不加载第三方统计脚本。若将来有此需要，弹窗中的"访问统计"类别会先被启用，本文档也会随之更新版本号。',
                        'zh-Hant' => '不載入第三方統計指令碼。若將來有此需要，視窗中的「流量統計」類別會先被啟用，本文件也會隨之更新版本號。',
                    ),
                ), $active)
                . self::h(2, array('en' => '5. Who else sees the data', 'zh-Hans' => '五、还有谁能看到这些数据', 'zh-Hant' => '五、還有誰能看到這些資料'), $active)
                . self::p(array(
                    'en'      => 'The service runs behind a content delivery network, which necessarily sees the request metadata described above in order to route and cache it. Upstream data providers (CelesTrak, AMSAT, SatNOGS DB, Space-Track) are contacted by the server, never by your browser, so they do not see your IP address. No other party receives anything.',
                    'zh-Hans' => '本服务运行在内容分发网络之后，该网络为完成路由与缓存，必然能看到上述请求元数据。上游数据源（CelesTrak、AMSAT、SatNOGS DB、Space-Track）由服务器访问，绝不通过您的浏览器访问，因此它们看不到您的 IP 地址。除此之外没有其他方会收到任何数据。',
                    'zh-Hant' => '本服務運行於內容傳遞網路之後，該網路為完成路由與快取，必然能看到上述請求中介資料。上游資料來源（CelesTrak、AMSAT、SatNOGS DB、Space-Track）由伺服器存取，絕不透過您的瀏覽器存取，因此它們看不到您的 IP 位址。除此之外沒有其他方會收到任何資料。',
                ), $active)
                . self::h(2, array('en' => '6. Your choices and rights', 'zh-Hans' => '六、您的选择与权利', 'zh-Hant' => '六、您的選擇與權利'), $active)
                . self::ul(array(
                    array(
                        'en'      => 'Change or withdraw your cookie choice at any time using the "Cookie settings" control in the page footer.',
                        'zh-Hans' => '随时通过页面底部的"Cookie 设置"更改或撤回您的选择。',
                        'zh-Hant' => '隨時透過頁面底部的「Cookie 設定」變更或撤回您的選擇。',
                    ),
                    array(
                        'en'      => 'Switch language at any time with the switcher at the top of the page. That preference is a cookie and disappears if you restrict optional cookies.',
                        'zh-Hans' => '随时通过页面顶部的切换器更改语言。该偏好属于 Cookie，若您限制可选项则不会保留。',
                        'zh-Hant' => '隨時透過頁面頂端的切換器變更語言。該偏好屬於 Cookie，若您限制選用項目則不會保留。',
                    ),
                    array(
                        'en'      => 'Request access to, correction of, or deletion of log data that concerns you by writing to ' . $contactHtml . '. Because logs are keyed by IP address rather than by identity, please include the address and a time window.',
                        'zh-Hans' => '如需查阅、更正或删除涉及您的日志数据，请通过 ' . $contactHtml . ' 联系。由于日志以 IP 地址而非身份为索引，请一并提供地址与时间范围。',
                        'zh-Hant' => '如需查閱、更正或刪除涉及您的日誌資料，請透過 ' . $contactHtml . ' 聯絡。由於日誌以 IP 位址而非身分為索引，請一併提供位址與時間範圍。',
                    ),
                ), $active)
                . self::h(2, array('en' => '7. Changes to this policy', 'zh-Hans' => '七、本政策的变更', 'zh-Hant' => '七、本政策的變更'), $active)
                . self::p(array(
                    'en'      => 'A material change raises the version number shown at the top of this page. The consent dialog asks again when it detects an older acknowledged version, so a renewed acknowledgement always covers the text that is actually published.',
                    'zh-Hans' => '实质性变更会提高本页顶部显示的版本号。当弹窗检测到您此前同意的是旧版本时，会重新询问，因此每一次重新同意对应的一定是当前发布的文本。',
                    'zh-Hant' => '實質性變更會提高本頁頂端顯示的版本號。當視窗偵測到您先前同意的是舊版本時，會重新詢問，因此每一次重新同意對應的一定是當前發布的文字。',
                ), $active);
        }

        // terms
        return $meta
            . self::h(1, array('en' => 'Terms of service', 'zh-Hans' => '服务协议', 'zh-Hant' => '服務條款'), $active)
            . self::h(2, array('en' => '1. Acceptance', 'zh-Hans' => '一、接受条款', 'zh-Hant' => '一、接受條款'), $active)
            . self::p(array(
                'en'      => 'By using this API you accept these terms. If you do not accept them, do not use the service. The operator may require an explicit acknowledgement for API requests; if that is switched on, requests without one receive HTTP 451 Unavailable For Legal Reasons.',
                'zh-Hans' => '使用本 API 即表示您接受本协议。如不接受，请不要使用本服务。运营方可以要求 API 请求显式声明同意；一旦开启，未声明的请求会收到 HTTP 451（因法律原因不可用）。',
                'zh-Hant' => '使用本 API 即表示您接受本條款。如不接受，請不要使用本服務。營運方可以要求 API 請求明確聲明同意；一旦開啟，未聲明的請求會收到 HTTP 451（因法律原因無法提供）。',
            ), $active)
            . self::h(2, array('en' => '2. What the service is', 'zh-Hans' => '二、服务性质', 'zh-Hant' => '二、服務性質'), $active)
            . self::p(array(
                'en'      => 'A free, read-only aggregation of third-party orbital data. It is offered as-is, with no service level agreement, no availability guarantee and no warranty of accuracy or fitness for any purpose.',
                'zh-Hans' => '本服务是免费的、只读的第三方轨道数据聚合接口，按"现状"提供，不附服务等级协议、不保证可用性，也不对准确性或适用于特定用途作出任何担保。',
                'zh-Hant' => '本服務是免費的、唯讀的第三方軌道資料彙整 API，依「現狀」提供，不附服務層級協定、不保證可用性，也不對準確性或適用於特定用途作出任何擔保。',
            ), $active)
            . self::h(2, array('en' => '3. Orbital data is not navigation data', 'zh-Hans' => '三、轨道数据不是导航数据', 'zh-Hant' => '三、軌道資料不是導航資料'), $active)
            . self::p(array(
                'en'      => 'Orbital element sets are predictions. They carry an epoch and they decay with age. Do not use this service for collision avoidance, safety-of-life decisions, spacecraft commanding, or any purpose where a wrong number causes harm. TLE accuracy is published by the upstream providers; consult them for the authoritative figures.',
                'zh-Hans' => '轨道根数是预测值，带有历元并随时间衰减。请勿将本服务用于碰撞规避、涉及生命安全的决策、航天器指令发送，或任何"错误数值会造成损害"的用途。TLE 精度以上游数据源公布的为准，权威数值请向其查询。',
                'zh-Hant' => '軌道根數是預測值，帶有曆元並隨時間衰減。請勿將本服務用於碰撞避免、涉及生命安全的決策、太空載具指令傳送，或任何「錯誤數值會造成損害」的用途。TLE 精度以上游資料來源公布的為準，權威數值請向其查詢。',
            ), $active, 'legal__meta')
            . self::h(2, array('en' => '4. Acceptable use', 'zh-Hans' => '四、可接受的使用方式', 'zh-Hant' => '四、可接受的使用方式'), $active)
            . self::ul(array(
                array(
                    'en'      => 'Respect the published rate limits and the Retry-After header. Cache responses; the feeds change slowly and re-fetching on every request wastes a shared resource.',
                    'zh-Hans' => '请遵守公布的速率限制与 Retry-After 响应头。请缓存响应：这些数据变化缓慢，每次请求都重新获取只会浪费共享资源。',
                    'zh-Hant' => '請遵守公布的速率限制與 Retry-After 回應標頭。請快取回應：這些資料變化緩慢，每次請求都重新取得只會浪費共享資源。',
                ),
                array(
                    'en'      => 'Do not attempt to bypass rate limiting, evict the cache, or otherwise degrade the service for others.',
                    'zh-Hans' => '请勿尝试绕过限流、清空缓存，或以其他方式降低他人使用本服务的质量。',
                    'zh-Hant' => '請勿嘗試繞過限流、清空快取，或以其他方式降低他人使用本服務的品質。',
                ),
                array(
                    'en'      => 'Do not present the data as your own or remove the upstream attribution. See section 5.',
                    'zh-Hans' => '请勿将数据表述为您自己的，也不要移除上游署名。见第五节。',
                    'zh-Hant' => '請勿將資料表述為您自己的，也不要移除上游署名。見第五節。',
                ),
                array(
                    'en'      => 'Do not use the service to break any law, or to interfere with the operation of satellites or ground stations.',
                    'zh-Hans' => '请勿利用本服务从事违法活动，或干扰卫星与地面站的运行。',
                    'zh-Hant' => '請勿利用本服務從事違法活動，或干擾衛星與地面站的運作。',
                ),
            ), $active)
            . self::h(2, array('en' => '5. Upstream data and attribution', 'zh-Hans' => '五、上游数据与署名', 'zh-Hant' => '五、上游資料與署名'), $active)
            . self::p(array(
                'en'      => 'The data belongs to the upstream providers, not to this service. Each response names the source it came from. Attribution requirements are set by those providers, and it is your responsibility to meet them for the data you redistribute:',
                'zh-Hans' => '数据属于上游数据源，而非本服务。每个响应都会标明其来源。署名要求由各上游数据源规定，若您再分发这些数据，满足这些要求是您的责任：',
                'zh-Hant' => '資料屬於上游資料來源，而非本服務。每個回應都會標明其來源。署名要求由各上游資料來源規定，若您再散佈這些資料，滿足這些要求是您的責任：',
            ), $active)
            . self::ul(array(
                array(
                    'en'      => '<strong>CelesTrak</strong> — Dr T.S. Kelso. Weather and amateur group element sets, refreshed every six hours.',
                    'zh-Hans' => '<strong>CelesTrak</strong> —— Dr T.S. Kelso。气象与业余无线电分组的轨道根数，每六小时刷新一次。',
                    'zh-Hant' => '<strong>CelesTrak</strong> —— Dr T.S. Kelso。氣象與業餘無線電分組的軌道根數，每六小時重新整理一次。',
                ),
                array(
                    'en'      => '<strong>Space-Track</strong> — a fallback provider for the same groups, used when CelesTrak is unavailable. Data is requested with an account held by the operator.',
                    'zh-Hans' => '<strong>Space-Track</strong> —— 同分组数据的备份来源，在 CelesTrak 不可用时启用。请求使用运营方持有的账号。',
                    'zh-Hant' => '<strong>Space-Track</strong> —— 同分組資料的備援來源，在 CelesTrak 無法使用時啟用。請求使用營運方持有的帳號。',
                ),
                array(
                    'en'      => '<strong>AMSAT</strong> — amateur satellite status reports.',
                    'zh-Hans' => '<strong>AMSAT</strong> —— 业余卫星状态报告。',
                    'zh-Hant' => '<strong>AMSAT</strong> —— 業餘衛星狀態報告。',
                ),
                array(
                    'en'      => '<strong>SatNOGS DB</strong> — satellite and transmitter catalogues, published under their own licence.',
                    'zh-Hans' => '<strong>SatNOGS DB</strong> —— 卫星与发射机目录，按其自身许可发布。',
                    'zh-Hant' => '<strong>SatNOGS DB</strong> —— 衛星與發射機目錄，依其自身授權發布。',
                ),
            ), $active)
            . self::h(2, array('en' => '6. Availability and changes', 'zh-Hans' => '六、可用性与变更', 'zh-Hant' => '六、可用性與變更'), $active)
            . self::ul(array(
                array(
                    'en'      => 'The API version is part of the path (/v1). A breaking change is published as a new version rather than applied to the existing one.',
                    'zh-Hans' => 'API 版本体现在路径中（/v1）。破坏性变更会作为新版本发布，而不会直接施加到现有版本上。',
                    'zh-Hant' => 'API 版本體現在路徑中（/v1）。破壞性變更會作為新版本發布，而不會直接套用到現有版本上。',
                ),
                array(
                    'en'      => 'Endpoints may be rate limited, put into maintenance, or withdrawn. When an endpoint is under maintenance it answers 503 with a reason and a Retry-After header rather than failing silently.',
                    'zh-Hans' => '端点可能被限流、进入维护状态或下线。处于维护状态时会返回 503，并附带原因与 Retry-After 响应头，而不是静默失败。',
                    'zh-Hant' => '端點可能被限流、進入維護狀態或下線。處於維護狀態時會傳回 503，並附帶原因與 Retry-After 回應標頭，而不是靜默失敗。',
                ),
                array(
                    'en'      => 'Pre-v1 paths (/tle, /health, /status and the historical .php files) are kept working but are deprecated; responses carry Deprecation and Link headers pointing at the replacement.',
                    'zh-Hans' => 'v1 之前的路径（/tle、/health、/status 及历史 .php 文件）保持可用但已弃用；响应会带上 Deprecation 与 Link 响应头指向替代端点。',
                    'zh-Hant' => 'v1 之前的路徑（/tle、/health、/status 及歷史 .php 檔案）保持可用但已棄用；回應會帶上 Deprecation 與 Link 回應標頭指向替代端點。',
                ),
            ), $active)
            . self::h(2, array('en' => '7. Limitation of liability', 'zh-Hans' => '七、责任限制', 'zh-Hant' => '七、責任限制'), $active)
            . self::p(array(
                'en'      => 'To the extent permitted by law, the operator is not liable for any indirect, incidental or consequential loss arising from use of the service or from reliance on the data it returns, including decisions made on the basis of an inaccurate or outdated element set.',
                'zh-Hans' => '在法律允许的范围内，对于因使用本服务或依赖其返回的数据而产生的任何间接、附带或后果性损失（包括基于不准确或过期轨道根数作出的决定），运营方不承担责任。',
                'zh-Hant' => '在法律允許的範圍內，對於因使用本服務或依賴其傳回的資料而產生的任何間接、附帶或後果性損失（包括基於不準確或過期軌道根數所作的決定），營運方不承擔責任。',
            ), $active)
            . self::h(2, array('en' => '8. Governing terms and contact', 'zh-Hans' => '八、适用条款与联系方式', 'zh-Hant' => '八、適用條款與聯絡方式'), $active)
            . self::p(array(
                'en'      => 'These terms are governed by the laws applicable at the operator\'s principal place of business. Questions about these terms, or about the privacy policy, go to ' . $contactHtml . '. The service home is <a href="' . $esc($homepage) . '">' . $esc($homepage) . '</a>.',
                'zh-Hans' => '本协议适用运营方主要营业地所适用的法律。有关本协议或隐私政策的问题，请联系 ' . $contactHtml . '。服务主页为 <a href="' . $esc($homepage) . '">' . $esc($homepage) . '</a>。',
                'zh-Hant' => '本條款適用營運方主要營業地所適用的法律。有關本條款或隱私權政策的問題，請聯絡 ' . $contactHtml . '。服務首頁為 <a href="' . $esc($homepage) . '">' . $esc($homepage) . '</a>。',
            ), $active);
    }

    /**
     * The cookie inventory, generated from configuration so the table cannot drift
     * from what the code actually sets.
     */
    private static function cookieTable($active, callable $esc)
    {
        $rows = '';
        foreach (array('essential', 'preferences', 'analytics') as $category) {
            $entries = (array) Config::get('cookies.' . $category, array());
            foreach ($entries as $name => $purpose) {
                $purposeByLocale = array(
                    'en'      => $esc((string) $purpose),
                    'zh-Hans' => $esc(self::purposeHans((string) $name, (string) $purpose)),
                    'zh-Hant' => $esc(self::purposeHant((string) $name, (string) $purpose)),
                );
                $rows .= '<tr>'
                    . '<td><code>' . $esc((string) $name) . '</code></td>'
                    . '<td>' . self::loc(array(
                        'en'      => self::labelEn($category),
                        'zh-Hans' => self::labelHans($category),
                        'zh-Hant' => self::labelHant($category),
                    ), $active) . '</td>'
                    . '<td>' . self::loc($purposeByLocale, $active) . '</td>'
                    . '</tr>';
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">&mdash;</td></tr>';
        }

        $head = '<thead><tr>'
            . '<th>' . self::loc(array('en' => 'Name', 'zh-Hans' => '名称', 'zh-Hant' => '名稱'), $active) . '</th>'
            . '<th>' . self::loc(array('en' => 'Category', 'zh-Hans' => '类别', 'zh-Hant' => '類別'), $active) . '</th>'
            . '<th>' . self::loc(array('en' => 'Purpose and lifetime', 'zh-Hans' => '用途与有效期', 'zh-Hant' => '用途與有效期'), $active) . '</th>'
            . '</tr></thead>';

        return '<div class="legal__tablewrap"><table class="legal__table">' . $head . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private static function labelEn($category)
    {
        $map = array('essential' => 'Strictly necessary', 'preferences' => 'Preferences', 'analytics' => 'Analytics');

        return isset($map[$category]) ? $map[$category] : $category;
    }

    private static function labelHans($category)
    {
        $map = array('essential' => '绝对必要', 'preferences' => '偏好设置', 'analytics' => '访问统计');

        return isset($map[$category]) ? $map[$category] : $category;
    }

    private static function labelHant($category)
    {
        $map = array('essential' => '絕對必要', 'preferences' => '偏好設定', 'analytics' => '流量統計');

        return isset($map[$category]) ? $map[$category] : $category;
    }

    private static function purposeHans($name, $fallback)
    {
        $map = array(
            'cookie_consent'         => '记录您在 Cookie 弹窗中的选择，避免每次打开页面都重复询问。有效期 180 天。',
            'announcement_dismissed' => '记录您已关闭的服务公告编号，使该公告保持关闭。有效期 30 天。',
            'cap_session'            => '保存本浏览器通过的工作量证明会话，仅在验证通过后写入，有效期 12 小时。',
            'satapi-theme'           => '记住您选择的浅色或深色主题。有效期 180 天。',
            'satapi-lang'            => '记住您选择的显示语言，使下次打开页面即为该语言。有效期 180 天。',
        );

        return isset($map[$name]) ? $map[$name] : $fallback;
    }

    private static function purposeHant($name, $fallback)
    {
        $map = array(
            'cookie_consent'         => '記錄您在 Cookie 視窗中的選擇，避免每次開啟頁面都重複詢問。有效期 180 天。',
            'announcement_dismissed' => '記錄您已關閉的服務公告編號，使該公告保持關閉。有效期 30 天。',
            'cap_session'            => '保存本瀏覽器通過的工作量證明工作階段，僅在驗證通過後寫入，有效期 12 小時。',
            'satapi-theme'           => '記住您選擇的淺色或深色主題。有效期 180 天。',
            'satapi-lang'            => '記住您選擇的顯示語言，使下次開啟頁面即為該語言。有效期 180 天。',
        );

        return isset($map[$name]) ? $map[$name] : $fallback;
    }
}
