<?php
/**
 * Runtime capability probe.
 *
 *   php tests/probe.php            # human readable
 *   php tests/probe.php --json     # machine readable (used by the test harness)
 *
 * Run this first on a new server: it reports the PHP version, the extensions
 * the API depends on, whether the writable directories exist, and the effective
 * configuration. Exit code 1 means a required capability is missing.
 */

require_once __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;
use SatApi\Config;

$json = in_array('--json', isset($argv) ? $argv : array(), true);

$extensions = array(
    'curl'    => array('required' => true,  'loaded' => function_exists('curl_init'), 'why' => 'fetching upstream data'),
    'json'    => array('required' => true,  'loaded' => function_exists('json_encode'), 'why' => 'JSON responses and SatNOGS parsing'),
    'zlib'    => array('required' => false, 'loaded' => function_exists('gzencode'), 'why' => 'gzip fallback when the web server does not compress'),
    'redis'   => array('required' => false, 'loaded' => class_exists('Redis'), 'why' => 'shared cache and maintenance switches'),
    'openssl' => array('required' => false, 'loaded' => function_exists('openssl_decrypt'), 'why' => 'legacy AES ticket verification'),
    'mbstring' => array('required' => false, 'loaded' => function_exists('mb_strlen'), 'why' => 'not used, listed for completeness'),
);

$directories = array(
    'cache'     => (string) Config::get('cache.dir'),
    'logs'      => (string) Config::get('logs.dir'),
    'maintenance' => (string) Config::get('maintenance.dir'),
    'ratelimit' => (string) Config::get('ratelimit.dir'),
);
$dirState = array();
foreach ($directories as $name => $path) {
    $dirState[$name] = array(
        'path'     => $path,
        'exists'   => is_dir($path),
        'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path)),
    );
}

$missingRequired = array();
foreach ($extensions as $name => $info) {
    if ($info['required'] && !$info['loaded']) {
        $missingRequired[] = $name;
    }
}

$report = array(
    'php_version'   => PHP_VERSION,
    'sapi'          => PHP_SAPI,
    'extensions'    => $extensions,
    'directories'   => $dirState,
    'cache_backend' => Store::backendName(),
    'config'        => array(
        'service_version'      => Config::get('service.version'),
        'redis_enabled'        => (bool) Config::get('cache.redis.enabled'),
        'verify_tls'           => (bool) Config::get('http.verify_tls'),
        'compression_enabled'  => (bool) Config::get('compression.enabled'),
        'rate_limit_enabled'   => (bool) Config::get('ratelimit.enabled'),
        'admin_token_set'      => Config::get('admin.token', '') !== '',
        'cors_allow_origin'    => Config::get('cors.allow_origin'),
        'log_level'            => Config::get('logs.level'),
    ),
    'ok' => empty($missingRequired),
);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), cache backend: ' . $report['cache_backend'] . "\n\n";
    echo "extensions:\n";
    foreach ($extensions as $name => $info) {
        printf("  %-9s %-8s %s\n", $name, $info['loaded'] ? 'loaded' : 'MISSING', $info['required'] ? '(required for ' . $info['why'] . ')' : '(optional: ' . $info['why'] . ')');
    }
    echo "\ndirectories:\n";
    foreach ($dirState as $name => $state) {
        printf("  %-12s %-8s %s\n", $name, $state['writable'] ? 'writable' : 'NOT WRITABLE', $state['path']);
    }
    echo "\nconfiguration:\n";
    foreach ($report['config'] as $key => $value) {
        printf("  %-22s %s\n", $key, is_bool($value) ? ($value ? 'true' : 'false') : $value);
    }
    echo "\n" . ($report['ok'] ? 'OK: all required capabilities present' : 'FAIL: missing required extension(s): ' . implode(', ', $missingRequired)) . "\n";
}

exit($report['ok'] ? 0 : 1);
