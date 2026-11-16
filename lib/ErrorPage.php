<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Announcement;
use SatApi\Support\CapBootstrap;
use SatApi\Support\I18n;
use SatApi\Support\LocalePicker;

/**
 * The error page, reached through the front controller at `/error`.
 *
 * Why it is a routed page rather than `404.php` served by its own fastcgi location:
 * the earlier arrangement never actually ran. nginx answered with its own 146-byte
 * page while `error_page 404 /404.php` sat in the vhost and `nginx -t` was clean --
 * a configuration that is legal, loads, and silently does nothing. Measured attempts
 * ruled out the two obvious causes (a missing `fastcgi_params`, then the `internal`
 * directive) and the remaining suspect was a pre-existing location in the panel vhost
 * winning the regex race for `/404.php`.
 *
 * Routing it instead removes the whole class of problem: it travels the same path as
 * every other page, so there is no second fastcgi location to keep correct, and the
 * nginx side is a single `error_page 404 /error;` line whose target is matched by the
 * same rule that already serves the site.
 *
 * The page renders in the visitor's language and carries the announcement banner, for
 * the same reason the landing page does: a visitor who mistyped a URL should still get
 * an answer they can read, and still be able to reach the policy.
 */
final class ErrorPage
{
    /** Paths this renderer answers. `/error` is what nginx's error_page points at. */
    public static function handles(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return false;
        }
        $path = rtrim((string) $request->path(), '/');

        return $path === '/error' || $path === '/404' || $path === '/error-404';
    }

    public static function render(Request $request, $status = 404)
    {
        $status = (int) $status;

        /*
         * Reply 200, not the error status, and let nginx's error_page own the code.
         *
         * This is not a workaround for a detail -- it is the only arrangement that works
         * with `fastcgi_intercept_errors on`, which this vhost sets. That directive makes
         * nginx replace any upstream 4xx/5xx with the configured error page, so an error
         * page that itself answers 404 gets intercepted again: nginx answers with its own
         * 146-byte page and the real one never reaches the browser. Measured loop:
         * `/error` -> PHP renders -> nginx sees 404 -> serves /error -> renders -> ...
         * and settles on the built-in page.
         *
         * The status a visitor sees is still 404, because the error_page that produced
         * this render carries it. `X-Robots-Tag: noindex` stops a crawler treating the
         * body as a real page, and `no-store` keeps it out of every cache.
         */
        $replyStatus = 200;
        $locale = I18n::resolve($request);
        $code = (string) $locale['code'];
        $tag = isset($locale['tag']) ? (string) $locale['tag'] : $code;
        $t = static function ($key, $fallback = '') use ($code) {
            return I18n::translate($code, $key, $fallback);
        };
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $shell = self::capture(__DIR__ . '/../partials/shell.php', array(
            'bootPage'       => 'error',
            // No proof of work on an error page: a visitor who mistyped a URL should get
            // an answer, not a challenge.
            'bootOwnConsent' => false,
        ));

        // A short, useful index instead of a bare apology: on an API service the most
        // likely reason someone is here is a mistyped path.
        $links = array(
            array('/v1/tle', 'GET /v1/tle', 'error.link.tle', 'Merged TLE feed, text or JSON'),
            array('/v1/satellites', 'GET /v1/satellites', 'error.link.sats', 'SatNOGS satellite catalogue'),
            array('/v1/transmitters', 'GET /v1/transmitters', 'error.link.tx', 'SatNOGS transmitter catalogue'),
            array('/v1/amsat/status', 'GET /v1/amsat/status', 'error.link.amsat', 'AMSAT live status reports'),
            array('/v1/health', 'GET /v1/health', 'error.link.health', 'Health and readiness'),
            array('/docs', 'GET /docs', 'error.link.docs', 'API reference'),
        );
        $rows = '';
        foreach ($links as $link) {
            // The path is an API address and stays as it is; the sentence after it is
            // prose and is translated.
            $rows .= '<li><code>' . $esc($link[1]) . '</code> &mdash; ' . $esc($t($link[2], $link[3])) . '</li>';
        }

        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $head = array(
            '<!DOCTYPE html>',
            '<html lang="' . $esc($tag) . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<meta name="robots" content="noindex">',
            '<title>' . $status . ' &middot; ' . $esc($name) . '</title>',
            '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">',
            '<link rel="stylesheet" href="/assets/tokens.css' . HomePage::assetQueryFor('tokens.css') . '">',
            '<link rel="stylesheet" href="/assets/app.css' . HomePage::assetQueryFor('app.css') . '">',
            '<link rel="stylesheet" href="/assets/md3.css' . HomePage::assetQueryFor('md3.css') . '">',
            '<link rel="stylesheet" href="/assets/metro.css' . HomePage::assetQueryFor('metro.css') . '">',
            CapBootstrap::render(),
            '</head>',
        );

        $document = implode("\n", $head) . "\n"
            . '<body class="metro" data-page="error" data-locale="' . $esc($code) . '">'
            . $shell
            . '<main class="wrap error-page" id="main">'
            . '<div class="card error-page__card">'
            . '<p class="error-page__code">' . $status . '</p>'
            . '<h1>' . $esc($t('error.notfound', 'There is no resource at this address.')) . '</h1>'
            . '<p class="muted">' . $esc($t('error.return', 'Back to the overview')) . ' &rarr; <a href="/">/</a></p>'
            . '<ul class="error-page__list">' . $rows . '</ul>'
            . '<p><a class="btn btn--primary" href="/">' . $esc($t('error.return', 'Back to the overview')) . '</a> '
            . '<a class="btn btn--ghost" href="/docs">' . $esc($t('nav.docs', 'API docs')) . '</a></p>'
            . '<p class="error-page__lang">' . LocalePicker::render($locale, 'lang lang--inline') . '</p>'
            . '</div></main></body>' . "\n" . '</html>' . "\n";

        Response::raw($document, 'text/html; charset=utf-8', $replyStatus, array(
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex',
            'Vary'          => 'Accept-Language, Cookie, Accept-Encoding',
        ));
    }

    private static function capture($file, array $vars)
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
