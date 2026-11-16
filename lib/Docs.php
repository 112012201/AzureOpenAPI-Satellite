<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\CapBootstrap;
use SatApi\Support\Consent;
use SatApi\Support\I18n;
use SatApi\Support\LocalePicker;

/**
 * The API reference page.
 *
 * `docs.html` stays the authored source: it is a large self-contained document that
 * references the vendored Swagger bundle, and rewriting it into a PHP template would
 * be churn for no reader-visible gain. Instead it is served through PHP so that the
 * ONE thing it cannot express on its own can be injected -- Cap's asset overrides,
 * which must be present before the widget loads and which keep the visitor's browser
 * from fetching the solver from a third-party CDN.
 *
 * It is also the second page behind the proof of work (the operator's choice:
 * homepage and docs). Keeping both on the same injection path means the two pages
 * cannot drift apart in how the challenge is configured.
 */
final class Docs
{
    /**
     * Render the shared shell with a controlled variable scope.
     *
     * Same contract as HomePage and Legal: the partial expects $bootPage and
     * $bootOwnConsent to be set by its caller, which is also what its direct-access
     * guard checks.
     *
     * @return string
     */
    private static function shell()
    {
        $bootPage = 'docs';
        $bootOwnConsent = true;
        ob_start();
        require __DIR__ . '/../partials/shell.php';

        return (string) ob_get_clean();
    }

    public static function handles(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return false;
        }

