<?php
/**
 * Front controller.
 *
 * Every dynamic request (v1 API, legacy routes, legacy .php files) ends up
 * here. Static assets (index.html, docs.html, openapi.yaml, swagger bundles)
 * are served directly by nginx and never reach PHP.
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

if (defined('SATAPI_LEGACY_ROUTE')) {
    \SatApi\Api\Router::dispatch($request, SATAPI_LEGACY_ROUTE);
} else {
    \SatApi\Api\Router::dispatch($request);
}
