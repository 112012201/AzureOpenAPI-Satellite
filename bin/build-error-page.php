<?php
/**
 * Build the static error page: error.html
 *
 * Why a static file after all the routing work: `error_page 404 /error` never took
 * effect through either a fastcgi location or the front controller. Measured attempts
 * ruled out a missing `fastcgi_params`, the `internal` directive, the reply status
 * (200 vs 404), and `error_page ... =200`. nginx always answered with its own
 * 146-byte page -- and that page carries NO security headers, so every mistyped URL
 * was also the one response without them.
 *
 * A file on disk is the arrangement that demonstrably works here (the pre-existing
 * `error_page 404/404.html` is how this site behaved before), so the page is generated
 * instead of routed. Generating rather than hand-writing it keeps the single source of
 * truth: the announcement banner, the consent dialog, the boot overlay and the strings
 * all come from the same partials and conf/i18n.php that the rest of the site uses, so
 * the error page cannot drift from them.
 *
 * Language: a static file cannot negotiate Accept-Language, so ALL THREE locales are
 * baked in and the same client-side switcher that runs on every other page picks one.
 * A visitor without JavaScript sees the English block, which is the documented default.
 *
 * Usage:
 *   php bin/build-error-page.php            # write error.html next to the webroot
 *   php bin/build-error-page.php --check    # exit 1 if error.html is stale
 */

require __DIR__ . '/../bootstrap.php';

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Support\CapBootstrap;
use SatApi\Support\I18n;

$checkOnly = in_array('--check', $argv, true);
$target = Config::path('error.html');

$request = Request::fromParts('/error');
$esc = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

/* --- the shell, rendered once and reused for every locale ----------------- */
$bootPage = 'error';
$bootOwnConsent = false;
// A static file has no clock: an announcement baked in here would outlive its own
// `expires` date, so the board is skipped exactly like the address-dependent notice.
$bootStatic = true;
ob_start();
require __DIR__ . '/../partials/shell.php';
$shell = (string) ob_get_clean();

/* --- strings: one span per locale, all present, English active ------------ */
$loc = static function ($key) {
    $out = '';
    foreach (I18n::locales() as $code) {
        $text = I18n::translate($code, $key, $key);
        $active = $code === 'en';
        $out .= '<span class="i18n' . ($active ? ' is-active' : '') . '"'
            . ' lang="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
            . ($active ? '' : ' hidden') . '>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return $out;
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

$asset = static function ($relative) {
    $q = \SatApi\HomePage::assetQueryFor($relative);
    return '/assets/' . $relative . $q;
};

$document = '<!DOCTYPE html>' . "\n"
    . '<html lang="en">' . "\n"
    . '<head>' . "\n"
    . '<meta charset="utf-8">' . "\n"
    . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
    . '<meta name="robots" content="noindex">' . "\n"
    . '<title>404 &middot; ' . $esc((string) Config::get('service.name', '')) . '</title>' . "\n"
    . '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">' . "\n"
    . '<link rel="stylesheet" href="' . $esc($asset('tokens.css')) . '">' . "\n"
    . '<link rel="stylesheet" href="' . $esc($asset('app.css')) . '">' . "\n"
    . '<link rel="stylesheet" href="' . $esc($asset('md3.css')) . '">' . "\n"
    . '<link rel="stylesheet" href="' . $esc($asset('metro.css')) . '">' . "\n"
    . CapBootstrap::render() . "\n"
    . '</head>' . "\n"
    . '<body class="metro" data-page="error" data-locale="en">'
    . $shell
    . '<main class="wrap error-page" id="main"><div class="card error-page__card">'
    . '<p class="error-page__code">404</p>'
    . '<h1>' . $loc('error.notfound') . '</h1>'
    . '<p class="muted">' . $loc('error.return') . ' &rarr; <a href="/">/</a></p>'
    . '<ul class="error-page__list">' . $rows . '</ul>'
    . '<p><a class="btn btn--primary" href="/">' . $loc('error.return') . '</a> '
    . '<a class="btn btn--ghost" href="/docs">' . $loc('nav.docs') . '</a></p>'
    . '</div></main>'
    . '</body>' . "\n" . '</html>' . "\n";

$size = strlen($document);

if ($checkOnly) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current === $document) {
        echo "error.html is up to date ({$size} bytes)\n";
        exit(0);
    }
    fwrite(STDERR, "error.html is STALE (would be {$size} bytes)\n");
    exit(1);
}

if (is_file($target)) {
    copy($target, $target . '.bak');
}
if (file_put_contents($target, $document) === false) {
    fwrite(STDERR, "failed to write {$target}\n");
    exit(1);
}
@chmod($target, 0644);
echo "wrote {$target} ({$size} bytes)\n";
echo "  locales baked in: " . implode(', ', I18n::locales()) . "\n";
echo "  announcement: " . (\SatApi\Support\Announcement::current($request) === null ? 'none' : 'present') . "\n";
