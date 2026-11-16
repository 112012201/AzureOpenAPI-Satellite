<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Consent;
use SatApi\Support\I18n;

/**
 * The landing page.
 *
 * Formerly a static index.html served by nginx. It is rendered by PHP now for three
 * reasons a static file cannot satisfy:
 *
 *   1. the locale has to be chosen from Accept-Language before the first byte is
 *      sent, so the right language paints on the first frame instead of after a
 *      script run;
 *   2. the announcement banner is rendered server-side, which makes it visible
 *      without JavaScript and puts the expiry decision on the server where a client
 *      clock cannot reach it;
 *   3. the consent dialog and the proof-of-work overlay belong to the document, so a
 *      visitor who blocks scripts still reads the notice and can reach the policy.
 *
 * The page renders every locale's text and marks one active, so the language
 * switcher needs neither a reload nor an extra request, and a visitor without
 * JavaScript still reads the language the server picked.
 *
 * The cost is one PHP round trip where nginx used to send a file. Measured on this
 * host: 1.06ms static, see _plan-v3.1.md for the post-change figure.
 */
final class HomePage
{
    /**
     * Paths this renderer answers. Kept explicit rather than pattern-based so the
     * API surface can never be captured by accident.
     *
     * @return bool
     */
    public static function handles(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return false;
        }
        $path = rtrim((string) $request->path(), '/');