        return rtrim((string) $request->path(), '/') === '/docs';
    }

    public static function render(Request $request)
    {        $file = Config::path('docs.html');
        if (!is_file($file)) {
            // Not fatal: fall back to a problem document rather than a blank page.
            Response::raw(
                "API reference is unavailable.\n",
                'text/plain; charset=utf-8',
                503,
                array('Cache-Control' => 'no-store')
            );

            return;
        }

        $html = (string) file_get_contents($file);

        // Cap's asset overrides must be in the head before the widget loads.
        $html = CapBootstrap::inject($html);

        // Cache-bust the stylesheets and scripts this page loads, for the same reason the
        // landing page does: they are served immutable for a week, so a fix would not
        // reach a browser that already holds the previous copy. docs.html is the authored
        // source, so the rewrite is a targeted replacement of the literal hrefs rather
        // than a template change.
        //
        // swagger-theme.css is in this list because it was NOT, and that cost a debugging pass:
        // the theme was edited, the server served the new text, and the browser kept applying the
        // cached stylesheet -- so a working fix looked like it had done nothing. The vendored
        // Swagger assets sit at the site root rather than under /assets/, so they are versioned by
        // a second pass. Everything this page loads is covered now.
        foreach (array('tokens.css', 'app.css', 'md3.css', 'metro.css', 'app.js', 'consent.js', 'swagger-theme.css') as $asset) {
            $q = \SatApi\HomePage::assetQueryFor($asset);
            if ($q === '') {
                continue;
            }
            foreach (array('/assets/', './assets/') as $prefix) {
                $html = str_replace($prefix . $asset . '"', $prefix . $asset . $q . '"', $html);
            }
        }
        foreach (array('swagger-ui.css', 'swagger-ui-bundle.js', 'swagger-ui-standalone-preset.js') as $asset) {
            $q = \SatApi\HomePage::assetQueryFor($asset);
            if ($q === '') {
                continue;
            }
            $html = str_replace('./' . $asset . '"', './' . $asset . $q . '"', $html);
        }

        // The SPEC is versioned too, and this is not cosmetic.
        //
        // Swagger UI fetches it at runtime, so the page's own cache headers say nothing
        // about which generation of /openapi.yaml arrives. Measured 2026-11-03, right
        // after deploying a repaired spec: /docs still parsed the PREVIOUS text (a stale
        // encoding variant at the edge -- curl on the same URL returned the new bytes
        // while the browser's Brotli variant was still the old one), and js-yaml
        // rejected it with "duplicated mapping key". The page looked broken even though
        // the origin was correct. A content hash in the URL makes a stale spec
        // structurally impossible: new document, new URL, cache miss.
        //
        // The human-facing links (`<a href="./openapi.yaml">`) are deliberately left
        // unversioned: those are URLs people paste, and a hash would churn them.
        $specQuery = \SatApi\HomePage::assetQueryFor('openapi.yaml');
        if ($specQuery !== '') {
            $html = str_replace(
                "url: './openapi.yaml'",
                "url: './openapi.yaml" . $specQuery . "'",
                $html
            );
        }

        /*
         * docs.html loads assets/app.js itself because it is also served as a static
         * file (the vhost routes /docs to PHP, but /docs.html still exists on disk).
         * The shell below loads it too, so on the PHP route the file arrived TWICE --
         * and that is not a harmless duplicate:
         *
         *   - every module bound its listeners twice, so the theme button switched to
         *     dark and straight back on one click and looked dead (the reported bug);
         *   - status.init() started two 60-second /v1/health pollers;
         *   - copy.init() installed two document-level click handlers.
         *
         * The page's own tag is removed here, before the shell is injected, so the
         * browser requests it once. The authored tag stays in docs.html for the static
         * route, and assets/app.js now refuses to boot twice anyway.
         */
        $html = (string) preg_replace(
            '#<script\s+src="(?:\./|/)assets/app\.js[^"]*"[^>]*>\s*</script>\s*#i',
            '',
            $html,
            1
        );

        // The shared shell carries the announcement bar, the consent dialog, the
        // proof-of-work overlay and the boot script. It is inserted immediately after
        // <body> so a visitor who blocks JavaScript still sees the notice and can reach
        // the policy, and so this page has the language switcher like the others.
        //
        // The page keeps its authored markup: only the attributes on <body> are added
        // and one block is inserted. Nothing else about docs.html is rewritten, so the
        // served document cannot drift from the file on disk.
        $locale = I18n::resolve($request);
        // Resolve the seasonal skin before anything reads it: the head, the body
        // class and the shell's badge all consult it, and the shell is built later.
        \SatApi\Support\Season::boot($request);
        $region = \SatApi\Support\Region::forRequest($request);
        $mainland = !empty($region['mainland']);
        $pinned = $region['source'] === 'query';
        $code = (string) $locale['code'];
        $tag = isset($locale['tag']) ? (string) $locale['tag'] : $code;

        // <html lang> follows the TAG (en-US / en-GB); the text follows the content
        // locale. One English text, two tags -- see I18n::canonicalTag().
        $html = preg_replace(
            '/<html lang="[^"]*"/i',
            '<html lang="' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '"',
            $html,
            1
        );

        // The seasonal skin, appended after every other stylesheet this page loads --
        // including the vendored Swagger ones -- so its overrides are the last word.
        if (\SatApi\Support\Season::active()) {
            $head = '';
            foreach (\SatApi\Support\Season::head() as $seasonLine) {
                $head .= $seasonLine . "
";
            }
            $html = str_replace('</head>', $head . '</head>', $html);
        }

        $bodyAttrs = ' data-page="docs" data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-region="' . htmlspecialchars((string) $region['region'], ENT_QUOTES, 'UTF-8') . '"'
            . \SatApi\Support\Season::bodyAttrs();
        if (\SatApi\Support\Season::active()) {
            // The authored <body class="metro"> gains the active theme's class by name,
            // not a hard-coded one: this page is edited as a static file and must not
            // have to know which holiday is running.
            $html = str_replace(
                '<body class="metro"',
                '<body class="metro ' . htmlspecialchars(\SatApi\Support\Season::name(), ENT_QUOTES, 'UTF-8') . '"',
                $html
            );
        }
        $html = preg_replace('/<body(\s[^>]*)?>/i', '<body$1' . $bodyAttrs . '>', $html, 1, $tagCount);

        // The language selector, filtered to what this zone serves. Inserted here
        // rather than authored in docs.html because the allowed set is a property of
        // the visitor, not of the template.
        $html = str_replace('<!--locale-picker-->', LocalePicker::render($locale, 'lang lang--header'), $html);

        // The fallback panel's text is assembled in JavaScript, so it cannot come from
        // the per-locale spans in the document. Hand the active locale's copy over.
        $strings = array(
            'fallbackH1'   => I18n::translate($code, 'docs.fallback.h1', 'Reference unavailable'),
            'fallbackP'    => I18n::translate($code, 'docs.fallback.p', ''),
            'fallbackTail' => I18n::translate($code, 'docs.fallback.tail', ''),
        );
        $html = str_replace(
            '</body>',
            '<script>window.__DOCS_STRINGS__ = '
                . json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                . ';</script>' . "
" . '</body>',
            $html
        );

        // {{t:key}} becomes a per-locale span block, {{a:key}} the active locale's text
        // in an attribute, {{host}} the address the visitor actually reached.
        // The address printed on the page comes from the configured endpoint registry,
        // not from the Host header: a page that echoes whatever Host it was sent can be
        // made to print somebody else's hostname, and this document is cached.
        $endpoint = \SatApi\Support\Endpoint::current($request);
        $base = trim((string) $endpoint['url']);
        if ($base === '') {
            $base = rtrim((string) Config::get('service.homepage', 'https://www.authskin.xyz'), '/');
        }
        $html = self::tokens($html, $code, $base);

        /*
         * The head's search metadata is generated, not authored.
         *
         * docs.html used to carry `<link rel="canonical" href="{{host}}/docs.html">`,
         * and /docs.html is a 301 to /docs -- so the page told a search engine that
         * its canonical copy lived at an address that redirects back to it. The
         * canonical is /docs now, on the host that actually serves it ({{host}} is the
         * endpoint the visitor reached, and two of the three hostnames are CDNs).
         */
        $serviceName = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $seoTitle = I18n::translate($code, 'docs.doc_title', 'API reference') . ' · ' . $serviceName;
        $seoDescription = I18n::translate($code, 'docs.meta_description', '');
        $docsNodes = array(
            \SatApi\Support\Seo::webPage('/docs', $seoTitle, $code, $seoDescription),
            \SatApi\Support\Seo::breadcrumbs('/docs', array(
                array(I18n::translate($code, 'nav.overview', 'Overview'), '/'),
                array(I18n::translate($code, 'docs.doc_title', 'API reference'), '/docs'),
            )),
            // The page IS the reference, so it is an article about the API rather
            // than an application: saying "SoftwareApplication" here would compete
            // with the landing page's claim instead of describing this page.
            array(
                '@type'            => 'TechArticle',
                '@id'              => \SatApi\Support\Seo::url('/docs') . '#article',
                'headline'         => $seoTitle,
                'description'      => $seoDescription,
                'inLanguage'       => $code,
                'url'              => \SatApi\Support\Seo::url('/docs'),
                'isPartOf'         => array('@id' => \SatApi\Support\Seo::site() . '/#website'),
                'publisher'        => array('@id' => \SatApi\Support\Seo::site() . '/#organization'),
                'mainEntityOfPage' => array('@id' => \SatApi\Support\Seo::url('/docs') . '#webpage'),
            ),
        );
        $html = str_replace(
            '<!--seo-head-->',
            implode("\n    ", \SatApi\Support\Seo::head(array(
                'path'        => '/docs',
                'title'       => $seoTitle,
                'description' => $seoDescription,
                'lang'        => $code,
                'type'        => 'article',
                'index'       => !$pinned,
                'nodes'       => array_merge(\SatApi\Support\Seo::siteNodes($seoDescription), $docsNodes),
            ))),
            $html
        );

        $shell = self::shell();
        if ($shell !== '') {
            $pos = stripos($html, '<body');
            $end = $pos === false ? false : strpos($html, '>', $pos);
            if ($end !== false) {
                $html = substr($html, 0, $end + 1) . $shell . substr($html, $end + 1);
            }
        }

        $etag = '"' . substr(hash('sha256', $html), 0, 32) . '"';
        // A document that selects its own language and its own picker from the client
        // ADDRESS cannot be shared by a cache that cannot key on it. The international
        // answer stays cacheable (every language is in the document and the script
        // picks the stored one); the mainland answer -- which is the one carrying a
        // filtered picker -- is not stored at all.
        $addressDependent = (string) $region['zone'] !== \SatApi\Support\Region::INTL;
        $headers = array(
            'Cache-Control' => ($addressDependent || $pinned || \SatApi\Support\Season::pinned())
                ? 'no-store, max-age=0'
                : 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400',
            'Vary'          => 'Accept-Language, Accept-Encoding',
            'ETag'          => $etag,
            'X-Region'      => (string) $region['zone'],
        );
        if (trim((string) $request->header('if-none-match', '')) === $etag) {
            Response::noContent(304, $headers);

            return;
        }

        $consent = Consent::current();
        if (is_array($consent) && empty($consent['accepted'])) {
            // Same signal the JSON endpoints send, so a browser client can act on the
            // requirement without a second request.
            $headers['X-Consent-Required'] = \SatApi\Support\Consent::headerValue($consent);
        }

        Response::raw($html, 'text/html; charset=utf-8', 200, $headers);
    }

    /**
     * Render the tokens docs.html is authored with.
     *
     *   {{t:key}}   the key as a block of per-locale spans, exactly like the rest of
     *               the site, so switching language on this page needs no reload
     *   {{a:key}}   the active locale's text, escaped, for attributes and <title>
     *   {{host}}    the address the visitor reached, so the page never prints a
     *               hostname that has been retired
     */
    private static function tokens($html, $code, $host)
    {
        $html = (string) preg_replace_callback('/\{\{t:([a-z0-9._-]+)\}\}/i', static function ($m) use ($code) {
            return I18n::block($code, $m[1]);
        }, $html);
        $html = (string) preg_replace_callback('/\{\{a:([a-z0-9._-]+)\}\}/i', static function ($m) use ($code) {
            return htmlspecialchars(I18n::translate($code, $m[1], $m[1]), ENT_QUOTES, 'UTF-8');
        }, $html);

        return str_replace('{{host}}', htmlspecialchars($host, ENT_QUOTES, 'UTF-8'), $html);
    }
}
