<?php

namespace SatApi\Support;

use SatApi\Config;

/**
 * Injects the Cap asset overrides into a document's <head>.
 *
 * The widget normally pulls its WASM solver and a pako fallback from
 * `cdn.jsdelivr.net`. Two independent mechanisms keep that request on our own origin:
 *
 *   1. nginx rewrites the literal jsDelivr prefix inside the vendored widget
 *      (`sub_filter`). Efficient, but a literal match: if Cap renames or reflows that
 *      URL the rewrite stops matching and visitors silently start pulling code from a
 *      third party again, with nothing to notice.
 *   2. The globals below, set BEFORE the widget is loaded. These make the widget ask
 *      for our paths regardless of what its own source says.
 *
 * Belt and braces on purpose: mechanism 2 is the guarantee, mechanism 1 is the
 * optimisation. When Cap's own asset server is used with `CACHE_HOST` pointed at
 * jsDelivr -- the upstream default -- this is the only thing standing between a
 * visitor's browser and a third-party CDN.
 *
 * The version numbers track the vendored file names; the widget appends
 * `browser/cap_wasm_bg.wasm` to the WASM URL and `/pako@…/dist/pako_inflate.min.js`
 * to CAP_PAKO_URL. Paths are same-origin, so no third-party origin has to be allowed
 * by the content security policy (and none is).
 */
final class CapBootstrap
{
    /** Vendored widget/asset versions. Update together with assets/vendor/cap/. */
    const WIDGET_VERSION = '0.1.58';
    const WASM_VERSION = '0.0.8';
    const PAKO_VERSION = '2.1.0';

    /**
     * True when the proof of work is switched on and keyed, i.e. when the browser will
     * actually load the widget.
     */
    public static function active()
    {
        return Pow::enabled();
    }

    /**
     * Quiet on pages that do not run the challenge.
     */
    public static function script()
    {
        return self::render();
    }

    /**
     * The <script> element, already closed.
     *
     * Inline rather than a separate file so it cannot be cached independently of the
     * page that depends on it: a stale copy pointing at a version we no longer vendor
     * would break the challenge in a way that is hard to diagnose.
     *
     * The URLs are the COMPLETE asset paths, not directories. The widget does
     * `fetch(CAP_CUSTOM_WASM_URL).then(r => r.arrayBuffer()).then(WebAssembly.compile)`
     * and likewise for hashwx, so each variable must name a `.wasm` file. Pointing them
     * at a directory produced a request for the directory itself, which 404'd and left
     * the overlay stuck on "Verifying" -- a silent failure, because a failed wasm load
     * only logs a debug line. CAP_PAKO_URL is a <script src>, but the same reasoning
     * applies: name the file.
     *
     * @return string
     */
    public static function render()
    {
        $base = '/cap/vendor';

        return '<script>(function(){'
            . 'var b=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';'
            // fetch() targets: full file paths.
            . 'window.CAP_CUSTOM_WASM_URL=b+"/cap_wasm_bg.wasm";'
            . 'window.CAP_CUSTOM_HASHWX_URL=b+"/hashwx.wasm";'
            // <script src> target: also the file itself.
            . 'window.CAP_PAKO_URL=b+"/pako_inflate.min.js";'
            // Cap prints a version banner to the console unless silenced; the banner
            // is useful while wiring the challenge up and noise afterwards.
            . 'window.CAP_SILENT=' . ((bool) Config::get('pow.debug', false) ? 'false' : 'true') . ';'
            . '})();</script>';
    }

    /**
     * Insert the bootstrap into a document, immediately before </head>.
     *
     * Used for pages that are still authored as static HTML (docs.html): the page keeps
     * its own markup, and only this one element is injected, so the served document
     * cannot drift from the file on disk in any other way.
     *
     * @param string $html
     * @return string
     */
    public static function inject($html)
    {
        $html = (string) $html;
        if (!self::active()) {
            return $html;
        }
        $needle = '</head>';
        $pos = stripos($html, $needle);
        if ($pos === false) {
            return $html;
        }

        return substr($html, 0, $pos) . self::render() . "\n" . substr($html, $pos);
    }
}
