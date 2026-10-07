<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\CapBootstrap;
use SatApi\Support\Consent;
use SatApi\Support\I18n;

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
        foreach (array('tokens.css', 'app.css', 'md3.css', 'app.js', 'consent.js', 'swagger-theme.css') as $asset) {
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

        // The shared shell carries the announcement bar, the consent dialog, the
        // proof-of-work overlay and the boot script. It is inserted immediately after
        // <body> so a visitor who blocks JavaScript still sees the notice and can reach
        // the policy, and so this page has the language switcher like the others.
        //
        // The page keeps its authored markup: only the attributes on <body> are added
        // and one block is inserted. Nothing else about docs.html is rewritten, so the
        // served document cannot drift from the file on disk.
        $locale = I18n::resolve($request);
        $bodyAttrs = ' data-page="docs" data-locale="' . htmlspecialchars($locale['code'], ENT_QUOTES, 'UTF-8') . '"';
        $html = preg_replace('/<body(\s[^>]*)?>/i', '<body$1' . $bodyAttrs . '>', $html, 1, $tagCount);

        $shell = self::shell();
        if ($shell !== '') {
            $pos = stripos($html, '<body');
            $end = $pos === false ? false : strpos($html, '>', $pos);
            if ($end !== false) {
                $html = substr($html, 0, $end + 1) . $shell . substr($html, $end + 1);
            }
        }

        $etag = '"' . substr(hash('sha256', $html), 0, 32) . '"';
        $headers = array(
            'Cache-Control' => 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400',
            'Vary'          => 'Cookie, Accept-Encoding',
            'ETag'          => $etag,
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
}
