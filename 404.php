<?php
/**
 * Error page, served by nginx via `error_page 404 /404.php`.
 *
 * PHP rather than a static file so the message appears in the visitor's language
 * and so the announcement banner and consent dialog stay consistent with the rest
 * of the site. It deliberately does NOT run the proof-of-work overlay: a visitor
 * who mistyped a URL should get an answer, not a challenge.
 *
 * It never reads the request path beyond the shell's locale negotiation, so there is
 * nothing here to reflect back and nothing to escape.
 */

require __DIR__ . '/bootstrap.php';

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\I18n;

$request = Request::capture();
$locale = I18n::resolve($request);
$code = $locale['code'];
$t = static function ($key, $fallback = '') use ($code) {
    return I18n::translate($code, $key, $fallback);
};

$bootPage = 'error';
$bootOwnConsent = false;

ob_start();
require __DIR__ . '/partials/shell.php';
$shell = (string) ob_get_clean();

$esc = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$links = array(
    array('/v1/tle', 'GET /v1/tle', 'Merged TLE feed, text or JSON'),
    array('/v1/satellites', 'GET /v1/satellites', 'SatNOGS satellite catalogue'),
    array('/v1/transmitters', 'GET /v1/transmitters', 'SatNOGS transmitter catalogue'),
    array('/v1/amsat/status', 'GET /v1/amsat/status', 'AMSAT live status reports'),
    array('/v1/health', 'GET /v1/health', 'Health and readiness'),
    array('/docs', 'GET /docs', 'API reference'),
);

$rows = '';
foreach ($links as $link) {
    $rows .= '<li><code>' . $esc($link[1]) . '</code> &mdash; ' . $esc($link[2]) . '</li>';
}

$document = '<!DOCTYPE html>' . "\n"
    . '<html lang="' . $esc($code) . '">' . "\n"
    . '<head>' . "\n"
    . '<meta charset="utf-8">' . "\n"
    . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
    . '<meta name="robots" content="noindex">' . "\n"
    . '<title>404 &middot; ' . $esc((string) Config::get('service.name', '')) . '</title>' . "\n"
    . '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">' . "\n"
    . '<link rel="stylesheet" href="/assets/app.css">' . "\n"
    . \SatApi\Support\CapBootstrap::render() . "\n"
    . '</head>' . "\n"
    . '<body data-page="error" data-locale="' . $esc($code) . '">'
    . $shell
    . '<main class="wrap error-page" id="main">'
    . '<div class="card error-page__card">'
    . '<p class="error-page__code">404</p>'
    . '<h1>' . $esc($t('error.notfound', 'There is no resource at this address.')) . '</h1>'
    . '<p class="muted">' . $esc($t('error.return', 'Back to the overview')) . ' &rarr; <a href="/">/</a></p>'
    . '<ul class="error-page__list">' . $rows . '</ul>'
    . '<p><a class="btn btn--primary" href="/">' . $esc($t('error.return', 'Back to the overview')) . '</a> '
    . '<a class="btn btn--ghost" href="/docs">' . $esc($t('nav.docs', 'API docs')) . '</a></p>'
    . '</div>'
    . '</main>'
    . '</body>' . "\n" . '</html>' . "\n";

Response::raw($document, 'text/html; charset=utf-8', 404, array(
    'Cache-Control' => 'no-store',
    'Vary'          => 'Accept-Language, Cookie, Accept-Encoding',
));