        return $path === '' || $path === '/' || $path === '/index';
    }

    public static function render(Request $request)
    {
        $locale = I18n::resolve($request);
        // Resolve the seasonal skin before anything reads it: the head, the body
        // class and the shell's badge all consult it, and the shell is built later.
        \SatApi\Support\Season::boot($request);
        $code = (string) $locale['code'];
        $tag = isset($locale['tag']) ? (string) $locale['tag'] : $code;
        $t = static function ($key, $fallback = '') use ($code) {
            return I18n::translate($code, $key, $fallback);
        };

        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $version = (string) Config::get('service.version', '');
        $homepage = rtrim((string) Config::get('service.homepage', ''), '/');

        /*
         * Which jurisdiction this visitor is served under. The decision comes from the
         * client address (Region), matched against the APNIC table, and it changes two
         * things: the footer notice and whether the document may be cached at all.
         *
         * It cannot be expressed as a request header the CDN varies on, so the mainland
         * document is served no-store. The alternative -- caching one document that
         * carries both variants -- is the cache-poisoning bug this project has already
         * fixed twice (Vary: Cookie, then the per-visitor consent decision).
         */
        $region = \SatApi\Support\Region::forRequest($request);
        $mainland = !empty($region['mainland']);
        $pinned = $region['source'] === 'query';
        // Anything other than `intl` gets a document whose DEFAULT LANGUAGE depends
        // on the address, and a shared cache cannot key on an address. Hong Kong,
        // Macao and Taiwan are included deliberately: their document is identical to
        // the international one except for which language is marked active, and a
        // cache hit would hand a Taiwanese reader an English page.
        $addressDependent = (string) $region['zone'] !== \SatApi\Support\Region::INTL;

        // One block of text per locale, all present, one active: the same contract the
        // shell uses, so the language switcher and a no-JS reader both work.
        $block = static function ($key) use ($code) {
            $out = '';
            foreach (I18n::locales() as $loc) {
                $text = I18n::translate($loc, $key, $key);
                $active = $loc === $code;
                $out .= '<span class="i18n' . ($active ? ' is-active' : '') . '"'
                    . ' lang="' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-locale="' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '"'
                    . ($active ? '' : ' hidden') . '>'
                    . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
            }

            return $out;
        };

        /*
         * The address the page's copy-paste samples use.
         *
         * Not the Host header: a document that echoes whatever host it was sent can be
         * made to print somebody else's name, and this one is cacheable. The endpoint
         * registry maps the request to a configured address and falls back to the
         * default entry for an unknown host, so the worst case is the wrong-but-ours.
         */
        $endpoint = \SatApi\Support\Endpoint::current($request);
        $base = trim((string) $endpoint['url']);
        if ($base === '') {
            $base = rtrim((string) $homepage, '/');
        }

        $shell = self::capture(__DIR__ . '/../partials/shell.php', array('bootPage' => 'index', 'bootOwnConsent' => true));
        /*
         * The first-screen video. Resolved HERE rather than in the partial, because the
         * head needs the poster before the body is rendered: the poster is what paints
         * while the clip loads, so preloading it is the difference between a still and
         * a black band.
         *
         * Null for a theme with no film (or with its files missing) -- the partial then
         * renders no band at all.
         */
        $film = \SatApi\Support\Season::film();
        $body = self::capture(__DIR__ . '/../partials/page-home.php', array(
            'homeT'        => $t,
            'homeLocale'   => $locale,
            'homeMainland' => $mainland,
            'homeBlock'    => $block,
            'homeFilm'     => $film,
            // The picker is built here rather than in the partial: the zone filter
            // belongs to the resolver, and a template should not have to know which
            // languages its visitor is allowed to see.
            'homePicker'   => \SatApi\Support\LocalePicker::render($locale, 'lang lang--header'),
            // Already escaped: the partials echo it raw inside <code> and <pre>, where
            // an unescaped hostname from a request would be the one injection point on
            // an otherwise fully escaped page.
            'homeBaseEsc'  => htmlspecialchars($base, ENT_QUOTES, 'UTF-8'),
            // The service version is configuration, so the page prints it from there
            // instead of a literal that goes stale the moment Config changes (it did:
            // the footer and the sample envelope both still said the previous release).
            'homeVersionEsc' => htmlspecialchars($version, ENT_QUOTES, 'UTF-8'),
        ));
        /*
         * The band's controller: which encoding, whether to play, and the way out.
         * Requested from here rather than from Season::scripts() because the band is
         * this page's, and because the plain site -- no theme at all -- has one too.
         * It goes at the end of the document, like every other script.
         */
        if (is_array($film)) {
            $body .= '<script src="/assets/film.js' . self::assetQuery('film.js') . '" defer></script>';
        }

        $headers = array(
            // The document embeds a locale decision, so a shared cache must key on
            // the header that produced it.
            //
            // `Cookie` is deliberately NOT in this list any more. It was, because the
            // document used to carry the visitor's own cookie decision in
            // window.__SATAPI_BOOT__ -- and that is precisely what broke: a CDN edge
            // served one visitor's variant to another, so the consent dialog either
            // reappeared on every page or never appeared at all (measured
            // 2026-10-28: identical request and cookie, "hasConsentCookie":true on a
            // MISS and false on the next HIT). Per-visitor state is now read from
            // document.cookie by assets/consent.js, which makes this document
            // byte-identical for every visitor and removes the variant explosion at
            // the edge: one object per language instead of one per cookie combination.
            //
            // Verified by comparing the rendered body with and without
            // `Cookie: cookie_consent=accept_all` -- see the comment in partials/shell.php.
            // A skin chosen by the visitor (or by ?season=) is per-visitor, so it
            // follows the same rule as the region and language decisions above.
            'Cache-Control' => ($addressDependent || $pinned || \SatApi\Support\Season::pinned())
                ? 'no-store, max-age=0'
                : 'public, max-age=300, s-maxage=600, stale-while-revalidate=86400',
            'Vary'          => 'Accept-Language, Accept-Encoding',
            'X-Region'      => (string) $region['zone'],
        );

        // Acknowledge an explicit choice so later requests skip negotiation. This is
        // a preference cookie, so it is only written when the visitor has not
        // restricted optional cookies.
        if ($locale['source'] === 'query' && self::preferenceCookiesAllowed($request)) {
            // The TAG is stored, not the content locale: "English (UK)" has to survive
            // as en-GB or the next request comes back as en-US.
            $cookie = I18n::localeCookie($tag);
            if ($cookie !== '') {
                $headers['Set-Cookie'] = $cookie;
            }
        }

        $document = self::document($code, $tag, $t, $name, $version, $shell, $body, (string) $region['zone'], $film, $locale['source'] === 'query' ? $code : '');

        // The ETag covers the locale and the body, so a conditional request from a
        // crawler or a CDN revalidates without a transfer but a language switch
        // still returns a new representation.
        $etag = '"' . substr(hash('sha256', $tag . '|' . $version . '|' . md5($document)), 0, 32) . '"';
        $headers['ETag'] = $etag;
        if (trim((string) $request->header('if-none-match', '')) === $etag) {
            Response::noContent(304, $headers);

            return;
        }

        Response::raw($document, 'text/html; charset=utf-8', 200, $headers);
    }

    private static function preferenceCookiesAllowed(Request $request)
    {
        $cookieHeader = (string) $request->header('cookie', '');
        if ($cookieHeader === '') {
            // No decision yet: writing the language cookie is harmless and is what
            // the visitor explicitly asked for by using ?lang=.
            return true;
        }

        return strpos($cookieHeader, 'cookie_consent=reject_all') === false
            && strpos($cookieHeader, 'cookie_consent=essential_only') === false;
    }

    /**
     * Cache-busting query for a vendored asset, derived from its content hash.
     *
     * The assets are served with `immutable, max-age=7d`, which is right for files that
     * never change and wrong the moment one does: a browser that already holds
     * consent.js keeps executing the old copy for a week, and the fix never reaches it.
     * This cost real debugging time -- an integration fix was deployed, the server and
     * the CDN both served it, and the browser still ran the previous version from cache.
     *
     * The hash is computed once per request per file. The file is small, and the
     * alternative (a build step writing versions into a manifest) would add a moving part
     * to a project that deploys by copying files.
     *
     * @param string $relative path under assets/
     * @return string '?v=abcdef12' or '' when the file is missing
     */
    /**
     * Template-facing alias for assetQuery(), because the partials call it.
     */
    public static function assetQueryFor($relative)
    {
        return self::assetQuery($relative);
    }

    private static function assetQuery($relative)
    {
        static $cache = array();
        $relative = (string) $relative;
        if (array_key_exists($relative, $cache)) {
            return $cache[$relative];
        }
        // Two roots, because the page loads assets from both. The site's own files live in
        // assets/; the vendored Swagger build sits at the document root next to docs.html.
        // Only assets/ was searched before, so asking for a vendored file returned '' and the
        // link was left unversioned -- which is exactly what let a browser keep serving the
        // previous copy of a file that had changed on disk.
        $candidates = array(
            __DIR__ . '/../assets/' . ltrim($relative, '/'),
            __DIR__ . '/../' . ltrim($relative, '/'),
        );
        $found = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $found = '?v=' . substr(hash('sha256', (string) file_get_contents($path)), 0, 8);
                break;
            }
        }
        $cache[$relative] = $found;

        return $found;
    }

    /**
     * Render a partial with a controlled variable scope.
     *
     * The partials are plain PHP that expect a handful of variables; isolating them
     * keeps a caller's locals from leaking in and makes the contract explicit.
     *
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

    private static function document($code, $tag, callable $t, $name, $version, $shell, $body, $region = '', $film = null, $langQuery = '')
    {
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $consent = Consent::current();
        $consentRequired = is_array($consent) && empty($consent['accepted']);

        $title = $t('page.home.title', $name . ' · unified TLE and satellite metadata');
        $description = $t(
            'page.home.description',
            'One de-duplicated TLE and satellite metadata feed merged from CelesTrak, AMSAT and SatNOGS DB. Edge-cached JSON or classic 20-column TLE, OpenAPI 3.1, RFC 9457 errors.'
        );

        /*
         * The preview image: the film's poster when the band is on screen, the app
         * icon otherwise.
         *
         * A poster is 1280x720, which is the shape a link preview wants; the icon is
         * 512x512 and would be letterboxed. It is the SEASONAL poster, so a Christmas
         * link preview in December is the intended picture rather than a surprise --
         * but it is fetched with the asset's own ?v= hash, so a rotated still is a new
         * URL rather than a stale cached preview.
         */
        $ogImage = '';
        $ogImageType = 'image/png';
        $ogImageSize = array(512, 512);
        if (is_array($film) && (string) $film['poster'] !== '') {
            $poster = (string) $film['poster'];
            $ogImage = strpos($poster, 'http') === 0 ? $poster : \SatApi\Support\Seo::site() . $poster;
            $ogImageType = 'image/jpeg';
            $ogImageSize = array(1280, 720);
        }

        $seoNodes = \SatApi\Support\Seo::siteNodes($description);
        $seoNodes[] = \SatApi\Support\Seo::application($description);

        $head = array(
            '<!DOCTYPE html>',
            '<html lang="' . $esc($tag) . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . $esc($title) . '</title>',
        );
        foreach (\SatApi\Support\Seo::head(array(
            'path'        => '/',
            'title'       => $title,
            'description' => $description,
            'lang'        => $code,
            'lang_query'  => $langQuery,
            'image'       => $ogImage,
            'image_type'  => $ogImageType,
            'image_size'  => $ogImageSize,
            'nodes'       => $seoNodes,
        )) as $seoLine) {
            $head[] = $seoLine;
        }
        $head[] = '<meta name="theme-color" content="#0891b2" media="(prefers-color-scheme: light)">';
        $head[] = '<meta name="theme-color" content="#080f18" media="(prefers-color-scheme: dark)">';
        $head[] = '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">';
        $head[] = '<link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">';
        $head[] = '<link rel="apple-touch-icon" href="/apple-touch-icon.png">';
        $head[] = '<link rel="manifest" href="/site.webmanifest">';
        // Load order is load-bearing: tokens define the roles, app.css is the layout
        // that consumes them, md3.css re-skins the components and therefore has to
        // come last.
        $head[] = '<link rel="stylesheet" href="/assets/tokens.css' . self::assetQuery('tokens.css') . '">';
        $head[] = '<link rel="stylesheet" href="/assets/app.css' . self::assetQuery('app.css') . '">';
        $head[] = '<link rel="stylesheet" href="/assets/md3.css' . self::assetQuery('md3.css') . '">';
        // The Windows/Metro face. Last, so its typography and tiles win over the
        // component layer without either file having to know about the other.
        $head[] = '<link rel="stylesheet" href="/assets/metro.css' . self::assetQuery('metro.css') . '">';
        // Apply a stored theme before first paint; guarded because storage can
        // throw in private mode.
        $head[] = '<script>(function(){try{var c=window.localStorage.getItem("satapi-theme");'
            . 'if(c==="light"||c==="dark"){document.documentElement.setAttribute("data-theme",c);}}catch(e){}}());</script>';
        $head[] = \SatApi\Support\CapBootstrap::render();
        // The seasonal skin's stylesheet, appended last so it overrides every layer
        // below it. Empty unless the operator (or ?season=) turned the skin on.
        foreach (\SatApi\Support\Season::head() as $seasonLine) {
            $head[] = $seasonLine;
        }
        /*
         * The first-screen film. Its stylesheet is added here rather than in
         * Season::head() because only THIS page renders the band -- the docs and legal
         * pages share Season::head() and have no business downloading it.
         *
         * The poster is preloaded because it is what the browser paints before the
         * first video frame: on a cold load it is the largest thing on screen, and a
         * preload hint is the difference between a still and a black band.
         */
        if (is_array($film)) {
            $head[] = '<link rel="stylesheet" href="/assets/film.css' . self::assetQuery('film.css') . '">';
            if ((string) $film['poster'] !== '') {
                $head[] = '<link rel="preload" as="image" href="' . $esc($film['poster']) . '" fetchpriority="high">';
            }
        }
        $head[] = '</head>';

        $bodyAttrs = ' class="metro' . \SatApi\Support\Season::bodyClass() . '"'
            . ' data-page="index" data-locale="' . $esc($code) . '"'
            . ' data-region="' . $esc($region) . '"'
            . \SatApi\Support\Season::bodyAttrs()
            . ' data-consent-required="' . ($consentRequired ? '1' : '0') . '"';

        return implode("\n", $head) . "\n<body" . $bodyAttrs . ">" . $shell . $body . "</body>\n</html>\n";
    }
}
