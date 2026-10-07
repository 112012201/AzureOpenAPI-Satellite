<?php

namespace SatApi;

use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\CapBootstrap;
use SatApi\Support\I18n;
use SatApi\Support\Pow;

/**
 * The dedicated verification page, at `/verify`.
 *
 * Why it exists: the challenge used to appear wherever the overlay happened to leave its host
 * element, which put it far down the document rather than in front of the visitor who was being
 * asked to solve it. A verification step belongs on its own page, where there is nothing else to
 * look at and nothing else to do -- and where a failed or unavailable challenge has somewhere to
 * send the visitor that is not the page they were trying to read.
 *
 * The flow:
 *
 *   phase-2 gate cannot verify  ->  /verify?return=<path>  ->  solved  ->  back to <path>
 *
 * `return` is validated as a site-relative path so the page cannot be used as an open redirect.
 * A visitor who already holds a valid session is sent straight back rather than shown the
 * challenge again.
 *
 * The page deliberately does not carry the announcement bar or the consent dialog: it is a step
 * in a flow, not a destination, and stacking two dialogs on a verification prompt would give the
 * visitor two things to dismiss before they could do the one thing they came for.
 */
final class VerifyPage
{
    /** Longest path we will accept as a return target. */
    const MAX_RETURN = 512;

    public static function handles(Request $request)
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return false;
        }

        return rtrim((string) $request->path(), '/') === '/verify';
    }

    /**
     * Where the visitor asked to go, or the home page.
     *
     * Only a site-relative path is accepted, and it must start with a single slash. A value like
     * `//evil.example` is a protocol-relative URL, and `https://evil.example` is absolute; both
     * would turn this page into an open redirect, so both are rejected. Control characters and
     * anything that could break out of the attribute are dropped too.
     *
     * @return string
     */
    public static function returnTarget(Request $request)
    {
        $raw = (string) $request->param('return', '');
        if ($raw === '' || strlen($raw) > self::MAX_RETURN) {
            return '/';
        }
        $raw = str_replace(array("\r", "\n", "\t", "\0"), '', $raw);
        // Exactly one leading slash, and no scheme.
        if ($raw[0] !== '/' || substr($raw, 0, 2) === '//') {
            return '/';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
            return '/';
        }

        return $raw;
    }

    public static function render(Request $request)
    {
        $target = self::returnTarget($request);

        // Already verified: there is nothing to do here.
        if (Pow::enabled() && Pow::hasValidSession($request)) {
            Response::redirect($target, 302);
            return;
        }

        $locale = I18n::resolve($request);
        $code = $locale['code'];
        $t = static function ($key, $fallback = '') use ($code) {
            return I18n::translate($code, $key, $fallback);
        };
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $block = static function ($key, $fallback = '') use ($code, $esc) {
            $out = '';
            foreach (I18n::locales() as $localeCode) {
                $active = $localeCode === $code;
                $out .= '<span class="i18n' . ($active ? ' is-active' : '') . '"'
                    . ' lang="' . $esc($localeCode) . '" data-locale="' . $esc($localeCode) . '"'
                    . ($active ? '' : ' hidden') . '>'
                    . $esc(I18n::translate($localeCode, $key, $fallback)) . '</span>';
            }

            return $out;
        };

        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $asset = static function ($relative) {
            return '/assets/' . $relative . HomePage::assetQueryFor($relative);
        };

        $head = array(
            '<!DOCTYPE html>',
            '<html lang="' . $esc($code) . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<meta name="robots" content="noindex">',
            '<title>' . $esc($t('verify.title', 'Verification')) . ' &middot; ' . $esc($name) . '</title>',
            '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32">',
            '<link rel="stylesheet" href="' . $esc($asset('tokens.css')) . '">',
            '<link rel="stylesheet" href="' . $esc($asset('app.css')) . '">',
            '<link rel="stylesheet" href="' . $esc($asset('md3.css')) . '">',
            CapBootstrap::render(),
            '</head>',
        );

        $body = '<body class="verify-page" data-page="verify" data-locale="' . $esc($code) . '"'
            . ' data-verify-return="' . $esc($target) . '">'
            . '<main class="wrap verify-page__main" id="main">'
            . '<div class="card verify-page__card">'
            . '<p class="eyebrow">' . $block('verify.eyebrow', 'One quick step') . '</p>'
            . '<h1>' . $block('verify.title', 'Verification') . '</h1>'
            . '<p class="muted">' . $block('verify.intro', 'This site asks for a one-off browser check before serving the overview and the API reference. It takes a moment and needs no account.') . '</p>'
            . '<div class="verify-page__slot" data-cap-host></div>'
            . '<p class="verify-page__status" data-verify-status>' . $block('verify.waiting', 'Waiting for the check to start…') . '</p>'
            . '<p class="verify-page__actions">'
            . '<button class="btn btn--primary" type="button" data-verify-retry>' . $block('verify.retry', 'Start the check') . '</button>'
            . '<a class="btn btn--ghost" href="' . $esc($target) . '">' . $block('verify.skip', 'Continue without verifying') . '</a>'
            . '</p>'
            . '</div>'
            . '<p class="verify-page__note muted">' . $block('verify.note', 'The check only proves a browser is present. Nothing about you is stored or sent anywhere else.') . '</p>'
            . '</main>'
            . '<script>'
            . 'window.__SATAPI_VERIFY__=' . json_encode(array(
                'endpoint'   => (string) Config::get('pow.endpoint', '/cap'),
                'siteKey'    => (string) Config::get('pow.site_key', ''),
                'verifyUrl'  => '/v1/consent/verify-pow',
                'returnTo'   => $target,
                'enabled'    => Pow::enabled(),
                'strings'    => array(
                    'waiting'  => I18n::translate($code, 'verify.waiting', 'Waiting for the check to start…'),
                    'working'  => I18n::translate($code, 'verify.working', 'Completing the check…'),
                    'done'     => I18n::translate($code, 'verify.done', 'Verified. Returning…'),
                    'failed'   => I18n::translate($code, 'verify.failed', 'The check could not be completed.'),
                    'retry'    => I18n::translate($code, 'verify.retry', 'Start the check'),
                ),
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
            . '</script>'
            . '<script src="' . $esc($asset('consent.js')) . '" defer></script>'
            . '</body>' . "\n" . '</html>' . "\n";

        Response::raw(implode("\n", $head) . "\n" . $body, 'text/html; charset=utf-8', 200, array(
            // A verification step is per-visitor: never a shared cache entry.
            'Cache-Control' => 'no-store',
            'Vary'          => 'Accept-Language, Cookie',
        ));
    }
}
