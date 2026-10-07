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
        $code = $locale['code'];
        $t = static function ($key, $fallback = '') use ($code) {
            return I18n::translate($code, $key, $fallback);
        };

        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $version = (string) Config::get('service.version', '');
        $homepage = rtrim((string) Config::get('service.homepage', ''), '/');

        $shell = self::capture(__DIR__ . '/../partials/shell.php', array('bootPage' => 'index', 'bootOwnConsent' => true));
        $body = self::capture(__DIR__ . '/../partials/page-home.php', array('homeT' => $t, 'homeLocale' => $locale));

        $headers = array(
            // The document embeds a locale decision, so a shared cache must key on
            // the header that produced it. Cookie participates because the consent
            // state changes the markup (dialog present or not).
            'Cache-Control' => 'public, max-age=300, s-maxage=600, stale-while-revalidate=86400',
            'Vary'          => 'Accept-Language, Cookie, Accept-Encoding',
        );

        // Acknowledge an explicit choice so later requests skip negotiation. This is
        // a preference cookie, so it is only written when the visitor has not
        // restricted optional cookies.
        if ($locale['source'] === 'query' && self::preferenceCookiesAllowed($request)) {
            $cookie = I18n::localeCookie($code);
            if ($cookie !== '') {
                $headers['Set-Cookie'] = $cookie;
            }
        }

        $document = self::document($code, $t, $name, $version, $homepage, $shell, $body);

        // The ETag covers the locale and the body, so a conditional request from a
        // crawler or a CDN revalidates without a transfer but a language switch
        // still returns a new representation.
        $etag = '"' . substr(hash('sha256', $code . '|' . $version . '|' . md5($document)), 0, 32) . '"';
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
        $path = __DIR__ . '/../assets/' . ltrim($relative, '/');
        $cache[$relative] = is_file($path)
            ? '?v=' . substr(hash('sha256', (string) file_get_contents($path)), 0, 8)
            : '';

        return $cache[$relative];
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

    private static function document($code, callable $t, $name, $version, $homepage, $shell, $body)
    {
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $consent = Consent::current();
        $consentRequired = is_array($consent) && empty($consent['accepted']);

        $head = array(
            '<!DOCTYPE html>',
            '<html lang="' . $esc($code) . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . $esc($t('page.home.title', $name . ' · unified TLE and satellite metadata')) . '</title>',
            '<meta name="description" content="' . $esc($t(
                'page.home.description',
                'One de-duplicated TLE and satellite metadata feed merged from CelesTrak, AMSAT and SatNOGS DB. Edge-cached JSON or classic 20-column TLE, OpenAPI 3.1, RFC 9457 errors.'
            )) . '">',
            '<link rel="canonical" href="' . $esc($homepage) . '/">',
            // hreflang tells a search engine which locale each variant serves, so the
            // three language forms are not treated as duplicates of each other.
            '<link rel="alternate" hreflang="zh-Hans" href="/?lang=zh-Hans">',
            '<link rel="alternate" hreflang="zh-Hant" href="/?lang=zh-Hant">',
            '<link rel="alternate" hreflang="en" href="/?lang=en">',
            '<link rel="alternate" hreflang="x-default" href="/">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="' . $esc($name) . '">',
            '<meta property="og:title" content="' . $esc($name) . '">',
            '<meta property="og:url" content="' . $esc($homepage) . '/">',
            '<meta property="og:image" content="' . $esc($homepage) . '/icon-512.png">',
            '<meta name="twitter:card" content="summary">',
            '<meta name="theme-color" content="#0891b2" media="(prefers-color-scheme: light)">',
            '<meta name="theme-color" content="#080f18" media="(prefers-color-scheme: dark)">',
            '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">',
            '<link rel="alternate icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">',
            '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
            '<link rel="manifest" href="/site.webmanifest">',
            '<link rel="stylesheet" href="/assets/app.css' . self::assetQuery('app.css') . '">',
            // Apply a stored theme before first paint; guarded because storage can
            // throw in private mode.
            '<script>(function(){try{var c=window.localStorage.getItem("satapi-theme");'
                . 'if(c==="light"||c==="dark"){document.documentElement.setAttribute("data-theme",c);}}catch(e){}}());</script>',
            \SatApi\Support\CapBootstrap::render(),
            '</head>',
        );

        $bodyAttrs = ' data-page="index" data-locale="' . $esc($code) . '"'
            . ' data-consent-required="' . ($consentRequired ? '1' : '0') . '"';

        return implode("\n", $head) . "\n<body" . $bodyAttrs . ">" . $shell . $body . "</body>\n</html>\n";
    }
}
