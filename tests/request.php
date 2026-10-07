<?php
/**
 * Single-request dispatcher for debugging and end-to-end verification.
 *
 * Runs one request through the real front controller without a web server:
 *
 *   php tests/request.php GET /v1/tle/weather?format=json --seed
 *   php tests/request.php HEAD /v1/tle --accept=text/plain
 *   php tests/request.php GET /v1/tle --header=If-None-Match:"abc"
 *
 * Prints the response body, then a `<<<STATUS:n>>>` marker on the last line so
 * a shell or CI script can assert on the status code.
 *
 * `--seed` fills the cache with the tests/fixtures data (no network needed).
 */

require_once __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Data\Amsat;
use SatApi\Tle\Parser;

$argvList = isset($argv) ? $argv : array();
$method = isset($argvList[1]) ? strtoupper($argvList[1]) : 'GET';
$uri = isset($argvList[2]) ? $argvList[2] : '/v1/health';

$accept = 'application/json';
$headers = array();
$maintenanceFlags = array();
$configOverrides = array();
$seedStamp = null;
$seed = false;

for ($i = 3; $i < count($argvList); $i++) {
    $arg = $argvList[$i];
    if ($arg === '--seed') {
        $seed = true;
    } elseif (strpos($arg, '--accept=') === 0) {
        $accept = substr($arg, 9);
    } elseif (strpos($arg, '--header=') === 0) {
        $headers[] = substr($arg, 9);
    } elseif (strpos($arg, '--maintenance=') === 0) {
        $maintenanceFlags[] = substr($arg, 14);
    } elseif (strpos($arg, '--seed-stamp=') === 0) {
        $seedStamp = (int) substr($arg, 13);
    } elseif (strpos($arg, '--config=') === 0) {
        $configOverrides[] = substr($arg, 9);
    }
}

// Keep the test run away from production state.
$tmp = __DIR__ . '/tmp';
@mkdir($tmp . '/cache/locks', 0777, true);
Config::set('cache.redis.enabled', false);
Config::set('cache.dir', $tmp . '/cache');
Config::set('maintenance.dir', $tmp);
Config::set('metrics.file', $tmp . '/metrics.json');
Config::set('logs.dir', $tmp);
// External gates off. The consent gate (451) and the proof-of-work challenge are
// DEPLOYMENT settings: this deployment has both on. Leaving them on in tests means the
// suite result would depend on conf/config.php, and an unrelated change to that file
// would turn into a pile of failures here. The gates themselves are asserted directly
// (see the consent and pow sections) rather than by driving every request through them.
Config::set('consent.required', false);
Config::set('pow.enabled', false);
\SatApi\Logger::init($tmp, 'request.log', 'error');

// --config=path.to.key=value  (test seam for tuning compression, rate limits…)
foreach ($configOverrides as $override) {
    $split = explode('=', $override, 2);
    if (count($split) === 2) {
        $value = $split[1];
        if (is_numeric($value)) {
            $value = $value + 0;
        } elseif (in_array(strtolower($value), array('true', 'false'), true)) {
            $value = strtolower($value) === 'true';
        }
        Config::set($split[0], $value);
    }
}

if ($seed) {
    if (in_array('--debug-flags', $argvList, true)) {
        error_log('[request] seedStamp=' . var_export($seedStamp, true) . ' argv=' . json_encode($argvList));
    }
    seedCache(__DIR__ . '/fixtures', $seedStamp === null ? time() : $seedStamp);
}

foreach ($maintenanceFlags as $endpoint) {
    \SatApi\Support\Maintenance::set($endpoint, true, 'Scheduled upgrade window', 300);
}

// --- build the fake SAPI environment -------------------------------------
$parts = parse_url($uri);
$path = isset($parts['path']) ? $parts['path'] : '/';
$query = isset($parts['query']) ? $parts['query'] : '';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['PATH_INFO'] = $path;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST'] = 'satellite.api.authskin.xyz';
$_SERVER['HTTP_ACCEPT'] = $accept;
$_SERVER['HTTP_USER_AGENT'] = 'satapi-request-runner/1.0';
$_SERVER['HTTPS'] = 'on';

foreach ($headers as $header) {
    $split = explode(':', $header, 2);
    if (count($split) === 2) {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', trim($split[0])));
        $_SERVER[$name] = trim($split[1]);
    }
}
if (isset($_SERVER['HTTP_CONTENT_TYPE'])) {
    $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
    unset($_SERVER['HTTP_CONTENT_TYPE']);
}

define('SATAPI_DISPATCH', true);

// Expose the emitted status + headers so a harness can assert on the real HTTP
// contract (the CLI SAPI gives us no other way to read them back).
$captureFile = __DIR__ . '/tmp/response.json';
@mkdir(__DIR__ . '/tmp', 0777, true);
\SatApi\Http\Response::$observer = static function ($status, array $headers, $bytes) use ($captureFile) {
    @file_put_contents($captureFile, json_encode(array(
        'status'  => $status,
        'headers' => $headers,
        'bytes'   => $bytes,
    ), JSON_UNESCAPED_SLASHES));
};

register_shutdown_function(static function () {
    $code = function_exists('http_response_code') ? http_response_code() : 0;
    echo "\n<<<STATUS:" . (int) $code . ">>>\n";
});

require __DIR__ . '/../index.php';

/**
 * Populate the cache with the fixture data sets.
 *
 * @param string $fixtures
 * @param int    $storedAt  Fixed cache stamp so repeated runs produce a
 *                          byte-identical representation (stable ETags).
 */
function seedCache($fixtures, $storedAt)
{
    $tle = array(
        'weather' => array('three-line.txt', 'tle-text'),
        'amateur' => array('three-line.txt', 'tle-text'),
        'amsat' => array('three-line.txt', 'tle-text'),
    );
    foreach ($tle as $id => $spec) {
        $raw = file_get_contents($fixtures . '/' . $spec[0]);
        $records = Parser::parseText($raw, $id);
        Store::write('dataset:tle:' . $id, $records, 86400, 'serialize', 10800, $storedAt);
    }

    $satnogs = Parser::parseSatnogsJson(file_get_contents($fixtures . '/satnogs-tle.json'), 'satnogs');
    Store::write('dataset:tle:satnogs', $satnogs, 86400, 'serialize', 10800, $storedAt);

    Store::write(
        'dataset:satnogs_satellites',
        json_decode(file_get_contents($fixtures . '/satnogs-satellites.json'), true),
        86400,
        'serialize',
        21600,
        $storedAt
    );
    Store::write(
        'dataset:satnogs_transmitters',
        json_decode(file_get_contents($fixtures . '/satnogs-transmitters.json'), true),
        86400,
        'serialize',
        21600,
        $storedAt
    );

    $reports = Amsat::parse(
        file_get_contents($fixtures . '/amsat-status.html'),
        array('AO-91' => '43017', 'AO-7' => '07530', 'ISS' => '25544')
    );
    Store::write('dataset:amsat_status', $reports['reports'], 86400, 'serialize', 3600, $storedAt);
}
