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
\SatApi\Logger::setRequestId($request->requestId());

// A legacy .php shim has already decided which route it is.
if (defined('SATAPI_LEGACY_ROUTE')) {
    \SatApi\Api\Router::dispatch($request, SATAPI_LEGACY_ROUTE);
    exit;
}

// Documents before the router: they are pages, not API routes, and the router would
// answer them with a 404 problem document.
if (\SatApi\HomePage::handles($request)) {
    \SatApi\HomePage::render($request);
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

\SatApi\Api\Router::dispatch($request);
