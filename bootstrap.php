<?php
/**
 * Application bootstrap.
 *
 * Loaded by index.php and by the legacy .php shims. Keeps side effects in one
 * place: configuration, autoloading, error handling, storage directories and
 * the metrics flush at the end of the request.
 */

if (defined('SATAPI_BOOTSTRAP')) {
    return;
}
define('SATAPI_BOOTSTRAP', true);
define('SATAPI_ROOT', __DIR__);
define('SATAPI_START', microtime(true));

require __DIR__ . '/lib/autoload.php';

date_default_timezone_set('UTC');

\SatApi\Config::init(__DIR__);
\SatApi\Logger::init();
\SatApi\Errors::register();

// Storage directories (kept outside the web-exposed static assets).
foreach (array(
    (string) \SatApi\Config::get('cache.dir'),
    (string) \SatApi\Config::get('ratelimit.dir'),
    (string) \SatApi\Config::get('logs.dir'),
    \SatApi\Config::path('data'),
) as $dir) {
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
}

register_shutdown_function(array('SatApi\\Support\\Metrics', 'flush'));
