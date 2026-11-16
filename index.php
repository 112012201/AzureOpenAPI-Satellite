<?php
/**
 * Front controller.
 *
 * Every dynamic request ends up here:
 *   - the API and legacy routes, dispatched through Router;
 *   - the landing page, rendered by HomePage (it needs a server-side locale
 *     decision, the announcement banner and the consent markup in the document);
 *   - the CLI, via Cli::run.
 *
 * Static assets (docs.html, openapi.yaml, the Swagger bundles, images) are served
 * directly by nginx and never reach PHP.
 *
 * The landing page used to be a static index.html served by nginx. It is rendered
 * here now because a static file cannot choose a language before the first byte or
 * put the notice and consent markup into the document. Everything else about the
 * routing contract is unchanged.
 */

require __DIR__ . '/bootstrap.php';

// CLI SAPI (including phpdbg and the embedded SAPI used by tooling) runs the
// command line interface; everything else is an HTTP request.
$isCli = in_array(PHP_SAPI, array('cli', 'phpdbg', 'embed'), true)
    && isset($argv) && is_array($argv);

if ($isCli && !defined('SATAPI_DISPATCH')) {
    exit(\SatApi\Cli::run($argv));
}

$request = \SatApi\Http\Request::capture();

// /no-accel is a stable, short address for the acceleration notice; the notice
// itself lives at /no-acceleration (a legal document, cached differently). The
// redirect is permanent because the short address is the one printed in the
// footer and must keep working if the document is ever renamed again.
if (rtrim((string) $request->path(), '/') === '/no-accel') {
    \SatApi\Http\Response::redirect('/no-acceleration', 301);
    exit;
}
\SatApi\Logger::setRequestId($request->requestId());

// A legacy .php shim has already decided which route it is.
if (defined('SATAPI_LEGACY_ROUTE')) {
    \SatApi\Api\Router::dispatch($request, SATAPI_LEGACY_ROUTE);
    exit;
}

// Documents before the router: they are pages, not API routes, and the router would
// answer them with a 404 problem document.
//
// The ServiceWorker is first of these because nginx does not serve /sw.js from disk:
// the worker has to live at the origin root to get scope "/", and it is published by
// PHP so that does not depend on the web server configuration (see the class).
if (\SatApi\ServiceWorker::handles($request)) {
    \SatApi\ServiceWorker::render($request);
    exit;
}
if (\SatApi\HomePage::handles($request)) {
    \SatApi\HomePage::render($request);
    exit;
}
if (\SatApi\VerifyPage::handles($request)) {
    \SatApi\VerifyPage::render($request);
    exit;
}
if (\SatApi\Docs::handles($request)) {
    \SatApi\Docs::render($request);
    exit;
}
if (\SatApi\ErrorPage::handles($request)) {
    \SatApi\ErrorPage::render($request, 404);
    exit;
}
$legalSection = \SatApi\Legal::section($request);
if ($legalSection !== null) {
    \SatApi\Legal::render($request, $legalSection);
    exit;
}

// The media key: one small JSON object, never cached, only for a browser that solved the
// challenge. Handled here rather than in the router because it is not part of the /v1
// contract (see lib/MediaKey.php).
if (\SatApi\MediaKey::handles($request)) {
    \SatApi\MediaKey::render($request);
    exit;
}

\SatApi\Api\Router::dispatch($request);
