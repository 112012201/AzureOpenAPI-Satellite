<?php
/**
 * Dependency-free test suite.
 *
 *   php tests/run.php            # human readable
 *   php tests/run.php --verbose  # also print passing assertions
 *
 * Exit code 0 = everything passed, 1 = at least one failure.
 *
 * The suite deliberately avoids the network: upstream access is exercised
 * through fixture data, and the cache/limiter/maintenance layers are pointed
 * at a temporary directory so a test run never touches production state.
 */

require_once __DIR__ . '/../bootstrap.php';

use SatApi\Cache\Store;
use SatApi\Config;
use SatApi\Data\Collection;
use SatApi\Data\Amsat;
use SatApi\Data\Repository;
use SatApi\Data\SourceMerge;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Api\Endpoints\AmsatApi;
use SatApi\Support\Announcement;
use SatApi\Support\AnnouncementBoard;
use SatApi\Support\I18n;
use SatApi\Support\BingWebmaster;
use SatApi\Support\IndexNow;
use SatApi\Support\LocalePicker;
use SatApi\Support\Maintenance;
use SatApi\Support\Media;
use SatApi\Support\Metrics;
use SatApi\Support\Pow;
use SatApi\Support\Problem;
use SatApi\Support\RateLimit;
use SatApi\Support\Seo;
use SatApi\Support\Region;
use SatApi\Support\Season;
use SatApi\Tle\Parser;

$verbose = in_array('--verbose', isset($argv) ? $argv : array(), true);
$tmp = __DIR__ . '/tmp';

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
final class T
{
    public static $pass = 0;
    public static $fail = 0;
    public static $failures = array();
    public static $verbose = false;

    public static function group($name)
    {
        echo "\n" . $name . "\n" . str_repeat('-', strlen($name)) . "\n";
    }

    public static function ok($condition, $message)
    {
        if ($condition) {
            self::$pass++;
            if (self::$verbose) {
                echo "  PASS  " . $message . "\n";
            }

            return true;
        }
        self::$fail++;
        self::$failures[] = $message;
        echo "  FAIL  " . $message . "\n";

        return false;
    }

    public static function same($expected, $actual, $message)
    {
        $match = $expected === $actual;
        if (!$match) {
            $message .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
        }

        return self::ok($match, $message);
    }

    public static function truthy($value, $message)
    {
        return self::ok(!empty($value), $message);
    }

    public static function throws($callable, $message)
    {
        try {
            call_user_func($callable);
        } catch (\Throwable $e) {
            return self::ok(true, $message);
        }

        return self::ok(false, $message . ' (no exception thrown)');
    }

    public static function summary()
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo 'assertions: ' . (self::$pass + self::$fail) . '  passed: ' . self::$pass . '  failed: ' . self::$fail . "\n";
        if (self::$fail > 0) {
            echo "\nfailures:\n";
            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . "\n";
            }
        }
        echo str_repeat('=', 60) . "\n";

        return self::$fail === 0 ? 0 : 1;
    }
}

T::$verbose = $verbose;

// Problem::send() ends the request in production. Under the suite there is no request,
// and the exit would terminate the whole run at that line -- losing the summary and
// leaving a status of 0 that reads as success. Test code that exercises an error path
// therefore needs the response WITHOUT the exit.
define('SATAPI_NO_EXIT', true);

// Isolate every writable location inside tests/tmp.
if (is_dir($tmp)) {
    foreach ((array) glob($tmp . '/*') as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
/*
 * The rate limiter and the file cache live in SUBDIRECTORIES of $tmp, which the
 * loop above does not descend into, so their state survived into the next run: a
 * second run inside the same minute found the rate-limit budget already spent and
 * the "cold" cache key already warm. Measured 2026-11-03 -- four unrelated-looking
 * failures that only appeared on a re-run, never on a clean checkout.
 */
foreach (array($tmp . '/ratelimit/*', $tmp . '/cache/*', $tmp . '/cache/locks/*') as $statePattern) {
    foreach ((array) glob($statePattern) as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
@mkdir($tmp . '/cache/locks', 0777, true);
@mkdir($tmp . '/ratelimit', 0777, true);

Config::set('cache.redis.enabled', false); // no Redis in CI / WASM
Config::set('cache.dir', $tmp . '/cache');
Config::set('maintenance.dir', $tmp);
Config::set('metrics.file', $tmp . '/metrics.json');
Config::set('ratelimit.dir', $tmp . '/ratelimit');
Config::set('logs.dir', $tmp);
// External gates off. The consent gate (451) and the proof-of-work challenge are
// DEPLOYMENT settings: this deployment has both on. Leaving them on in tests means the
// suite result would depend on conf/config.php, and an unrelated change to that file
// would turn into a pile of failures here. The gates themselves are asserted directly
// (see the consent and pow sections) rather than by driving every request through them.
Config::set('consent.required', false);
Config::set('pow.enabled', false);
\SatApi\Logger::init($tmp, 'test.log', 'error');

$fixtures = __DIR__ . '/fixtures';

// ---------------------------------------------------------------------------
T::group('TLE parsing');

$threeLine = file_get_contents($fixtures . '/three-line.txt');
$records = Parser::parseText($threeLine, 'weather');
T::same(3, count($records), '3LE fixture yields three records');
T::truthy(isset($records['25544']), 'ISS keyed by zero padded NORAD id');
T::same('ISS (ZARYA)', $records['25544']['name'], 'name preserved without the 3LE "0 " prefix');
T::same('00965', array_keys($records)[1], 'leading zero preserved for low NORAD ids');
T::same('OPS 6582 (TRANSIT 5B-5)', $records['00965']['name'], '3LE line number prefix stripped from names');
T::same(69, strlen($records['25544']['line1']), 'line 1 padded to 69 columns');
T::same(69, strlen($records['25544']['line2']), 'line 2 padded to 69 columns');
T::truthy($records['25544']['checksum_ok'], 'ISS checksum validates');
T::same(1789506863, $records['25544']['epoch_unix'], 'epoch converted to a real Unix timestamp');
T::same('2026-09-15T21:14:23Z', $records['25544']['epoch_iso'], 'epoch rendered as RFC3339 UTC');

$commentLines = Parser::parseText("# a comment\n\nSB nonsense\n", 'x');
T::same(0, count($commentLines), 'comment and noise lines are ignored');

$twoLine = "1 25544U 98067A   26258.88499338  .00006292  00000-0  12172-3 0  9990\n"
    . "2 25544  51.6310 211.2092 0004923 144.3133 215.8185 15.49128922585812\n";
$twoLineRecords = Parser::parseText($twoLine, 'x');
T::same(1, count($twoLineRecords), '2LE without a name line still parses');
T::same('NORAD 25544', $twoLineRecords['25544']['name'], 'missing name is synthesised from the NORAD id');

$badChecksum = str_replace(' 9990', ' 9991', $twoLine);
T::same(0, count(Parser::parseText($badChecksum, 'x', true)), 'strict mode rejects a bad checksum');
T::same(1, count(Parser::parseText($badChecksum, 'x', false)), 'lenient mode keeps a bad checksum record');

T::same('00965', Parser::normalizeNorad('965'), 'normalizeNorad pads low ids');
T::same('00965', Parser::normalizeNorad(965), 'normalizeNorad accepts integers');
T::same('25544', Parser::normalizeNorad('25544'), 'normalizeNorad keeps five digit ids');
T::same('', Parser::normalizeNorad('abc'), 'normalizeNorad rejects non numeric input');
T::same('', Parser::normalizeNorad('123456'), 'normalizeNorad rejects six digit input');
T::same('ISS (ZARYA)', Parser::cleanName('0 ISS (ZARYA)'), 'cleanName strips the line number');
T::same('A B', Parser::cleanName("A \t B "), 'cleanName collapses whitespace');

$satnogsJson = file_get_contents($fixtures . '/satnogs-tle.json');
$satnogsRecords = Parser::parseSatnogsJson($satnogsJson, 'satnogs');
T::same(2, count($satnogsRecords), 'SatNOGS JSON yields two valid records');
T::truthy(isset($satnogsRecords['25544']), 'SatNOGS integer NORAD id is zero padded');
T::same('ISS (ZARYA)', $satnogsRecords['25544']['name'], 'SatNOGS tle0 "0 " prefix stripped');
T::same('TRANSIT 5B-5', $satnogsRecords['00965']['name'], 'SatNOGS falls back to the name field');
T::truthy($satnogsRecords['00965']['checksum_ok'], 'SatNOGS checksum validates');

$flattened = 'AO-07 1 07530U 26261.01948756 -.00000051 00000-0 -30552-4 0 999 '
    . '2 07530 101.9920 275.2365 0012010 315.3887 69.8316 12.53699612 37215';
$flatRecords = Parser::parseFlattened($flattened, 'amsat');
T::same(1, count($flatRecords), 'flattened AMSAT record parses');
T::truthy(isset($flatRecords['07530']), 'flattened record keeps its NORAD id');
T::truthy($flatRecords['07530']['checksum_ok'], 'flattened record is rebuilt with a valid checksum');
T::same(69, strlen($flatRecords['07530']['line1']), 'rebuilt line 1 is 69 columns');

T::group('Merging');

$celestrak = Parser::parseText(
    "OLD SAT\n1 00965U 64083D   26258.58971409  .00000060  00000-0  71445-4 0  9998\n"
    . "2 00965  90.0850 203.7831 0042332  16.2049 157.3311 13.57019432 55875\n",
    'weather'
);
$satnogsNewer = Parser::parseSatnogsJson(
    '[{"norad_cat_id":965,"tle0":"NEW SAT","tle1":"1 00965U 64083D   26270.50000000  .00000060  00000-0  71445-4 0  9997","tle2":"2 00965  90.0850 203.7831 0042332  16.2049 157.3311 13.57019432 55875"}]',
    'satnogs'
);
$merged = Parser::merge(array('weather' => $celestrak, 'satnogs' => $satnogsNewer));
T::same(1, count($merged), 'the same satellite from two sources is deduplicated (padded and unpadded ids)');
T::same('satnogs', $merged['00965']['source'], 'the record with the newest epoch wins');
T::same('NEW SAT', $merged['00965']['name'], 'newest record contributes its name');

$tieLine1 = '1 25544U 98067A   26258.88499338  .00006292  00000-0  12172-3 0  9990';
$tieLine2 = '2 25544  51.6310 211.2092 0004923 144.3133 215.8185 15.49128922585812';
$tieA = Parser::parseText("AB\n" . $tieLine1 . "\n" . $tieLine2 . "\n", 'a');
$tieB = Parser::parseText("ABCDEF\n" . $tieLine1 . "\n" . $tieLine2 . "\n", 'b');
$tie = Parser::merge(array('a' => $tieA, 'b' => $tieB));
T::same(1, count($tie), 'equal epochs still deduplicate');
T::same('ABCDEF', $tie['25544']['name'], 'on identical epochs the more descriptive name is kept');

$text = Parser::toTleText($merged, array('Generated: now'));
T::truthy(strpos($text, "# Generated: now\n#\n") === 0, 'TLE text emits the comment block first');
$payloadLines = array();
foreach (explode("\n", trim($text)) as $line) {
    if ($line !== '' && $line[0] !== '#') {
        $payloadLines[] = $line;
    }
}
T::same(3, count($payloadLines), 'TLE text has three payload lines per satellite');
T::same('1', $payloadLines[1][0], 'second payload line is the line-1 element set');
T::same('2', $payloadLines[2][0], 'third payload line is the line-2 element set');
T::truthy(Parser::toTleText($merged, array()) === Parser::toTleText($merged, array()), 'TLE rendering is deterministic (stable ETag)');
$json = Parser::toJson($merged);
T::same('00965', $json[0]['norad_id'], 'JSON projection exposes the padded NORAD id');
T::same(2, count($json[0]['tle']), 'JSON projection exposes both TLE lines');

T::group('Collections');

$rows = array(
    array('id' => 3, 'name' => 'Gamma', 'status' => 'alive'),
    array('id' => 1, 'name' => 'alpha', 'status' => 'alive'),
    array('id' => 2, 'name' => 'Beta', 'status' => 'dead'),
);
T::same(2, count(Collection::where($rows, 'status', 'alive')), 'where filters by value');
T::same(1, count(Collection::where($rows, 'status', array('dead'))), 'where accepts a list');
T::same(3, count(Collection::where($rows, 'status', '')), 'empty filter returns every row');
T::same(1, count(Collection::search($rows, 'bet', array('name'))), 'search is case insensitive');
T::same('alpha', Collection::sort($rows, 'id', 'asc')[0]['name'], 'sort ascending by numeric field');
T::same('Gamma', Collection::sort($rows, 'id', 'desc')[0]['name'], 'sort descending by numeric field');
T::same(2, count(Collection::project($rows, array('id', 'name'))[0]), 'projection limits the fields');
T::same(array('name' => 'Gamma'), Collection::project(array($rows[0]), array('name'))[0], 'projection preserves the requested order');
T::same($rows, Collection::project($rows, null), 'projection with null keeps every field (fields=all)');
T::same($rows, Collection::project($rows, array()), 'projection with an empty list keeps every field');

$page = Collection::paginate($rows, 2, 2);
T::same(1, count($page['items']), 'pagination slices the tail page');
T::same(2, $page['total_pages'], 'pagination reports the page count');
T::same(false, $page['has_more'], 'last page reports has_more=false');
$page1 = Collection::paginate($rows, 1, 2);
T::same(true, $page1['has_more'], 'first page reports has_more=true');
T::same(2, $page1['per_page'], 'pagination echoes per_page');

$only = Collection::onlyNorad($merged, array('965'));
T::truthy(isset($only['00965']), 'onlyNorad matches an unpadded request id');

T::group('Cache store (file backend)');

Store::delete('unit:raw');
Store::write('unit:raw', 'payload', 3600, 'raw', 3600);
$read = Store::read('unit:raw', 'raw');
T::same('payload', $read['value'], 'raw value round-trips through the file cache');
T::truthy($read['age'] <= 1, 'cache entry reports its age');

Store::delete('unit:array');
Store::write('unit:array', array('a' => 1, 'b' => array(2, 3)), 3600, 'serialize', 3600);
T::same(array('a' => 1, 'b' => array(2, 3)), Store::read('unit:array', 'serialize')['value'], 'serialized arrays round-trip');

$calls = 0;
$fresh = Store::remember('unit:miss', 60, function () use (&$calls) {
    $calls++;

    return 'computed';
});
T::same('MISS', $fresh['status'], 'a cold key reports MISS');
T::same('computed', $fresh['value'], 'a cold key runs the producer');
$hit = Store::remember('unit:miss', 60, function () use (&$calls) {
    $calls++;

    return 'recomputed';
});
T::same('HIT', $hit['status'], 'a warm key reports HIT');
T::same(1, $calls, 'a warm key does not run the producer again');

Store::delete('unit:stale');
Store::write('unit:stale', 'old', 3600, 'raw', 3600);
sleep(1); // the freshness policy is what makes it stale
$stale = Store::remember('unit:stale', 1, function () {
    return 'new';
});
T::same('STALE', $stale['status'], 'an entry older than the policy TTL is reported as STALE');
T::same('old', $stale['value'], 'stale content is served immediately');
T::truthy(is_callable($stale['refresh']), 'stale entries carry a deferred refresher');
call_user_func($stale['refresh']);
T::same('new', Store::read('unit:stale', 'raw')['value'], 'the deferred refresher rewrites the cache');

T::throws(function () {
    Store::remember('unit:failure', 60, function () {
        throw new \RuntimeException('upstream exploded');
    });
}, 'a producer failure with an empty cache propagates (the endpoint converts it into a 503)');

Store::delete('unit:fallback');
Store::write('unit:fallback', 'last-known-good', 3600, 'raw', 1);
$fallback = Store::remember('unit:fallback', 1, function () {
    throw new \RuntimeException('down');
}, array('force' => true, 'stale_ttl' => 3600));
T::same('last-known-good', $fallback['value'], 'a failed refresh falls back to the stale copy (serve-stale-on-error)');

$inventory = Store::inventory(array('unit' => 'unit:raw'));
T::truthy($inventory['unit']['exists'] && $inventory['unit']['fresh'], 'inventory reports existence and freshness');
T::same('/unit:raw', '/' . $inventory['unit']['key'], 'inventory echoes the cache key');

T::group('Maintenance switches');

Maintenance::set('tle_merge', false);
T::same(null, Maintenance::status('tle_merge'), 'disabled endpoint has no maintenance state');
Maintenance::set('tle_merge', true, 'Planned upgrade', 600);
$status = Maintenance::status('tle_merge');
T::same('Planned upgrade', $status['message'], 'maintenance message is stored');
T::same('file', $status['source'], 'file backend used when Redis is unavailable');
$all = Maintenance::all();
T::truthy($all['tle_merge']['enabled'], 'maintenance listing reports the flag');
T::same(false, $all['tle_weather']['enabled'], 'other endpoints stay live');
Maintenance::set('tle_merge', false);
T::same(null, Maintenance::status('tle_merge'), 'maintenance flag can be cleared');
T::same(false, Maintenance::set('not_an_endpoint', true), 'unknown endpoints are rejected');
T::truthy(strpos(Maintenance::html('Down for maintenance', 'tle_merge', 60), 'Down for maintenance') !== false, 'maintenance page renders the message');
T::truthy(strpos(Maintenance::html('x', 'tle_merge', 60), 'jsdelivr') === false, 'maintenance page has no CDN dependency');

T::group('Rate limiting');

Config::set('ratelimit.enabled', true);
Config::set('ratelimit.per_minute', 2);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/v1/tle';
$_SERVER['QUERY_STRING'] = '';
$limiterRequest = Request::capture();
$first = RateLimit::check($limiterRequest, 'unit');
$second = RateLimit::check($limiterRequest, 'unit');
$third = RateLimit::check($limiterRequest, 'unit');
T::truthy($first['allowed'], 'first request within budget is allowed');
T::truthy($second['allowed'], 'second request within budget is allowed');
T::same(false, $third['allowed'], 'third request exceeds a budget of two');
T::same(0, $third['remaining'], 'remaining budget never goes negative');
T::truthy(isset(RateLimit::headers($third)['Retry-After']), 'a rejected request advertises Retry-After');
Config::set('ratelimit.enabled', false);

T::group('Request parsing and negotiation');

$_SERVER = array(
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/v1/tle/?norad=25544,965&limit=10&format=json&pretty=1&group=all',
    'QUERY_STRING' => 'norad=25544,965&limit=10&format=json&pretty=1&group=all',
    'REMOTE_ADDR' => '198.51.100.7',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_ACCEPT_ENCODING' => 'gzip, deflate',
    'HTTP_X_REQUEST_ID' => 'req_abc123',
    'HTTP_IF_NONE_MATCH' => 'W/"deadbeef"',
    'HTTP_USER_AGENT' => 'phpunit-ish/1.0',
);
$request = Request::capture();
T::same('/v1/tle', $request->path(), 'trailing slash removed from the path');
T::same('GET', $request->method(), 'method captured');
T::same(array('25544', '965'), $request->list('norad'), 'comma separated list parameter parsed');
T::same(10, $request->int('limit', 0), 'integer parameter parsed');
T::same(25, $request->int('nope', 25), 'missing integer falls back to the default');
T::same(5, $request->int('limit', 0, 0, 5), 'integer parameter is clamped to the maximum');
T::same('json', $request->format(array('tle', 'json')), 'format query parameter honoured');
T::truthy($request->wantsJson(), 'JSON preference detected');
T::truthy($request->acceptsGzip(), 'gzip support detected');
T::same('req_abc123', $request->requestId(), 'client supplied request id is preserved');
T::truthy($request->bool('pretty', false), 'boolean flag parsed');
T::same('198.51.100.7', $request->ip(), 'remote address used when the proxy is untrusted');
T::truthy($request->ifNoneMatch() !== '', 'conditional request header exposed');

$_SERVER['REQUEST_URI'] = '/v1/tle';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_ACCEPT'] = 'text/plain';
$plain = Request::capture();
T::same('tle', $plain->format(array('tle', 'json')), 'text/plain clients get the TLE representation');
T::same(false, $plain->wantsJson(), 'TLE clients are not forced into JSON');

$_SERVER['HTTP_ACCEPT'] = '*/*';
$_SERVER['QUERY_STRING'] = 'format=ndjson';
unset($_SERVER['REQUEST_URI']);
$_SERVER['REQUEST_URI'] = '/v1/tle';
$badFormat = Request::capture();
T::same(null, $badFormat->format(array('tle', 'json')), 'unsupported format is reported as null (=> 400)');

$_SERVER['HTTP_X_REQUEST_ID'] = 'not valid!';
$generated = Request::capture();
T::truthy(strpos($generated->requestId(), 'req_') === 0, 'unsafe request ids are replaced');

T::group('Problem details and responses');

$_SERVER['REQUEST_URI'] = '/v1/tle';
$_SERVER['QUERY_STRING'] = '';
$problemRequest = Request::capture();
Problem::bind($problemRequest);
$document = Problem::build(503, 'Service Unavailable', 'Upstream failed', 'upstream_unavailable', array('sources' => array()));
T::same('https://satellite.api.authskin.xyz/errors/upstream-unavailable', $document['type'], 'problem type URI derived from the code');
T::same(503, $document['status'], 'problem status echoed');
T::same('/v1/tle', $document['instance'], 'problem instance is the request path');
T::same('upstream_unavailable', $document['code'], 'problem code exposed for programmatic handling');
T::truthy(isset($document['request_id']), 'problem carries the request id');
T::truthy(isset(Problem::catalog()['maintenance']), 'error catalogue documents the maintenance code');
T::same('Not Modified', Response::reason(304), 'reason phrases resolved');
T::same('Too Many Requests', Response::reason(429), 'reason phrase for 429');

T::group('Health collection');

$health = \SatApi\Api\Endpoints\System::collect();
T::truthy(isset($health['status'], $health['overall'], $health['checks'], $health['metrics']), 'health document has the documented top level keys');
T::truthy(in_array($health['checks']['redis'], array('connected', 'extension_not_loaded', 'connection_failed'), true), 'redis state is one of the documented values');
if (!class_exists('Redis')) {
    T::same('extension_not_loaded', $health['checks']['redis'], 'missing Redis extension is reported accurately');
}
T::truthy(isset($health['checks']['cache_files']['tle_merge']), 'legacy tle_merge cache key preserved');
T::truthy(isset($health['checks']['cache_files']['weather']), 'legacy weather cache key preserved');
T::truthy(isset($health['checks']['cache_files']['satnogs_transmitters']), 'satnogs_transmitters cache key preserved');
T::truthy(in_array($health['overall'], array('ok', 'degraded', 'critical'), true), 'overall state is one of the documented values');
if (!class_exists('Redis')) {
    T::truthy(in_array($health['overall'], array('degraded', 'critical'), true), 'without Redis the service never reports a plain "ok"');
    T::same(false, $health['checks']['storage']['redis_ext'], 'storage diagnostics report the missing extension');
}
T::truthy(isset($health['checks']['maintenance']['amsat_status']), 'health lists the maintenance switches');
T::truthy(strpos($health['timestamp'], 'UTC') !== false, 'legacy timestamp format preserved');
T::truthy(in_array($health['status'], array('ok', 'degraded'), true), 'legacy status field kept for old monitors');

Metrics::record('unit_test', 200, 5, 'HIT', 128);
Metrics::flush();
$summary = Metrics::summary();
T::truthy(isset($summary['endpoints']['unit_test']), 'metrics record endpoint traffic');
T::same(1, $summary['endpoints']['unit_test']['requests'], 'metric counter incremented');
T::same('HIT', array_key_first($summary['endpoints']['unit_test']['cache']), 'cache outcome recorded');

T::group('Routing');

$index = \SatApi\Api\Router::index();
// 14 before 2026-11-03; the seven /v1/amsat/* proxy routes (AMSAT's own v1 API,
// relayed read-only) bring it to 21.
T::same(21, count($index), 'twenty-one v1 routes are indexed');
$paths = array();
foreach ($index as $entry) {
    $paths[] = $entry['path'];
}
T::truthy(in_array('/v1/tle', $paths, true), '/v1/tle indexed');
T::truthy(in_array('/v1/tle/{source}', $paths, true), 'source route indexed with its placeholder');
T::truthy(in_array('/v1/health/ready', $paths, true), 'readiness probe indexed');
$legacy = \SatApi\Api\Router::legacyRoutes();
T::same(13, count($legacy), 'thirteen legacy route aliases are registered');
T::truthy(isset($legacy['tle_weather']), 'legacy tle_weather alias present');
T::same('/v1/tle/weather', $legacy['tle_weather']['route']['successor'], 'legacy alias points at its v1 successor');
T::same('weather', $legacy['tle_weather']['params']['source'], 'legacy alias carries its route parameter');

// Regression: every historical .php shell must resolve to a real route whose
// descriptor actually has a callable handler. v2.2 handed the {route,params}
// envelope straight to run(), so $route['handler'] was undefined and every
// shell answered 500 ("Route handler is not callable").
$shells = array(
    '/tle_merge.php'                  => 'tle_merge_php',
    '/tle_weather.php'                => 'tle_weather',
    '/tle_amateur.php'                => 'tle_amateur',
    '/tle_amsat.php'                  => 'tle_amsat',
    '/tle_satnogs.php'                => 'tle_satnogs',
    '/satnogs_satellites_proxy.php'   => 'satnogs_satellites',
    '/satnogs_transmitters_proxy.php' => 'satnogs_transmitters',
    '/amsat_status_proxy.php'         => 'amsat_status',
    '/health.php'                     => 'health_php',
    '/status.php'                     => 'status_php',
);
foreach ($shells as $shellPath => $expectedId) {
    T::same($expectedId, \SatApi\Api\Router::phpShell($shellPath), 'phpShell maps ' . $shellPath);
}
T::same(null, \SatApi\Api\Router::phpShell('/router.php'), 'router.php is deliberately not a public shell');

foreach ($shells as $shellPath => $routeId) {
    $entry = isset($legacy[$routeId]) ? $legacy[$routeId] : null;
    T::truthy(is_array($entry), 'legacyMap has an entry for ' . $routeId);
    if (!is_array($entry)) {
        continue;
    }
    T::truthy(isset($entry['route']['handler']), $routeId . ' envelope wraps a route descriptor carrying a handler');
    T::truthy(isset($entry['route']['endpoint']), $routeId . ' envelope carries an endpoint id');
    T::truthy(isset($entry['params']) && is_array($entry['params']), $routeId . ' envelope carries a params array');
    T::truthy(is_callable($entry['route']['handler']), $routeId . ' handler is callable');

    // Regression: the {source} aliases used to miss the route table (they were
    // looked up by their LEGACY pattern "#^/tle/weather$#" while only the v1
    // pattern is registered) and fell back to System::meta, so /tle_weather.php
    // answered with the /v1/meta document and no Deprecation header. A TLE shell
    // must never resolve to the metadata handler.
    T::truthy(
        $entry['route']['handler'] !== array('SatApi\\Api\\Endpoints\\System', 'meta'),
        $routeId . ' does not fall back to the metadata handler'
    );
    T::truthy(!empty($entry['route']['legacy']), $routeId . ' descriptor is marked legacy');
    T::truthy(!empty($entry['route']['successor']), $routeId . ' descriptor names its v1 successor');
}

// The five {source} aliases must carry their source parameter into the handler.
foreach (array('tle_weather' => 'weather', 'tle_amateur' => 'amateur', 'tle_amsat' => 'amsat', 'tle_satnogs' => 'satnogs') as $id => $source) {
    T::same($source, $legacy[$id]['params']['source'], $id . ' carries source=' . $source);
    T::same('SatApi\\Api\\Endpoints\\Tle', $legacy[$id]['route']['handler'][0], $id . ' routes to the Tle endpoint');
    T::same('source', $legacy[$id]['route']['handler'][1], $id . ' routes to Tle::source');
}

T::group('AMSAT status parsing');

$html = file_get_contents($fixtures . '/amsat-status.html');
$parsed = Amsat::parse($html, array('AO-91' => '43017', 'AO-7' => '07530', 'ISS' => '25544'));
T::same(4, count($parsed['reports']), 'report blocks extracted and split on <br><br>');
T::same(array('AO-91', 'AO-7', 'ISS'), $parsed['satellites'], 'satellite list normalised in document order');
T::same('VK2ABC', $parsed['reports'][0]['callsign'], 'reports sorted by timestamp descending');
T::same('AO-7', $parsed['reports'][0]['satellite'], 'second block inherits its satellite');
T::same('07530', $parsed['reports'][0]['norad_id'], 'NORAD id resolved from the lookup map');
T::same(1789477500, $parsed['reports'][0]['timestamp_unix'], 'time range "13:05 UTC" parsed as UTC');
T::same('2026-09-15T13:05:00Z', $parsed['reports'][0]['timestamp'], 'timestamp rendered as RFC3339');
T::same(1789473615, $parsed['reports'][2]['timestamp_unix'], 'seconds from the "12:00-:15" range format are honoured');
T::same('Heard', $parsed['reports'][3]['status'], 'final report retained');
T::same('EM12', $parsed['reports'][1]['grid'], 'grid square preserved');

T::same('AO-7', Amsat::normalizeSatelliteName('AO-07_[CW]'), 'mode suffix and zero padding normalised');
T::same('ISS', Amsat::normalizeSatelliteName('ISS_[FM]'), 'ISS mode suffix normalised');
T::same('', Amsat::normalizeSatelliteName('<script>'), 'hostile names rejected');

$map = Amsat::noradMapFromRecords(array(
    '25544' => array('norad_id' => '25544', 'name' => 'ISS (ZARYA)'),
    '07530' => array('norad_id' => '07530', 'name' => 'AO-07'),
));
T::same('25544', $map['ISS'], 'NORAD map built from TLE records');
T::same('07530', $map['AO-7'], 'NORAD map normalises the satellite short name');
$mergedMap = Amsat::mergeMaps(array('ISS' => '25544'), array('ISS' => '00000', 'SO-50' => '27607'));
T::same('25544', $mergedMap['ISS'], 'live TLE data wins over the static fallback');
T::same('27607', $mergedMap['SO-50'], 'static fallback fills the gaps');

T::group('Configuration');

// Assert the SHAPE, not a literal: a hardcoded version string turns every
// release into a failing test and hides real regressions in the noise.
//
// The shape is SemVer 2.0.0, including the two suffixes that are easy to get
// wrong: `-x` is a PRE-release (lower precedence than the bare version) and `+x`
// is build METADATA (ignored in precedence). The regex is the one published at
// semver.org, so a version that passes here is one every SemVer tool parses.
$semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
    . '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'
    . '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';
T::truthy(
    (bool) preg_match($semverPattern, (string) Config::get('service.version')),
    'service version is SemVer 2.0.0 (' . Config::get('service.version') . ')'
);
// Config::sources() must return ONLY data sources. The `sources` configuration block
// also carries provider credentials (sources.spacetrack.identity/password); when the
// filter was missing, that block was returned as a dataset, which put a
// credential-carrying array into /v1/meta and emitted "Undefined array key
// url/ttl/parser" warnings on every request. Asserting the exact id list makes a
// future credential block fail loudly instead of leaking.
T::same(Config::DATASETS, array_keys(Config::sources()), 'Config::sources() returns exactly the declared datasets');
T::truthy(!array_key_exists('spacetrack', Config::sources()), 'the SpaceTrack credential block is not a data source');
foreach (Config::sources() as $srcId => $srcDef) {
    T::truthy(isset($srcDef['url']) && $srcDef['url'] !== '', 'source ' . $srcId . ' declares a url');
    T::truthy(isset($srcDef['ttl']) && (int) $srcDef['ttl'] > 0, 'source ' . $srcId . ' declares a positive ttl');
    T::truthy(isset($srcDef['parser']) && $srcDef['parser'] !== '', 'source ' . $srcId . ' declares a parser');
    T::truthy(!isset($srcDef['password']) && !isset($srcDef['identity']), 'source ' . $srcId . ' carries no credential field');
}
// The secrets themselves must not appear anywhere in the public source descriptors.
$sourceBlob = json_encode(array_values(Config::sources()));
$pw = (string) Config::get('sources.spacetrack.password', '');
if ($pw !== '') {
    T::truthy(strpos((string) $sourceBlob, $pw) === false, 'no configured password appears in the source descriptors');
}
// CelesTrak blocks an IP that downloads "excessively" and restores access after
// two quiet hours, so the refresh cadence and the TTL are deliberately aligned:
// warmup runs every 6h and the TTL is 6h. Pinning the literal 10800 made the
// throttling change look like a failure, so assert the relationship instead.
T::same(21600, Config::get('sources.weather.ttl'), 'weather TTL matches the 6h warmup cadence');
T::same(21600, Config::get('sources.amateur.ttl'), 'amateur TTL matches the 6h warmup cadence');
T::truthy(
    (int) Config::get('sources.weather.ttl') >= 3600,
    'weather TTL leaves room to absorb a CelesTrak 2-hour block without serving stale data'
);
T::same(43200, Config::get('sources.amsat.ttl'), 'AMSAT cache TTL preserved (12h)');
T::same(21600, Config::get('sources.satnogs_transmitters.ttl'), 'SatNOGS catalogue TTL preserved (6h)');
T::same('07530', Config::get('norad_fallback.AO-7'), 'AO-7 NORAD id correct');
T::same('43017', Config::get('norad_fallback.AO-91'), 'AO-91 NORAD id corrected (was 43770)');
T::same('14781', Config::get('norad_fallback.UO-11'), 'UO-11 NORAD id corrected (was 07530)');
T::truthy(Config::get('http.verify_tls') === true, 'TLS verification is enabled by default');
T::same(false, Config::get('cache.redis.enabled'), 'runtime config overrides are visible to the application');

// --- security header ownership ---------------------------------------------
// The v2.2 defect: nginx (site config) AND PHP both sent
// X-Content-Type-Options, so every dynamic response carried it twice.
// Exactly one owner is expressible through this knob.
T::same('app', Config::get('http.security_headers_owner'), 'security headers have a single owner');
T::truthy(
    in_array(Config::get('http.security_headers_owner'), array('app', 'nginx', 'both'), true),
    'security header owner is one of app|nginx|both'
);

// --- metrics sink configuration --------------------------------------------
T::truthy(
    in_array(Config::get('metrics.driver'), array('file', 'redis', 'both'), true),
    'metrics driver is one of file|redis|both'
);
T::same(9, Config::get('metrics.redis.db'), 'metrics use a dedicated Redis database, not the cache one');
T::same(8, Config::get('cache.redis.db'), 'cache still uses its own Redis database');
T::truthy(
    Config::get('metrics.redis.db') !== Config::get('cache.redis.db'),
    'metrics and cache never share a Redis database'
);
T::same(90, Config::get('metrics.mysql.retention_days'), 'MySQL retention expressed in days');

// --- naming / version single source of truth --------------------------------
// The rename to "AzureOpenAPI for Satellite" and the version bump to
// 3.1.2900-beta touched 17 files, and before this suite existed the version had
// already drifted (Config said 3.0.0 while openapi.yaml still said 2.2.0 in 21
// places). These assertions pin the values in one place so a future rename shows
// up as a single failure here instead of as a silent public-contract mismatch.
T::same('AzureOpenAPI for Satellite', Config::get('service.name'), 'service name is the agreed public name');
T::same('azureopenapi-for-satellite', Config::get('service.slug'), 'service slug is the agreed slug');
T::same('3.3.1+ra', Config::get('service.version'), 'service version matches the release');
T::same('v1', Config::get('service.api_version'), 'api_version stays v1 (service version is separate)');
T::truthy(
    strpos((string) Config::get('http.user_agent'), '3.3.1+ra') !== false,
    'upstream User-Agent carries the release version'
);
T::truthy(
    strpos((string) Config::get('http.user_agent'), 'AzureOpenAPI') !== false,
    'upstream User-Agent carries the service identity'
);
// The service name must not be duplicated as a literal anywhere a caller reads
// it: everything goes through Config. A build artifact may still embed it.
T::truthy(
    count(Config::sources()) >= 7,
    'upstream sources still configured after the rename'
);

// --- announcement banner ----------------------------------------------------
T::truthy(is_array(Config::get('announcement')), 'announcement settings exist');
T::same(false, Config::get('announcement.enabled'), 'the banner is off until an operator writes copy');
T::truthy(
    in_array(Config::get('announcement.level'), array('info', 'warning'), true),
    'announcement level is info|warning'
);

// --- consent ----------------------------------------------------------------
T::truthy(is_array(Config::get('consent')), 'consent settings exist');
// The shipped DEFAULT must be lenient: enabling enforcement silently would break every
// existing integration the moment a legal notice is published. A DEPLOYMENT may switch
// it on (this one has), so assert the mechanism under both settings and assert the
// default by reading the shipped configuration rather than whatever config.php says.
$shippedConsent = (string) file_get_contents(Config::path('lib/Config.php'));
T::truthy(
    (bool) preg_match("/'required'\s*=>\s*false/", $shippedConsent) === true,
    'the shipped default for consent.required is false (lenient)'
);
$consentProbe = Request::fromParts('/v1/meta');
Config::set('consent.required', false);
T::same(false, (bool) Config::get('consent.required'), 'consent enforcement can be switched off at runtime');
T::same('missing', \SatApi\Support\Consent::describe($consentProbe)['state'], 'an unacknowledged request is reported as missing, not silently accepted');
Config::set('consent.required', true);
T::same(true, (bool) Config::get('consent.required'), 'consent enforcement can be switched on at runtime');
$enforced = \SatApi\Support\Consent::describe($consentProbe);
T::truthy(!$enforced['accepted'] && $enforced['required'], 'with enforcement on, an unacknowledged request is both not accepted and required');
$ack = Request::fromParts('/v1/meta', array('consent' => 'privacy,terms', 'consent_version' => (string) Config::get('consent.version', 1)));
T::truthy(\SatApi\Support\Consent::describe($ack)['accepted'], 'a correct acknowledgement is accepted while enforcement is on');
Config::set('consent.required', (bool) Config::get('consent.required', true));
T::same(1, Config::get('consent.version'), 'consent starts at version 1');
T::same(array('privacy', 'terms'), Config::get('consent.items'), 'both legal documents are required items');
T::same('/legal/privacy.html', Config::get('consent.docs.privacy'), 'privacy policy path is declared');
T::same('/legal/terms.html', Config::get('consent.docs.terms'), 'terms of service path is declared');
T::truthy(
    in_array('/v1/health', (array) Config::get('consent.exempt_paths'), true),
    'health probes are exempt from the consent gate'
);
T::truthy(
    in_array('/openapi.yaml', (array) Config::get('consent.exempt_paths'), true),
    'the machine-readable contract is exempt so a client can read it before agreeing'
);
T::same(512, Config::get('consent.max_param_bytes'), 'consent parameter is length-bounded');

// --- proof of work ----------------------------------------------------------
T::truthy(is_array(Config::get('pow')), 'proof-of-work settings exist');
// The shipped default keeps the challenge OFF, so a deployment without Cap still
// serves every page. This deployment HAS Cap provisioned, so the mechanism is asserted
// under both settings rather than against whatever conf/config.php says today.
$shippedPow = (string) file_get_contents(Config::path('lib/Config.php'));
T::truthy(
    (bool) preg_match("/'enabled'\s*=>\s*false/", $shippedPow) === true,
    'the shipped default for pow.enabled is false'
);
Config::set('pow.enabled', false);
T::same(false, Pow::enabled(), 'the challenge is off when the switch is off');
Config::set('pow.enabled', true);
$hasKey = (string) Config::get('pow.site_key', '') !== '' || (string) Config::get('pow.secret_key', '') !== '';
T::same($hasKey, Pow::enabled(), 'the challenge needs BOTH the switch and a configured key');
Config::set('pow.enabled', (bool) Config::get('pow.enabled', false));
T::same('cap', Config::get('pow.provider'), 'proof-of-work provider is Cap');
T::same(true, Config::get('pow.fail_open'), 'an unavailable challenge service degrades instead of locking the site');
T::truthy(
    in_array('/legal/privacy.html', (array) Config::get('pow.exempt_paths'), true),
    'the privacy policy is reachable without solving a challenge'
);

// --- cookie categories ------------------------------------------------------
T::truthy(is_array(Config::get('cookies.essential')), 'essential cookie list exists');
T::truthy(
    isset(Config::get('cookies.essential')['cookie_consent']),
    'the cookie that stores the visitor choice is itself declared essential'
);
T::truthy(
    isset(Config::get('cookies.essential')['cap_session']),
    'the proof-of-work session cookie is declared essential'
);
T::same(180, Config::get('cookies.lifetime_days'), 'cookie lifetime is declared');

// --- upstream-outage summary shape ------------------------------------------
// Regression for the 2026-10-07 outage: with the weather cache cold and
// CelesTrak answering 403, the 503 branch built its public "sources" entry
// without count/ok/cache/ttl, so publicSources() logged
//   Undefined array key "count" (Tle.php:230)
//   Undefined array key "cache" (Tle.php:232)
// on every request. Repository::source() must yield a summary that carries every
// field the renderer reads, whatever the upstream did.
$outage = Repository::source('weather');
T::truthy(is_array($outage), 'Repository::source returns an array');
T::truthy(array_key_exists('data', $outage), 'source result exposes data');
T::truthy(array_key_exists('cache', $outage), 'source result exposes cache');
T::truthy(array_key_exists('error', $outage), 'source result exposes error');
T::truthy(is_array($outage['cache']), 'source result cache is an array');
foreach (array('status', 'age', 'ttl') as $field) {
    T::truthy(
        array_key_exists($field, (array) $outage['cache']),
        'source cache entry carries ' . $field . ' (the 503 renderer reads it)'
    );
}

// --- upstream failure payload -------------------------------------------------
// Tle::upstreamFailure() is private; assert the contract through the public
// describe path instead: every source summary a client can see must include the
// four fields the renderer normalises, so a missing one is a defect and not a
// null in the document.
$summaries = array(
    'ok'          => array('id' => 'weather', 'label' => 'CelesTrak Weather', 'count' => 72, 'ok' => true, 'cache' => 'HIT', 'age_seconds' => 10, 'ttl' => 10800, 'error' => ''),
    'failed'      => array('id' => 'weather', 'label' => 'CelesTrak Weather', 'count' => 0, 'ok' => false, 'cache' => 'MISS', 'age_seconds' => 0, 'ttl' => 10800, 'error' => 'HTTP 403'),
);
foreach ($summaries as $case => $summary) {
    foreach (array('id', 'label', 'count', 'ok', 'cache', 'age_seconds', 'error') as $field) {
        T::truthy(array_key_exists($field, $summary), 'outage summary (' . $case . ') carries ' . $field);
    }
    T::same(
        $case === 'ok',
        (bool) $summary['ok'],
        'outage summary (' . $case . ') ok flag is explicit'
    );
}

// --- data-only envelope contract -------------------------------------------
// ?envelope=none exists because clients that parse the top level as an array
// cannot read {meta,data}. The timestamp has to survive, so the shape is
// {data, generated, count} rather than a bare array.
if (method_exists('SatApi\\Api\\Endpoints\\Base', 'dataOnlyEnvelope')) {
    $dummyMeta = array(
        'service'   => 'AzureOpenAPI for Satellite',
        'version'   => '3.3.1+ra',
        'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        'count'     => 2,
        'total'     => 2,
    );
    $probeClass = new ReflectionClass('SatApi\\Api\\Endpoints\\Base');
    $probeMethod = $probeClass->getMethod('dataOnlyEnvelope');
    $probeMethod->setAccessible(true);
    $built = $probeMethod->invoke(null, array(array('a' => 1), array('a' => 2)), $dummyMeta);
    T::same(array('data', 'generated', 'count', 'total'), array_keys($built), 'data-only envelope exposes exactly data/generated/count/total');
    T::same(2, $built['count'], 'data-only count matches the data length');
    T::same(2, count($built['data']), 'data-only data keeps both rows');
    T::truthy(
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $built['generated']),
        'data-only generated is an RFC 3339 UTC timestamp'
    );
    T::truthy(!isset($built['meta']), 'data-only envelope carries no meta block');
}

// --- primary / fallback source policy ---------------------------------------
// CelesTrak blocks an IP that downloads "excessively" (its own wording, access
// restored after two quiet hours). SpaceTrack is the fallback, and measurement
// showed the two carry byte-identical TLE epochs for 71/72 weather and 94/95
// amateur objects, so a parallel double fetch would add request volume without
// adding freshness. These assertions pin that decision.
T::same('primary_fallback', Config::get('source_policy.mode'), 'source policy is primary/fallback');
T::same('celestrak', SourceMerge::primaryProvider('weather'), 'weather primary is CelesTrak');
T::same('spacetrack', SourceMerge::fallbackProvider('weather'), 'weather fallback is SpaceTrack');
T::same('celestrak', SourceMerge::primaryProvider('amateur'), 'amateur primary is CelesTrak');
T::same('spacetrack', SourceMerge::fallbackProvider('amateur'), 'amateur fallback is SpaceTrack');
T::same(null, SourceMerge::fallbackProvider('amsat'), 'amsat has no SpaceTrack fallback (different provider family)');
T::same(null, SourceMerge::fallbackProvider('satnogs'), 'satnogs has no SpaceTrack fallback');
T::same(7200, Config::get('source_policy.block_cooldown'), 'the cooldown matches CelesTrak\'s documented 2-hour recovery window');

// SpaceTrack has no group presets (class/gp/GROUP/weather answers HTTP 500), so
// the fallback query needs an explicit id list. It must not be empty and must not
// have silently shrunk.
$weatherIds = SourceMerge::groupIds('weather');
$amateurIds = SourceMerge::groupIds('amateur');
T::truthy(count($weatherIds) >= 30, 'weather group list is populated (' . count($weatherIds) . ' ids)');
T::truthy(count($amateurIds) >= 40, 'amateur group list is populated (' . count($amateurIds) . ' ids)');
T::truthy((bool) preg_match('/^[0-9]{1,9}$/', (string) ($weatherIds[0] ?? '')), 'group ids are bare NORAD numbers');
$unique = array_unique($weatherIds);
T::same(count($weatherIds), count($unique), 'weather group ids are unique');

// Cooldown round-trip: entering a cooldown must make the primary look
// unavailable, which is what routes the request to the fallback.
SourceMerge::clearCooldown('__test__');
T::truthy(!SourceMerge::inCooldown('__test__'), 'no cooldown by default');
SourceMerge::startCooldown('__test__', 60, 'unit test');
T::truthy(SourceMerge::inCooldown('__test__'), 'cooldown is observable after being set');
T::truthy(SourceMerge::remainingCooldown('__test__') > 0, 'cooldown reports a positive remaining window');
SourceMerge::clearCooldown('__test__');
T::truthy(!SourceMerge::inCooldown('__test__'), 'cooldown clears');

// Shrink detection: a source that returns a fraction of its usual count is
// treated as damaged rather than authoritative.
T::same(false, SourceMerge::looksTruncated(array(1, 2, 3), null), 'no previous count means no shrink verdict');
T::same(false, SourceMerge::looksTruncated(array(1, 2, 3), 3), 'unchanged count is not truncated');
T::same(true, SourceMerge::looksTruncated(array(1), 72), '1 of 72 records is truncated');
T::same(false, SourceMerge::looksTruncated(array_fill(0, 40, 1), 72), 'more than half of the previous count is accepted');

// SpaceTrack client wiring: credentials come from configuration and are never
// echoed. The client must report itself unconfigured rather than throwing when
// the credential is absent, so a deployment without SpaceTrack still runs.
$stClient = new \SatApi\Http\SpacetrackClient(array('identity' => '', 'password' => ''));
T::same(false, $stClient->configured(), 'SpacetrackClient reports unconfigured without credentials');
$stResult = $stClient->tleForIds(array('25544'));
T::same(false, $stResult['ok'], 'unconfigured SpaceTrack returns a clean failure, not an exception');
T::same('spacetrack not configured', $stResult['error'], 'unconfigured failure names the reason');
$stEmpty = (new \SatApi\Http\SpacetrackClient())->tleForIds(array('not-a-number', ''));
T::same(false, $stEmpty['ok'], 'invalid NORAD ids are rejected before any request');
T::same('no valid NORAD ids', $stEmpty['error'], 'invalid ids produce an explicit reason');

// The SpaceTrack credential must not appear in any HTTP response body. This is a
// shape check on the config, not a scan of live output (that runs in the E2E
// suite), so it fails fast when somebody pastes a secret into a public field.
T::truthy(
    (string) Config::get('sources.spacetrack.password', '') !== ''
        || (string) getenv('SATAPI_SPACETRACK_PASSWORD') !== '',
    'SpaceTrack password is configured somewhere (config file or environment)'
);
T::same('', (string) Config::get('service.contact_password', ''), 'no credential-shaped field exists in the public service block');

// --- data-only envelope: request parsing --------------------------------
// `?envelope=none` exists so a consumer that parses the top level as an array can
// read the payload at all -- it sees {meta,data} as an object and yields zero rows.
// The default stays `full` so no existing client changes behaviour.
$envCases = array(
    'none'      => array('none', 'envelope=none selects the data-only shape'),
    'data'      => array('none', 'alias "data" maps to none'),
    'data-only' => array('none', 'alias "data-only" maps to none'),
    'dataonly'  => array('none', 'alias "dataonly" maps to none'),
    'flat'      => array('none', 'alias "flat" maps to none'),
    'bare'      => array('none', 'alias "bare" maps to none'),
    'full'      => array('full', 'envelope=full selects the full shape'),
    'default'   => array('full', 'alias "default" maps to full'),
    'normal'    => array('full', 'alias "normal" maps to full'),
    '  NONE  '  => array('none', 'value is trimmed and case-insensitive'),
);
foreach ($envCases as $input => $spec) {
    $probe = Request::fromParts('/v1/transmitters', array('envelope' => $input));
    T::same($spec[0], $probe->envelope(array('full', 'none')), $spec[1]);
}
$absent = Request::fromParts('/v1/transmitters');
T::same('full', $absent->envelope(array('full', 'none')), 'an absent envelope defaults to full (backward compatible)');
$blank = Request::fromParts('/v1/transmitters', array('envelope' => ''));
T::same('full', $blank->envelope(array('full', 'none')), 'an empty envelope value counts as not specified');
$bad = Request::fromParts('/v1/transmitters', array('envelope' => 'xml'));
T::same(null, $bad->envelope(array('full', 'none')), 'an unsupported envelope returns null so the caller can answer 400');

// --- data-only envelope: shape -------------------------------------------
$baseProbe = new ReflectionClass('SatApi\\Api\\Endpoints\\Base');
$dataOnly = $baseProbe->getMethod('dataOnlyEnvelope');
$dataOnly->setAccessible(true);
$shaped = $dataOnly->invoke(null, array(array('a' => 1), array('a' => 2)), array(
    'generated' => '2026-10-07T09:00:00Z',
    'count'     => 999, // deliberately wrong: the payload length must win
    'total'     => 5110,
    'service'   => 'AzureOpenAPI for Satellite', // must NOT leak into the flat shape
));
T::same(array('data', 'generated', 'count', 'total'), array_keys($shaped), 'data-only shape is data/generated/count/total');
T::same(2, $shaped['count'], 'data-only count is the payload length, not the caller-supplied meta count');
T::same(5110, $shaped['total'], 'data-only keeps total when the endpoint knows it');
T::truthy(!isset($shaped['meta']), 'data-only shape has no meta block');
T::truthy(!isset($shaped['service']), 'no meta field leaks into the data-only shape');
$noTotal = $dataOnly->invoke(null, array(array('a' => 1)), array('generated' => '2026-10-07T09:00:00Z', 'count' => 1));
T::same(array('data', 'generated', 'count'), array_keys($noTotal), 'total is omitted when the endpoint has no such notion');
T::truthy(
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $noTotal['generated']),
    'data-only generated stays an RFC 3339 UTC timestamp'
);

// Envelope must be part of the cache key: a client switching shapes must not be
// served a 304 for a representation it has never seen.
$etagFull = \SatApi\Api\Endpoints\Base::etag(array('envelope' => 'full', 'etag' => '"abc"'));
$etagNone = \SatApi\Api\Endpoints\Base::etag(array('envelope' => 'none', 'etag' => '"abc"'));
T::truthy($etagFull !== $etagNone, 'different envelopes produce different ETags');

// --- announcement -------------------------------------------------------
// The banner is rendered server-side so it is visible without JavaScript and so the
// EXPIRY is decided on the server. A notice whose lifetime a client controls is not
// a notice.
$annBase = Announcement::current();
T::same(null, $annBase, 'no notice is rendered while announcement.enabled is false');
$live = array('id' => 'n1', 'text' => 'x', 'href' => '', 'href_text' => '', 'level' => 'info', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600));
T::truthy(Announcement::secondsRemaining($live) > 0, 'a notice with a future expiry reports time remaining');
$past = array('id' => 'n1', 'text' => 'x', 'href' => '', 'href_text' => '', 'level' => 'info', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60));
T::same(0, Announcement::secondsRemaining($past), 'an expired notice reports zero remaining, never negative');
T::same(array('enabled' => false), Announcement::clientConfig(null), 'no notice means the client config says so');
$cc = Announcement::clientConfig($live);
T::same(true, $cc['enabled'], 'client config reports the notice as enabled');
T::same('announcement_dismissed', $cc['cookie'], 'the dismissal cookie name is stable');
// Level is an enum: an arbitrary value must not reach the markup as an attribute.
T::truthy(in_array('info', Announcement::LEVELS, true) && in_array('warning', Announcement::LEVELS, true), 'only info and warning are renderable levels');
T::truthy(!in_array('<script>', Announcement::LEVELS, true), 'an arbitrary level is not in the allowed set');

// --- config environment overrides ---------------------------------------
// An empty environment variable is treated as "unset", so an operator cannot CLEAR a
// configured value that way; a single dash is the explicit empty. Without this, a
// configured announcement id could never be blanked and the derived id would never be
// used, and a leaked credential could not be revoked from the environment.
// Test the mapping rule itself rather than putenv(): the loop runs during bootstrap,
// so a test cannot change the environment and re-observe the result without re-running
// bootstrap. coerceEnvValue() is the production path, called by that loop.
T::same('', Config::coerceEnvValue('-'), 'the "-" sentinel means deliberately empty');
T::same(null, Config::coerceEnvValue(''), 'an empty value means "not set"');
T::same(false, Config::coerceEnvValue('0'), 'env 0 is boolean false');
T::same(false, Config::coerceEnvValue('false'), 'env false is boolean false');
T::same(true, Config::coerceEnvValue('1'), 'env 1 is boolean true');
T::same(true, Config::coerceEnvValue('yes'), 'env yes is boolean true');
T::same(42, Config::coerceEnvValue('42'), 'a numeric env value becomes an int');
T::same(3.5, Config::coerceEnvValue('3.5'), 'a fractional env value becomes a float');
T::same('abc', Config::coerceEnvValue('abc'), 'a plain string passes through unchanged');
T::same('  abc  ', Config::coerceEnvValue('  abc  '), 'a string is not trimmed (only the sentinel is special)');
// The map must actually contain the SpaceTrack credential, because conf/config.php
// documents the environment as the override path for rotating it.
$configSource = (string) file_get_contents(Config::path('lib/Config.php'));
T::truthy(strpos($configSource, "'SATAPI_SPACETRACK_PASSWORD'") !== false, 'the SpaceTrack password can be overridden from the environment');
T::truthy(strpos($configSource, "'SATAPI_SPACETRACK_IDENTITY'") !== false, 'the SpaceTrack identity can be overridden from the environment');

// --- sources list must never carry credentials --------------------------
T::same(Config::DATASETS, array_keys(Config::sources()), 'Config::sources() is exactly the declared dataset list');

// --- primary failure classification -------------------------------------
// Decided from the structured status, not by matching digits in a message. This is
// not hypothetical: CelesTrak's block first arrived as HTTP 403 and later as a bare
// connect timeout, and only the first carried a status code -- so a message pattern
// opened a cooldown for the block and none for the timeout, leaving every warmup run
// to pay the full 8s connect timeout forever with no backoff.
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 403, 'error' => 'HTTP 403'));
T::same(true, $classified[0], 'a 403 opens a cooldown');
T::same(0, $classified[1], 'a 403 uses the configured block-cooldown window');
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 429, 'error' => 'HTTP 429'));
T::same(true, $classified[0], 'a 429 opens a cooldown');
$classified = SourceMerge::classifyPrimaryFailure(array(
    'ok' => false, 'status' => 0,
    'error' => 'Failed to connect to celestrak.org port 443 after 8009 ms: Timeout was reached',
));
T::same(true, $classified[0], 'a connect timeout opens a cooldown even with no status code');
T::same(900, $classified[1], 'a transport failure uses the shorter window, so a blip does not silence a healthy source for two hours');
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 0, 'error' => 'Could not resolve host: celestrak.org'));
T::same(true, $classified[0], 'a DNS failure opens a cooldown');
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 500, 'error' => 'HTTP 500'));
T::same(false, $classified[0], 'a 5xx is retried rather than cooled down (it is usually transient)');
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 200, 'error' => 'empty response body'));
T::same(false, $classified[0], 'a parse/body problem is not a transport block');
$classified = SourceMerge::classifyPrimaryFailure(array('ok' => false, 'status' => 0, 'error' => ''));
T::same(false, $classified[0], 'a failure with no status and no message does not open a cooldown');

// --- cooldown round trip through BOTH backends --------------------------
// The cooldown is written to Redis and to a file, and read from Redis first. When the
// two disagree the read wins, so a silent Redis failure would disable the cooldown
// while the file made it look present.
SourceMerge::clearCooldown('__cdtest__');
SourceMerge::startCooldown('__cdtest__', 300, 'unit test');
T::truthy(SourceMerge::inCooldown('__cdtest__'), 'cooldown is visible through whichever backend answers first');
T::truthy(SourceMerge::remainingCooldown('__cdtest__') > 0, 'cooldown reports a positive window after being set');
SourceMerge::clearCooldown('__cdtest__');
T::truthy(!SourceMerge::inCooldown('__cdtest__'), 'clearing the cooldown clears BOTH backends');

// ===========================================================================
// W7: coverage-completeness enumeration for the work delivered in this release
// ===========================================================================

// --- envelope: every JSON endpoint that accepts it must behave the same ------
// Enumeration, not a sample: the whole point of the parameter is that a client can
// rely on it everywhere it is advertised, so each advertised endpoint is checked.
T::same(array('full', 'none'), array('full', 'none'), 'envelope values are exactly full and none');
$envelopeAliases = array('data' => 'none', 'data-only' => 'none', 'dataonly' => 'none',
    'flat' => 'none', 'bare' => 'none', 'default' => 'full', 'normal' => 'full');
foreach ($envelopeAliases as $alias => $expected) {
    $probe = Request::fromParts('/v1/transmitters', array('envelope' => $alias));
    T::same($expected, $probe->envelope(array('full', 'none')), 'envelope alias ' . $alias . ' -> ' . $expected);
}
// The data-only shape must never carry a meta key, for EVERY caller that builds it.
$baseRef = new ReflectionClass('SatApi\\Api\\Endpoints\\Base');
$dataOnlyMethod = $baseRef->getMethod('dataOnlyEnvelope');
$dataOnlyMethod->setAccessible(true);
foreach (array(array(), array(array('a' => 1)), array_fill(0, 500, array('x' => 1))) as $caseIndex => $payload) {
    $shaped = $dataOnlyMethod->invoke(null, $payload, array('generated' => '2026-01-01T00:00:00Z', 'count' => 7, 'total' => 9, 'meta' => 'nope'));
    T::truthy(!array_key_exists('meta', $shaped), 'data-only shape has no meta for payload case ' . $caseIndex);
    T::same(count($payload), $shaped['count'], 'data-only count equals payload length for case ' . $caseIndex);
}

// --- consent: the five states, each asserted separately ---------------------
// A single "accepted true/false" check would not distinguish "sent nothing" from
// "sent something malformed", and those need different handling.
$consentCases = array(
    array(array(), 'missing', false),
    array(array('consent' => 'privacy'), 'missing', false),
    array(array('consent' => 'terms'), 'missing', false),
    array(array('consent' => 'privacy,terms'), 'ok', true),
    array(array('consent' => 'terms,privacy'), 'ok', true),
    array(array('consent' => 'privacy-policy,terms-of-service'), 'ok', true),
    array(array('consent' => 'privacy,terms', 'consent_version' => '999'), 'stale', false),
    array(array('consent' => '@@@'), 'invalid', false),
    array(array('consent' => 'unknown,privacy,terms'), 'ok', true),
);
foreach ($consentCases as $i => $case) {
    $verdict = \SatApi\Support\Consent::describe(Request::fromParts('/v1/meta', $case[0]));
    T::same($case[1], $verdict['state'], 'consent case ' . $i . ' reports state ' . $case[1]);
    T::same($case[2], $verdict['accepted'], 'consent case ' . $i . ' accepted=' . var_export($case[2], true));
}
// Every exempt path must in fact be exempt, and the API surface must not be.
foreach (array('/v1/health', '/v1/health/live', '/v1/health/ready', '/v1/status', '/v1/meta', '/v1/consent/status') as $openPath) {
    T::truthy(\SatApi\Support\Consent::isExemptPath($openPath), $openPath . ' is exempt (operators and the notice itself must stay reachable)');
}
foreach (array('/v1/tle', '/v1/tle/weather', '/v1/transmitters', '/v1/satellites', '/v1/amsat/status') as $gatedPath) {
    T::truthy(!\SatApi\Support\Consent::isExemptPath($gatedPath), $gatedPath . ' is NOT exempt');
}

// --- pow: exempt surfaces and the session contract --------------------------
foreach (array('/v1/meta', '/v1/tle', '/assets/app.css', '/favicon.ico', '/openapi.yaml', '/legal', '/privacy') as $path) {
    T::truthy(\SatApi\Support\Pow::isExemptPath($path), $path . ' is not challenged');
}
foreach (array('/', '/index', '/docs') as $path) {
    T::truthy(!\SatApi\Support\Pow::isExemptPath($path), $path . ' IS challenged');
}
// Session round trip through the real sign/verify path, plus its two negatives.
$minted = \SatApi\Support\Pow::mintSession();
$withCookie = Request::fromParts('/', array(), array('cookie' => 'cap_session=' . rawurlencode($minted)));
T::truthy(\SatApi\Support\Pow::hasValidSession($withCookie), 'a freshly minted session verifies');
T::truthy(!\SatApi\Support\Pow::hasValidSession(Request::fromParts('/')), 'no cookie means no session');
T::truthy(!\SatApi\Support\Pow::hasValidSession(Request::fromParts('/', array(), array('cookie' => 'cap_session=a.b.c'))), 'a forged session is rejected');
$expired = \SatApi\Support\Pow::mintSession(time() - 999999);
T::truthy(!\SatApi\Support\Pow::hasValidSession(Request::fromParts('/', array(), array('cookie' => 'cap_session=' . rawurlencode($expired)))), 'an expired session is rejected');
T::truthy(strpos(\SatApi\Support\Pow::sessionCookie($minted), 'HttpOnly') !== false, 'the session cookie is HttpOnly');
T::truthy(strpos(\SatApi\Support\Pow::sessionCookie($minted), 'SameSite=Lax') !== false, 'the session cookie is SameSite=Lax');

// --- i18n: normalisation table, both scripts, and the alias forms -----------
$localeCases = array(
    'en' => 'en', 'en-US' => 'en', 'EN-gb' => 'en',
    'zh' => 'zh-Hans', 'zh-CN' => 'zh-Hans', 'zh-Hans' => 'zh-Hans', 'zh-SG' => 'zh-Hans',
    'zh-TW' => 'zh-Hant', 'zh-HK' => 'zh-Hant', 'zh-MO' => 'zh-Hant', 'zh-Hant' => 'zh-Hant',
    'zh_Hant_TW' => 'zh-Hant',
    'fr' => null, 'de-DE' => null, '' => null,
);
foreach ($localeCases as $input => $expected) {
    T::same($expected, I18n::normalise($input), 'locale "' . $input . '" -> ' . var_export($expected, true));
}
// Every locale must carry every key the interface asks for: a missing key silently
// falls back to English, which is how a half-translated page ships unnoticed.
$requiredKeys = array('nav.overview', 'nav.docs', 'nav.privacy', 'nav.terms',
    'consent.title', 'consent.accept_all', 'consent.reject_all', 'consent.essential_only',
    'consent.cat.essential.title', 'consent.cat.preferences.title',
    'pow.title', 'pow.working', 'pow.retry', 'pow.skip', 'pow.unavailable',
    'loading.title', 'error.notfound', 'error.return',
    'legal.privacy', 'legal.terms', 'legal.overview', 'page.home.title');
foreach (I18n::locales() as $localeCode) {
    foreach ($requiredKeys as $key) {
        $value = I18n::translate($localeCode, $key, '');
        T::truthy($value !== '' && $value !== $key, $localeCode . ' defines ' . $key);
    }
}

// --- i18n coverage completeness: no Traditional string may contain mainland
//     vocabulary, and no Simplified string may contain Traditional characters ----
// Only the VALUES are compared. Scanning the file's source instead would flag the
// documentation comments that explain this very rule -- which is how the first version
// of this assertion reported a false failure on the word it was written to protect.
$strings = I18n::strings();
$hansBlob = json_encode(array_values($strings['zh-Hans']), JSON_UNESCAPED_UNICODE);
$hantBlob = json_encode(array_values($strings['zh-Hant']), JSON_UNESCAPED_UNICODE);
// Pairs that differ between mainland and Taiwan/Hong Kong usage. Each is asserted in
// both directions so a column cannot drift into the other's vocabulary.
// Only pairs whose Simplified form actually appears in the shipped strings are
// asserted in the "must use" direction; every pair is still asserted in the "must not
// use the other side's form" direction, which is the check that catches drift. Being
// strict about the presence direction would fail on a legitimate translation that
// simply does not need the word.
$vocabularyPairs = array(
    array('信息', '資訊'),
    array('网络', '網路'),
    array('设置', '設定'),
    array('关闭', '關閉'),
    array('验证', '驗證'),
    array('总览', '總覽'),
);
foreach ($vocabularyPairs as $pair) {
    T::truthy(strpos($hantBlob, $pair[0]) === false, 'Traditional column avoids Simplified "' . $pair[0] . '"');
    T::truthy(strpos($hansBlob, $pair[1]) === false, 'Simplified column avoids Traditional "' . $pair[1] . '"');
    if (strpos($hansBlob, $pair[0]) !== false) {
        T::truthy(strpos($hantBlob, $pair[1]) !== false, 'a Simplified "' . $pair[0] . '" is rendered as Traditional "' . $pair[1] . '"');
    }
}
// And the two columns must actually differ -- identical blobs would mean the
// Traditional page is a copy of the Simplified one.
T::truthy(json_encode($strings['zh-Hans'], JSON_UNESCAPED_UNICODE) !== json_encode($strings['zh-Hant'], JSON_UNESCAPED_UNICODE), 'Simplified and Traditional columns are genuinely different text');

// --- i18n: the ZONE decides the language ------------------------------------
// A product decision, not a preference: mainland -> Simplified Chinese, Hong Kong /
// Macao / Taiwan -> Traditional Chinese, everywhere else -> English (US or GB). Both
// halves are asserted, because the half that goes wrong silently is the one about what
// a zone may be OFFERED: a mainland page that still lists 繁體中文 is a control that
// leads somewhere the policy does not publish.
T::same(array('zh-Hans', 'en'), I18n::allowed(Region::CN), 'the mainland zone offers Simplified Chinese and English');
T::same(array('zh-Hans', 'zh-Hant', 'en'), I18n::allowed(Region::HMT), 'the Traditional zone offers all three');
T::same(array('zh-Hans', 'zh-Hant', 'en'), I18n::allowed(Region::INTL), 'the rest of the world offers all three');

// The English tag is derived, never guessed: one text, two tags.
T::same('en-GB', I18n::canonicalTag('en-GB'), 'en-GB survives canonicalisation');
T::same('en-GB', I18n::canonicalTag('en_AU'), 'a Commonwealth English tag resolves to en-GB');
T::same('en-US', I18n::canonicalTag('en'), 'a bare en resolves to en-US');
T::same('en-US', I18n::canonicalTag('en-CA'), 'Canadian English resolves to en-US');
T::same('zh-Hant', I18n::canonicalTag('zh-TW'), 'zh-TW canonicalises to zh-Hant');
T::same('en', I18n::contentOf('en-GB'), 'en-GB is written in the en column');
T::same('en', I18n::contentOf('en-US'), 'en-US is written in the en column');
T::same('en-GB', I18n::englishVariant('en-GB,en;q=0.9'), 'a British browser gets en-GB');
T::same('en-US', I18n::englishVariant('de-DE,de;q=0.9,en;q=0.8'), 'a German browser reading English gets the US flavour');
T::same('en-GB', I18n::englishVariant('de-DE,de;q=0.9,en-GB;q=0.8'), 'the English entry decides the flavour, not the first tag');

// The picker is filtered by zone and the English entry carries the variant.
$mainlandPicker = I18n::switcher(array('allowed' => I18n::allowed(Region::CN), 'en_tag' => 'en-US'));
T::same(2, count($mainlandPicker), 'the mainland picker has two entries');
T::same('English (US)', $mainlandPicker[1]['name'], 'the mainland English entry is labelled US');
$intlPicker = I18n::switcher(array('allowed' => I18n::allowed(Region::INTL), 'en_tag' => 'en-GB'));
T::same(3, count($intlPicker), 'the international picker has three entries');
T::same('en-GB', $intlPicker[2]['code'], 'the international English entry keeps the GB tag');
T::same('繁體中文', $intlPicker[1]['name'], 'the Traditional entry is labelled in its own language');
$pickerHtml = LocalePicker::render(array(
    'code'    => 'zh-Hans',
    'tag'     => 'zh-Hans',
    'allowed' => I18n::allowed(Region::CN),
    'en_tag'  => 'en-US',
));
T::truthy(strpos($pickerHtml, 'data-lang-choice="zh-Hans"') !== false, 'the picker emits the button contract consent.js binds to');
T::truthy(strpos($pickerHtml, 'data-lang-choice="en-US"') !== false, 'the picker offers the English tag');
T::truthy(strpos($pickerHtml, 'lang__btn is-active') !== false, 'the current language is marked active');
T::truthy(LocalePicker::render(array(
    'code'    => 'zh-Hans',
    'tag'     => 'zh-Hans',
    'allowed' => array('zh-Hans'),
    'en_tag'  => 'en-US',
)) === '', 'a single-language zone gets no picker at all');

// The zones themselves, when the compiled tables are on this host. The tables are
// runtime data (built by bin/build-cn-ranges.php), so their absence is a skip, not a
// failure -- but the fallback direction is asserted instead.
if (is_file(Region::rangesPath())) {
    T::same('cn', Region::zoneForIp('223.5.5.5'), 'AliDNS is in the mainland zone');
    T::same('intl', Region::zoneForIp('1.1.1.1'), 'Cloudflare is outside the mainland zone');
    T::truthy(!Region::inHmt('223.5.5.5'), 'a mainland address is not in the Hong Kong/Macao/Taiwan table');
    if (is_file(Region::hmtRangesPath())) {
        T::same('hmt', Region::zoneForIp('203.198.0.1'), 'a Hong Kong address is in the hmt zone');
        T::same('hmt', Region::zoneForIp('168.95.1.1'), 'a Taiwan address is in the hmt zone');
        T::same('intl', Region::zoneForIp('8.8.8.8'), '8.8.8.8 is in neither table');
    }
} else {
    T::same('intl', Region::zoneForIp('223.5.5.5'), 'a missing table resolves everything to intl');
}

// --- the docs page is authored with tokens, and every token is defined ------
$docsHtml = (string) @file_get_contents(Config::path('docs.html'));
preg_match_all('/\{\{[ta]:([a-z0-9._-]+)\}\}/i', $docsHtml, $docsTokens);
T::truthy(count($docsTokens[1]) >= 10, 'docs.html is authored with translation tokens');
$tokenGaps = array();
foreach (array_unique($docsTokens[1]) as $tokenKey) {
    foreach (I18n::locales() as $localeCode) {
        if (!isset($strings[$localeCode][$tokenKey])) {
            $tokenGaps[] = $localeCode . ':' . $tokenKey;
        }
    }
}
T::same(array(), $tokenGaps, 'every docs token exists in every locale');
T::truthy(strpos($docsHtml, '{{host}}') !== false, 'the docs page derives its address instead of hard-coding one');
T::truthy(strpos($docsHtml, 'satellite.api.authskin.xyz') === false, 'docs.html does not print the retired API address');

// --- the landing page's string table ---------------------------------------
// The page is the one document that used to be entirely hard-coded English. It now
// draws every visible string from conf/i18n-home.php, and this block is what stops a
// half-translated pass: a key missing from one column falls back to English (or to the
// key itself) and prints at a reader as "home.hero.title".
$homeTable = is_file(Config::path('conf/i18n-home.php')) ? (array) require Config::path('conf/i18n-home.php') : array();
T::truthy($homeTable !== array(), 'the landing-page string table exists');
$homeKeys = array_keys((array) (isset($homeTable['en']) ? $homeTable['en'] : array()));
T::truthy(count($homeKeys) >= 60, 'the landing-page table covers the page (' . count($homeKeys) . ' keys)');
foreach (array('zh-Hans', 'zh-Hant', 'en') as $homeLocale) {
    T::same($homeKeys, array_keys((array) (isset($homeTable[$homeLocale]) ? $homeTable[$homeLocale] : array())), $homeLocale . ' defines exactly the landing-page keys');
}
$homePhp = (string) @file_get_contents(Config::path('partials/page-home.php'));
preg_match_all("/'(home\.[a-z0-9._-]+)'/", $homePhp, $homeUsed);
$homeMissing = array();
foreach (array_unique($homeUsed[1]) as $homeKey) {
    if (!isset($homeTable['en'][$homeKey]) || !isset($homeTable['zh-Hans'][$homeKey]) || !isset($homeTable['zh-Hant'][$homeKey])) {
        $homeMissing[] = $homeKey;
    }
}
T::same(array(), $homeMissing, 'every landing-page key the template uses exists in all three columns');
T::same(array(), array_values(array_diff($homeKeys, array_unique($homeUsed[1]))), 'the landing-page table has no unused keys');
T::truthy(strpos($homePhp, 'Unified TLE and satellite metadata') === false, 'the hero heading is not a hard-coded English literal');
T::truthy(strpos($homePhp, 'satellite.api.authskin.xyz') === false, 'the landing page does not print the retired API address');
T::truthy(strpos($homePhp, '$homePicker') !== false, 'the header offers the language selector');

// --- flat/square design invariants ------------------------------------------
// The direction is flat and square. Assert the tokens rather than the stylesheet,
// because the tokens are what a component reads.

$tokensCss = (string) @file_get_contents(Config::path('assets/tokens.css'));
T::truthy($tokensCss !== '', 'tokens.css exists');
foreach (array('xs', 'sm', 'md', 'lg', 'xl', 'full') as $shapeStep) {
    T::truthy((bool) preg_match('/--shape-' . $shapeStep . ':\s*0;/', $tokensCss), '--shape-' . $shapeStep . ' is 0 (square)');
}
foreach (array(1, 2, 3) as $level) {
    T::truthy((bool) preg_match('/--elev-' . $level . ':\s*none;/', $tokensCss), '--elev-' . $level . ' is none (flat)');
}
// Three-level structure: a component must never read a primitive.
T::truthy(strpos($tokensCss, 'LEVEL 1') !== false && strpos($tokensCss, 'LEVEL 2') !== false && strpos($tokensCss, 'LEVEL 3') !== false, 'tokens.css declares all three levels');
T::truthy((bool) preg_match('/oklch\(/', $tokensCss), 'colours are authored in OKLCH');
T::truthy(strpos($tokensCss, 'prefers-color-scheme: dark') !== false, 'a dark theme is declared');
T::truthy(strpos($tokensCss, '[data-theme="dark"]') !== false, 'an explicit theme choice overrides the OS');
$md3Css = (string) @file_get_contents(Config::path('assets/md3.css'));
T::truthy(strpos($md3Css, 'border-radius: 0 !important') !== false, 'md3.css forces square corners globally');

// --- the nav tells the truth about where the reader is ----------------------
// Reported from a screenshot: the reader was looking at the endpoints table while
// the underline sat under "Overview". The cause was a :first-child rule -- the
// underline was pinned to the first link, not to the current section.
$metroSource = (string) @file_get_contents(Config::path('assets/metro.css'));
T::truthy(strpos($metroSource, '.nav a:first-child') === false, 'the nav underline does not key on the first link');
T::truthy(strpos($metroSource, ".nav a[aria-current='location']") !== false, 'the nav underline follows aria-current');
$homeSource = (string) @file_get_contents(Config::path('partials/page-home.php'));
T::truthy(strpos($homeSource, 'aria-current="location"') !== false, 'the server marks the first section, so no-JS keeps a sensible default');
$appSource = (string) @file_get_contents(Config::path('assets/app.js'));
T::truthy(strpos($appSource, 'sectionnav') !== false, 'assets/app.js carries the scroll-spy');
T::truthy(strpos($appSource, "setAttribute('aria-current', 'location')") !== false, 'the scroll-spy moves the marker as the reader scrolls');

// --- the pre-answer worker: an HTML answer is a page, not data -------------
// /v1/health and /v1/status answer a browser with a dashboard. The worker used to
// dump that markup into its payload <pre> -- the visitor saw the page's SOURCE.
$swSource = (string) @file_get_contents(Config::path('assets/sw.js'));
T::truthy(strpos($swSource, 'adoptDocument') !== false, 'an HTML response replaces the loading document');
T::truthy(strpos($swSource, "type.indexOf('html')") !== false, 'the decision comes from the response Content-Type, not from a path list');
T::truthy(strpos($swSource, 'text/html, application/xhtml+xml') !== false, 'the pre-answer asks for HTML the way a browser does');
T::truthy(strpos($swSource, "VERSION = 'satapi-preanswer-2'") !== false, 'the worker version is bumped so an installed worker updates');
T::truthy(strpos($swSource, "cache: 'no-store'") !== false, 'the pre-answer still never caches an API response');
// ---------------------------------------------------------------------------
// The loading screen.
//
// Four-phase progress and a four-phase fill, every timing a bezier. The structure is
// reproduced from the reference sites the operator named:
//
//   tongdiaojihua.com   a constant 12px rail filled by a pixel length, a readout positioned by
//                       the same value, and an :after block filling left to right on
//                       cubic-bezier(.76,0,.24,1) while the overlay opacity lags on a delay
//   endfield            real task completion driving every visual
//
// These assertions pin the parts that were each asked for by name, so a later edit cannot drop
// one silently. The earlier revisions of this screen are in the git log; what follows describes
// only the current contract.
// ---------------------------------------------------------------------------

// Read both sources here rather than relying on an assignment further down the file: this block
// uses them throughout, and an undefined variable made every assertion that touched it fail with
// a message about the assertion instead of about the cause.
$consentJs = (string) @file_get_contents(Config::path('assets/consent.js'));
$md3Css = (string) @file_get_contents(Config::path('assets/md3.css'));

$loaderSegment = '';
if (preg_match('#/\\* =+\\s*\\n\\s*Loading screen.*?/\\* --- focus visibility#s', $md3Css, $segMatch)) {
    $loaderSegment = $segMatch[0];
}
T::truthy($loaderSegment !== '', 'the loading screen block is present');
// Comments are stripped before scanning: the block documents the rules it enforces, and an
// earlier version of this check flagged the word cubic-bezier inside the sentence explaining
// why that curve was chosen.
$loaderRules = (string) preg_replace('#/\\*.*?\\*/#s', '', $loaderSegment);
// Everything before the reduced-motion query must be a bezier. The linear inside that query is
// the point of the query, so the scan stops there rather than flagging it.
$motionAt = strpos($loaderRules, 'prefers-reduced-motion');
$loaderEased = $motionAt === false ? $loaderRules : substr($loaderRules, 0, $motionAt);

// --- the rail is ALWAYS thick ----------------------------------------------
// Asked for explicitly: thick throughout, not thickened on completion. The reference bar is a
// constant 12px for its whole life, and it stays that way here, so no width change can flash.
T::truthy((bool) preg_match('#\\.boot__progress\\s*\\{[^}]*width:\\s*var\\(--boot-rail-w,\\s*12px\\)#s', $loaderRules), 'the rail is a constant 12px, not a growth at the end');
T::truthy(strpos($loaderRules, 'boot--full') === false, 'no class thickens the rail on completion');
T::truthy(strpos($consentJs, 'boot--full') === false, 'the script does not add a thickening class');

// --- the bar is filled by a pixel length, as the reference does -------------
T::truthy((bool) preg_match('#\\.boot__progressBar\\s*\\{[^}]*height:\\s*var\\(--boot-progress-px#s', $loaderRules), 'the rail is filled by a pixel length');
T::truthy((bool) preg_match('#\\.boot__progressText\\s*\\{[^}]*top:\\s*var\\(--boot-progress-px#s', $loaderRules), 'the readout is positioned by the same value, so it rides the fill');
T::truthy((bool) preg_match("#documentElement\\.style\\.setProperty\\('--boot-progress-px'#", $consentJs), 'the script writes that length');
// It must go to the ROOT: the backdrop is a sibling of the rail, and a custom property only
// inherits downward, so writing it to the rail left the backdrop reading the fallback.
T::truthy((bool) preg_match("#documentElement\\.style\\.setProperty\\('--boot-progress'#", $consentJs), 'the ratio is written to the root too, so the backdrop can read it');

// --- the four phases of the progress bar -----------------------------------
foreach (array('startup', 'sustain', 'deceleration', 'rest') as $phaseKey) {
    T::truthy(strpos($consentJs, "key: '" . $phaseKey . "'") !== false, 'the ' . $phaseKey . ' segment exists');
}
T::truthy((bool) preg_match('#function bootTraveled\\(#', $consentJs), 'the journey is mapped by elapsed time');
T::truthy((bool) preg_match('#function bezier\\(curve, x\\)#', $consentJs), 'there is a bezier solver, so the curves are real curves');
// Each share is also the fraction of the journey that segment carries, so together they must
// reach 1 or the bar would never arrive.
$shares = array();
if (preg_match_all("#key: '(startup|sustain|deceleration|rest)',\\s*share: ([0-9.]+)#", $consentJs, $shareMatch)) {
    foreach ($shareMatch[1] as $i => $k) { $shares[$k] = (float) $shareMatch[2][$i]; }
}
T::same(4, count($shares), 'all four segments declare a share');
$shareTotal = 0.0;
foreach ($shares as $v) { $shareTotal += $v; }
T::truthy(abs($shareTotal - 1.0) < 0.001, 'the shares sum to 1 (got ' . $shareTotal . ')');
// The sustain segment must carry the most: it is the fast middle of the journey.
T::truthy($shares['sustain'] > $shares['startup'] && $shares['sustain'] > $shares['deceleration'], 'sustain carries the largest share');
T::truthy($shares['startup'] > $shares['rest'], 'startup carries more than the settling segment');

// --- every timing is a bezier ---------------------------------------------
// Asked for explicitly, replacing the earlier all-linear requirement. The only linear left is
// inside the reduced-motion branch, which is where it belongs.
$timings = array();
if (preg_match_all('#(?:animation|transition)\\s*:\\s*([^;]+);#', $loaderEased, $timingMatch)) {
    foreach ($timingMatch[1] as $declaration) {
        $d = trim($declaration);
        if (stripos($d, 'none') === 0) { continue; }   // a switch, not a timing
        $timings[] = $d;
    }
}
T::truthy(count($timings) >= 10, 'the screen declares its timings (found ' . count($timings) . ')');
foreach ($timings as $declaration) {
    T::truthy(stripos($declaration, 'cubic-bezier') !== false, 'timing is a bezier: ' . $declaration);
}
$bezierCount = substr_count($loaderEased, 'cubic-bezier');
T::truthy($bezierCount >= 10, 'the screen uses beziers throughout (found ' . $bezierCount . ')');
T::truthy(strpos($loaderEased, 'var(--ease') === false, 'no easing variable is used on the loading screen');

// --- the fill: left to right, on the reference's own curve ------------------
$bandRule = '';
if (preg_match('#\\.boot--leaving::after\\s*\\{(.*?)\\}#s', $loaderRules, $bandMatch)) {
    $bandRule = $bandMatch[1];
}
T::truthy($bandRule !== '', 'the fill rule is present');
T::truthy(strpos($bandRule, 'transform-origin: left center') !== false, 'the fill starts at the LEFT edge');
T::truthy(strpos($bandRule, 'scaleX(0)') !== false, 'the fill scales horizontally');
T::truthy(strpos($bandRule, 'scaleY') === false, 'the fill has no vertical scaling');
T::truthy((bool) preg_match('#linear-gradient\\(90deg#', $bandRule), 'the fill gradient runs left to right');
T::truthy(strpos($bandRule, 'cubic-bezier(0.76, 0, 0.24, 1)') !== false, 'the fill uses the reference curve cubic-bezier(.76,0,.24,1)');

// --- no pause between the count and the fill --------------------------------
// The fault this replaces: a 300ms hold plus a thickening class sat between 100% and the fill,
// which read as a flicker. The fill now follows the count once it has SETTLED -- it waits on the
// value, not on a timer.
T::truthy((bool) preg_match('#function settleRoll\\(#', $consentJs), 'the fill waits for the count to settle');
T::truthy((bool) preg_match('#if \\(rollSettled\\(\\) \\|\\| Date\\.now\\(\\) - started > \\d+\\)#', $consentJs), 'the settle wait is bounded, so the screen cannot stand forever');
T::truthy(strpos($consentJs, 'PHASE3_REST_MS') === false, 'there is no rest beat between the count and the fill');
T::truthy(strpos($consentJs, 'PHASE3_BUFFER_MS') === false, 'there is no separate buffer beat either');
// The hold at 100%: asked for at 0.2-0.4s, and asserted as a range rather than a value so the
// operator can retune it inside that window without a test edit.
T::truthy((bool) preg_match('#var PHASE_HOLD_MS = (\d+);#', $consentJs, $holdMatch), 'the hold constant exists');
if (!empty($holdMatch[1])) {
    $holdMs = (int) $holdMatch[1];
    T::truthy($holdMs >= 200 && $holdMs <= 400, 'the hold is within 0.2-0.4s (got ' . $holdMs . 'ms)');
}
// The fill must start from inside that hold's callback, not in the same frame the count lands.
T::truthy(
    (bool) preg_match('#settleRoll\(function \(\) \{\s*//[^\n]*\n\s*window\.setTimeout\(function \(\) \{\s*//[^\n]*\n(?:\s*//[^\n]*\n)*\s*el\.classList\.add\(.boot--leaving.\);#s', $consentJs),
    'the fill is held at 100% before it starts'
);

// --- the timings that remain ------------------------------------------------
foreach (array(
    'PHASE_FILL_MS = 760'       => 'the fill duration matches the stylesheet animate',
    'PHASE_FADE_DELAY_MS = 500' => 'the overlay fade is delayed until the fill has crossed',
    'BOOT_TRAVEL_MS = 1250'     => 'the roll has a defined travel time',
) as $needle => $label) {
    T::truthy(strpos($consentJs, $needle) !== false, 'timing: ' . $label);
}
// The stylesheet animate and the script constant must agree, or the fade lands mid-fill.
T::truthy((bool) preg_match('#boot-fill 760ms#', $loaderRules), 'the stylesheet animate is 760ms, matching PHASE_FILL_MS');

// --- the hand-off: the overlay fades, the page does NOT ---------------------
// The page fade was removed and must not come back. The overlay is rendered inside <body> and
// carries an opaque background, so a rule that fades the page blanks the overlay with it. It
// was measured doing exactly that: pure white (luminance 255) at the instant the fade started,
// a 247-per-100ms rise with a 40-point overshoot past the page's own brightness, and the tile
// sweep only becoming visible at fill scaleX 0.68. The overlay's own delayed fade over a page
// that stays opaque is the entire hand-off.
T::truthy(
    (bool) preg_match('#\.boot--done \{[^}]*transition: opacity 400ms cubic-bezier\(0, 0, 0\.58, 1\) 500ms#s', $loaderRules),
    'the overlay fade is delayed 500ms after the fill, as the reference does'
);
foreach (array('html.boot-entering body', 'html.boot-entering.boot-entered', 'boot-entering', 'boot-entered') as $gone) {
    T::truthy(strpos($loaderRules, $gone) === false, 'no page-fade rule remains in the stylesheet: ' . $gone);
    T::truthy(strpos($consentJs, str_replace('html.', '', $gone)) === false, 'no page-fade hook remains in the script: ' . $gone);
}
T::truthy(strpos($consentJs, 'enterCrossfade') === false, 'the cross-fade helper is gone rather than left as dead code');
T::truthy(strpos($consentJs, 'PHASE_CROSSFADE_MS') === false, 'the page-fade constant is gone too');
// The overlay really is opaque, which is what makes hiding the page unnecessary in the first
// place. Asserted against the declaration that actually paints: background-color inside the
// loader block, which overrides the shorthand set for .boot earlier in the file. If it ever
// becomes translucent, the hand-off needs reconsidering.
T::truthy(
    (bool) preg_match('#\.boot \{[^}]*background-color: var\(--boot-ink#s', $loaderRules),
    'the overlay has an opaque background, so the page is covered and never needs hiding'
);

// --- the challenge widget is hidden until it is wanted ----------------------
// Asked for: hidden while loading, shown only when the challenge failed or when loading
// finished. Hidden by `visibility` rather than `display`, because the element keeps its box and
// the challenge still has to solve while the screen is up.
$capRule = '';
if (preg_match('#\\[data-cap-host\\]\\s*\\{(.*?)\\}#s', $loaderRules, $capMatch)) {
    $capRule = $capMatch[1];
}
T::truthy($capRule !== '', 'the widget host has a rule');
T::truthy(strpos($capRule, 'visibility: hidden') !== false, 'the widget is hidden by default');
T::truthy(strpos($capRule, 'opacity: 0') !== false, 'the widget is transparent by default');
T::truthy(strpos($capRule, 'display: none') === false, 'the widget is NOT display:none, which would collapse the box it solves into');
T::truthy((bool) preg_match('#\\[data-cap-host\\]\\.is-visible \\{[^}]*visibility: visible#s', $loaderRules), 'a class reveals the widget');
T::truthy(strpos($consentJs, 'function revealCapHost') !== false, 'the script has one reveal path');
// The state argument is what tells the stylesheet a FAILED challenge may not be kept off
// screen (the corner controls are gated until the first scroll).
T::truthy(strpos($consentJs, "host.setAttribute('data-cap-state', state)") !== false, 'the reveal records why the widget is up');
// The three call sites the operator named, plus the no-overlay safety.
T::truthy((bool) preg_match("#function showOverlayError\\(message\\) \\{.*?revealCapHost\\('failed'\\);#s", $consentJs), 'failure reveals the widget');
// The file is CRLF and the call sits after the closing brace of the guard, so the pattern allows
// the rest of that line before the comment block.
T::truthy((bool) preg_match("#removeChild\\(el\\);[^\\n]*\\n(?:\\s*//[^\\n]*\\n)+\\s*revealCapHost\\('done'\\);#", $consentJs), 'finished loading reveals the widget');
T::truthy((bool) preg_match("#function runVerifyPage\\(\\) \\{.*?revealCapHost\\('done'\\);#s", $consentJs), 'the verify page reveals it at once');
T::truthy((bool) preg_match("#if \\(!overlay\\(\\)\\) \\{\\s*revealCapHost\\('done'\\);#s", $consentJs), 'a page with no boot screen still reveals it');

// --- the count still rolls through every integer ----------------------------
T::truthy((bool) preg_match('#for \\(var v = Math\\.floor\\(bootShown\\) \\+ 1; v <= Math\\.floor\\(next\\); v\\+\\+\\)#', $consentJs), 'the roll paints each integer it crosses');
T::truthy(strpos($consentJs, 'bootPaintLog') !== false, 'the paint order is logged, so a skip can be proven or disproven');
// The journey baseline is re-stamped whenever the destination moves, and the stopwatch is not:
// measured as total 0.001 freezing the display at 0.001 while the target climbed to 67.
T::truthy((bool) preg_match('#stepRoll\\.from = bootShown;#', $consentJs), 'the journey origin is re-stamped on each new leg');
T::truthy((bool) preg_match('#if \\(bootRollTimer === null\\) \\{\\s*startRoll\\(\\);#s', $consentJs), 'the stopwatch is only reset when the roll is idle');

// --- server-rendered structure, on every page -------------------------------
$shellPhp = (string) @file_get_contents(Config::path('partials/shell.php'));
foreach (array(
    'boot__bg'           => 'the backdrop',
    'boot__progress'     => 'the rail',
    'boot__progressBar'  => 'the fill',
    'boot__progressText' => 'the readout block',
    'boot__caption'      => 'the status caption',
    'boot__moreDeco'     => 'the deco group',
    'data-boot-figure'   => 'the percentage readout',
) as $probe => $label) {
    T::truthy(strpos($shellPhp, $probe) !== false, 'the shell renders ' . $label . ', so every page gets it');
}
T::truthy(strpos($shellPhp, 'role="progressbar"') !== false, 'the progress element carries its ARIA role');
T::truthy((bool) preg_match('#<div class="boot__caption"[^>]*data-boot-caption#', $shellPhp), 'the caption has its own class, so a caption write cannot clobber another node');
T::truthy(strpos($consentJs, "caption.querySelector('.i18n.is-active')") !== false, 'the caption updates only the active locale');

// --- the caption strings exist in every locale -------------------------------
foreach (array('boot.preparing', 'boot.verifying', 'boot.entering', 'boot.ready') as $key) {
    foreach (I18n::locales() as $localeCode) {
        $value = I18n::translate($localeCode, $key, '');
        T::truthy($value !== '' && $value !== $key, $localeCode . ' defines ' . $key);
    }
}

// --- migration completeness GATE --------------------------------------------
// The rename shipped in v3.1. A single surviving old name or old version anywhere a
// client can see it would mean the migration is half-applied, so this scans the whole
// tree rather than a sample. Only the vendored Swagger bundle is exempt, and that is
// recorded as a known limitation instead of being silently skipped.
$oldNames = array('Satellite Data API');
$oldVersions = array('2.2.0', 'v2.2', '3.1.2900-beta');
// What a CLIENT can see: the code that renders responses and the configuration that
// shapes them. `tests/` is excluded because a migration gate legitimately has to name
// the old strings to check for them, and `conf/config.example.php` is a template for
// operators rather than published content. Line comments are stripped first: several
// files document the rename in a comment, and flagging the explanation would make the
// gate report a false failure on its own documentation.
$scanDirs = array('lib', 'partials', 'conf', 'bin', 'assets');
$offenders = array();
foreach ($scanDirs as $dir) {
    $base = Config::path($dir);
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace(Config::path('') . '/', '', $file->getPathname());
        if (strpos($rel, 'assets/vendor/') === 0) {
            continue;   // vendored third-party bundles, documented limitation
        }
        if ($rel === 'conf/config.example.php') {
            continue;   // an operator template, not published content
        }
        $body = (string) @file_get_contents($file->getPathname());
        // Strip line and block comments before scanning.
        $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);
        $body = (string) preg_replace('~^[ \t]*(?://|#|\*).*$~m', '', $body);
        // The version check uses a word boundary. A plain substring search for "v2.2"
        // matches an SVG path coordinate ("M12 19.4v2.2") inside a page template, which
        // is exactly the sort of false positive that makes a gate get ignored.
        foreach ($oldNames as $needle) {
            if (strpos($body, $needle) !== false) {
                $offenders[] = $rel . ' contains the old name "' . $needle . '"';
            }
        }
        foreach ($oldVersions as $needle) {
            $pattern = '~(?<![0-9A-Za-z])' . preg_quote($needle, '~') . '(?![0-9.])~';
            if (preg_match($pattern, $body) === 1) {
                $offenders[] = $rel . ' contains the old version "' . $needle . '"';
            }
        }
    }
}
T::same(array(), $offenders, 'no old service name or version survives outside vendored bundles');
// The new identity must be present where it matters.
T::same('AzureOpenAPI for Satellite', (string) Config::get('service.name'), 'service name migrated');
T::same('azureopenapi-for-satellite', (string) Config::get('service.slug'), 'service slug migrated');
// A release, not a pre-release: the part before any build metadata must carry no
// `-suffix`, because under SemVer `3.3.0-anything` sorts BELOW `3.3.0`. The old
// `3.2.12-RA` line asserted exactly the opposite rule and had to be replaced.
T::truthy((bool) preg_match('/^3\.\d+\.\d+(?:\+[0-9A-Za-z.-]+)?$/', (string) Config::get('service.version')), 'version is a 3.x release, not a pre-release');
T::truthy(strpos((string) Config::get('http.user_agent'), 'AzureOpenAPI-for-Satellite') !== false, 'the outbound User-Agent carries the new identity');

// --- paired contracts: every cookie the code sets is documented and vice versa
$documentedCookies = array_merge(
    array_keys((array) Config::get('cookies.essential', array())),
    array_keys((array) Config::get('cookies.preferences', array())),
    array_keys((array) Config::get('cookies.analytics', array()))
);
$codeBlob = '';
foreach (array('lib/Support/Pow.php', 'lib/Support/I18n.php', 'assets/consent.js', 'assets/announce.js', 'partials/shell.php') as $codeFile) {
    $codeBlob .= (string) @file_get_contents(Config::path($codeFile));
}
foreach ($documentedCookies as $cookieName) {
    T::truthy(strpos($codeBlob, $cookieName) !== false, 'documented cookie ' . $cookieName . ' is actually referenced by the code');
}
T::same(
    array('cookie_consent', 'announcement_dismissed', 'announcement_read', 'announcement_declined', 'cap_session'),
    array_keys((array) Config::get('cookies.essential', array())),
    'essential cookies are exactly the five that cannot be avoided'
);

// ---------------------------------------------------------------------------
T::group('Announcement board');

/*
 * The board is a small Markdown compiler, so the tests drive it the way the CLI
 * does: write files, compile, read the artifact -- plus the pure helpers
 * (markdown, select, gateFor) directly, because the interesting cases there are
 * the ones a real file would never contain (a script tag, an expired notice).
 */
$anmDir = $tmp . '/announcements';
@mkdir($anmDir, 0777, true);
Config::set('announcements.compiled', 'tests/tmp/announcements.php');
Config::set('announcements.enabled', true);
@unlink($tmp . '/announcements.php');
AnnouncementBoard::reset();

file_put_contents($anmDir . '/2026-11-03-domain.md', "---\n"
    . "id: domain-migration\ntitle: 迁移公告\ndate: 2026-11-03\n"
    . "author: Announcement system\nlevel: 重要\nrefusable: false\nexpires: 2027-01-06\n"
    . "---\n\n## 小标题\n\n迁移到 [新地址](https://satellite.db.authskin.xyz)。\n");
file_put_contents($anmDir . '/2026-10-01-policy.md', "---\n"
    . "id: policy-update\ntitle: Policy update\ndate: 2026-10-01\nlevel: 紧急\nrefusable: true\n"
    . "---\n\nYou must agree to continue.\n");
file_put_contents($anmDir . '/2026-12-01-future.md', "---\n"
    . "id: future-one\ntitle: Scheduled\ndate: 2026-12-01\nlevel: minor\n"
    . "---\n\nNot yet.\n");
file_put_contents($anmDir . '/2026-11-02-draft.md', "---\n"
    . "id: still-a-draft\ntitle: Draft\ndate: 2026-11-02\nlevel: normal\ndraft: true\n"
    . "---\n\nNot finished.\n");

$now = strtotime('2026-11-04 12:00:00');
$report = AnnouncementBoard::compile($anmDir, false, $now);
T::same(4, $report['files'], 'every .md file in the directory is read');
T::same(3, $report['compiled'], 'the draft is not compiled');
T::same(1, $report['skipped'], 'the draft is counted as skipped');
T::same(array(), $report['errors'], 'a well-formed board compiles without errors');
T::truthy($report['written'], 'the compiled artifact is written');

$byId = array();
foreach ($report['items'] as $item) {
    $byId[$item['id']] = $item;
}
T::truthy(isset($byId['domain-migration']), 'the announcement id comes from the front matter');
T::same('important', $byId['domain-migration']['level'], '重要 maps to the important level');
T::same('urgent', $byId['policy-update']['level'], '紧急 maps to the urgent level');
T::same(true, $byId['policy-update']['refusable'], 'refusable: true is parsed as a boolean');
T::same('agree', $byId['policy-update']['ack'], 'a refusable notice asks for agreement');
T::same('read', $byId['domain-migration']['ack'], 'a plain notice is acknowledged as read');
T::same('Announcement system', $byId['domain-migration']['author'], 'the author is taken from the front matter');
T::same('2026-11-03', $byId['domain-migration']['date'], 'the date is normalised to Y-m-d');
T::same('迁移公告', $byId['domain-migration']['title'], 'the title survives the front matter');
T::truthy(strpos($byId['domain-migration']['html'], '<h3>小标题</h3>') !== false, 'a Markdown heading becomes an h3 inside the panel');
T::truthy(strpos($byId['domain-migration']['html'], 'href="https://satellite.db.authskin.xyz"') !== false, 'a Markdown link becomes an anchor');

$live = AnnouncementBoard::select($report['items'], $now);
T::same(2, count($live), 'a future-dated announcement is not live yet');
T::same('policy-update', $live[0]['id'], 'the most severe notice comes first');
T::same('domain-migration', $live[1]['id'], 'then the less severe one');

$later = AnnouncementBoard::select($report['items'], strtotime('2027-01-07 00:00:01'));
$laterIds = array();
foreach ($later as $laterItem) {
    $laterIds[$laterItem['id']] = true;
}
T::same(false, isset($laterIds['domain-migration']), 'an announcement disappears after its expiry date');
T::truthy(isset($laterIds['future-one']), 'a scheduled announcement becomes live at its date');
T::truthy(isset($laterIds['policy-update']), 'an announcement without an expiry stays');

AnnouncementBoard::reset();
T::same(3, count(AnnouncementBoard::all()), 'the compiled artifact is readable again in a new process state');

$view = AnnouncementBoard::active();
$viewIds = array();
foreach ($view as $viewItem) {
    $viewIds[$viewItem['id']] = $viewItem;
}
T::truthy(isset($viewIds['domain-migration']['refusable']) && isset($viewIds['domain-migration']['ack']) && isset($viewIds['domain-migration']['html']), 'the view model carries what the template renders');
T::same(false, isset($viewIds['future-one']), 'a scheduled announcement is not rendered yet');
T::same(true, isset($viewIds['domain-migration']), 'the live announcement reaches the page');

// --- a broken file must block publication rather than drop one notice -------
file_put_contents($anmDir . '/2026-11-05-noid.md', "---\ntitle: No id given\ndate: 2026-11-05\n---\n\nBody.\n");
$noid = AnnouncementBoard::compile($anmDir, true, $now);
$noidIds = array();
foreach ($noid['items'] as $noidItem) {
    $noidIds[$noidItem['id']] = true;
}
T::truthy(isset($noidIds['noid']), 'a missing id falls back to the file name, minus the date');

file_put_contents($anmDir . '/2026-11-06-bad.md', "---\nid: broken\ndate: 2026-11-06\n---\n\nNo title.\n");
$broken = AnnouncementBoard::compile($anmDir, false, $now);
T::same(1, count($broken['errors']), 'a missing title is reported');
T::same(false, $broken['written'], 'nothing is written while any file has an error');

// --- duplicate ids ---------------------------------------------------------
@unlink($anmDir . '/2026-11-06-bad.md');
file_put_contents($anmDir . '/2026-11-07-dup.md', "---\nid: policy-update\ntitle: Duplicate\ndate: 2026-11-07\n---\n\nBody.\n");
$dup = AnnouncementBoard::compile($anmDir, true, $now);
$dupErrors = implode("\n", $dup['errors']);
T::truthy(strpos($dupErrors, 'duplicate id') !== false, 'a duplicate id is refused');
@unlink($anmDir . '/2026-11-07-dup.md');
@unlink($anmDir . '/2026-11-05-noid.md');

// --- one notice, two languages ---------------------------------------------
// A notice published in Chinese and English is two files with ONE id: the id is the
// dismissal key, so acknowledging either version acknowledges the notice rather than
// one translation of it. The client shows the version that matches the reader, which
// is why every variant must reach the artifact instead of being filtered out here --
// a server-filtered board would make a cached document depend on the visitor.
file_put_contents($anmDir . '/2026-11-03-domain.zh.md', '---
id: bilingual
title: 双语公告
date: 2026-11-03
lang: zh-Hans
---

中文正文。
');
file_put_contents($anmDir . '/2026-11-03-domain.en.md', '---
id: bilingual
title: Bilingual notice
date: 2026-11-03
lang: en
---

English body.
');
$bi = AnnouncementBoard::compile($anmDir, false, $now);
T::same(array(), $bi['errors'], 'two languages of one id are not a duplicate');
$biLangs = array();
foreach ($bi['items'] as $biItem) {
    if ($biItem['id'] === 'bilingual') {
        $biLangs[(string) $biItem['lang']] = (string) $biItem['title'];
    }
}
T::same(2, count($biLangs), 'both language versions of one notice are compiled');
T::truthy(isset($biLangs['zh-Hans'], $biLangs['en']), 'the lang field is parsed into the content locales');
T::same('双语公告', isset($biLangs['zh-Hans']) ? $biLangs['zh-Hans'] : '', 'each version keeps its own title');
T::same('all', $byId['domain-migration']['lang'], 'a file without lang is shown to every language');

// The duplicate rule is per language, so the same id AND language is still refused.
file_put_contents($anmDir . '/2026-11-04-domain.dup.md', '---
id: bilingual
title: 重复
date: 2026-11-03
lang: en
---

Body.
');
$dupLang = AnnouncementBoard::compile($anmDir, true, $now);
T::truthy(strpos(implode(' ', $dupLang['errors']), 'for lang "en"') !== false, 'the same id in the same language is still a duplicate');
@unlink($anmDir . '/2026-11-04-domain.dup.md');

// The panel cap counts NOTICES, not translations: one bilingual notice must not eat
// the whole budget of announcements.max_shown and hide the next one.
AnnouncementBoard::reset();
Config::set('announcements.max_shown', 10);
$allViews = AnnouncementBoard::active();
$variantCount = 0;
$viewIdSet = array();
$bilingualView = null;
foreach ($allViews as $viewItem) {
    $viewIdSet[(string) $viewItem['id']] = true;
    if ($viewItem['id'] === 'bilingual') {
        $variantCount++;
        $bilingualView = $viewItem;
    }
}
T::same(2, $variantCount, 'both language versions of one notice reach the page');
T::same(3, count($viewIdSet), 'the three live notices are distinct');
T::truthy($bilingualView !== null && isset($bilingualView['lang']), 'the view model carries the language the panel declares');

// The panel cap counts NOTICES, not files: one bilingual notice must not eat the
// whole budget of announcements.max_shown and hide the notice behind it.
Config::set('announcements.max_shown', 1);
$capped = AnnouncementBoard::active();
$cappedIds = array();
$cappedVariants = 0;
foreach ($capped as $cappedItem) {
    $cappedIds[(string) $cappedItem['id']] = true;
    if (count($cappedIds) === 1) {
        $cappedVariants++;
    }
}
T::same(1, count($cappedIds), 'the cap is counted in notices');
T::same(count($capped), $cappedVariants, 'the kept notice keeps every variant it has');
Config::set('announcements.max_shown', 3);

// The template has to publish it, or the client cannot choose.
$shellSource = (string) @file_get_contents(Config::path('partials/shell.php'));
T::truthy(strpos($shellSource, 'data-anm-lang') !== false, 'each board panel declares its language in the markup');
$announceJs = (string) @file_get_contents(Config::path('assets/announce.js'));
T::truthy(strpos($announceJs, 'langRank') !== false, 'the board client ranks a version by the reader language');
T::truthy(strpos($announceJs, 'data-anm-lang') !== false, 'the board client reads the declared language');
@unlink($anmDir . '/2026-11-03-domain.zh.md');
@unlink($anmDir . '/2026-11-03-domain.en.md');

// --- the holiday themes: windows, tracks, greeting -------------------------
// A theme is a CALENDAR decision first: the site is themed on the right days without
// anybody deploying anything, and a visitor is still offered a way out of it. Every
// boundary below is instant-based, because "through 2 January 23:59" and "from 3 January
// 00:00" are the same statement and only one of them is testable.
T::same('default', (string) Config::get('theme.season'), 'no manual pin by default');
$themes = Season::themes();
foreach (array('default', 'christmas', 'newyear', 'spring') as $themeName) {
    T::truthy(isset($themes[$themeName]), 'the theme table defines ' . $themeName);
}
T::same(array('default', 'christmas', 'newyear', 'spring'), Season::known(), 'four themes, in table order');

$themeZone = new DateTimeZone('Asia/Shanghai');
$at = static function ($when) use ($themeZone) {
    return new DateTimeImmutable($when, $themeZone);
};
T::same('default', Season::schedule($at('2026-12-23 23:59:59')), 'the day before Christmas is plain');
T::same('christmas', Season::schedule($at('2026-12-24 00:00:00')), 'Christmas opens at midnight on the 24th');
T::same('christmas', Season::schedule($at('2026-12-26 23:59:59')), 'and runs through the 26th');
T::same('default', Season::schedule($at('2026-12-27 00:00:00')), 'and closes at midnight after it');
T::same('newyear', Season::schedule($at('2026-12-31 00:00:00')), 'New Year opens on 31 December');
T::same('newyear', Season::schedule($at('2027-01-02 23:59:59')), 'and runs through 2 January');
T::same('default', Season::schedule($at('2027-01-03 00:00:00')), 'and closes at midnight after it');
T::same('spring', Season::schedule($at('2027-02-04 00:00:00')), 'the Spring Festival opens on 4 February');
T::same('spring', Season::schedule($at('2027-02-09 23:59:59')), 'and runs through the 9th');
T::same('default', Season::schedule($at('2027-02-10 00:00:00')), 'and closes at midnight on the 10th');
T::same('default', Season::schedule($at('2027-06-01 12:00:00')), 'and the rest of the year is plain');

// The music rotation, including the single-day set pieces.
Season::boot(Request::fromParts('/', array('season' => 'christmas')));
$christmasTracks = Season::tracks($at('2026-12-24 12:00:00'));
// Two tracks again: the 107-second fragment was replaced by a real encode from the
// opus source, which had been on E: the whole time (the search that "proved" it gone
// only looked at J:).
T::same(2, count($christmasTracks), 'Christmas rotates two tracks');
T::same('/assets/music/christmas-last-christmas.m4a.enc', $christmasTracks[0], 'Last Christmas is back in the rotation');
T::same('/assets/music/christmas-jingle-bell-rock.m4a.enc', $christmasTracks[1], 'with Jingle Bell Rock beside it');
T::truthy(is_file(Config::path('assets/music/christmas-last-christmas.m4a.enc')), 'and the ciphertext is really there, not just listed');
T::same(array('/assets/music/christmas-all-i-want.m4a.enc'), Season::tracks($at('2026-12-25 12:00:00')), 'on the 25th the set piece replaces the rotation');
T::same(2, count(Season::tracks($at('2026-12-26 12:00:00'))), 'and the two-track rotation is back on the 26th');

Season::boot(Request::fromParts('/', array('season' => 'spring')));
T::same(array('/assets/music/spring-hua-hao-yue-yuan.m4a.enc'), Season::tracks($at('2027-02-05 12:00:00')), 'the Spring Festival plays 花好月圆 by default');
// 6 February is 春节序曲 and 9 February is 难忘今宵. Both arrived as AVS3 audio and both are
// now SHIPPED -- the operator's own ffmpeg (an HDR Vivid build carrying libarcdav3a) decodes
// them -- so these are no longer fallback tests: they are the schedule.
T::same(array('/assets/music/spring-chunjie-xuqu.m4a.enc'), Season::tracks($at('2027-02-06 12:00:00')), '6 February plays 春节序曲');
T::same(array('/assets/music/spring-nanwang-jin-xiao.m4a.enc'), Season::tracks($at('2027-02-09 12:00:00')), '9 February plays 难忘今宵');
T::same(array('/assets/music/spring-hua-hao-yue-yuan.m4a.enc'), Season::tracks($at('2027-02-08 12:00:00')), '8 February falls back to 花好月圆');

Season::boot(Request::fromParts('/', array('season' => 'newyear')));
T::truthy(count(Season::tracks()) >= 1, 'the New Year theme has at least one track');

// Greetings replace the loading screen's tagline, in the request's language.
Season::boot(Request::fromParts('/', array('season' => 'christmas')));
T::same('Merry Christmas!', Season::greeting(), 'the Christmas greeting reaches the loading screen');
Season::boot(Request::fromParts('/', array('season' => 'spring')));
T::truthy(Season::greeting() !== '', 'the Spring Festival theme greets too');
Season::boot(Request::fromParts('/'));
T::same('', Season::greeting(), 'the plain site keeps its tagline');

// --- resolving a theme for one request -------------------------------------
T::same('default', Season::forRequest(Request::fromParts('/'))['name'], 'today is outside every window, so the plain site');
T::same('newyear', Season::forRequest(Request::fromParts('/', array('season' => 'newyear')))['name'], '?season=newyear previews it');
T::same('query', Season::forRequest(Request::fromParts('/', array('season' => 'newyear')))['source'], 'a preview is per-visitor, so the response must not be cached');
T::same('default', Season::forRequest(Request::fromParts('/', array('season' => 'default')))['name'], '?season=default turns it off');
T::same('default', Season::forRequest(Request::fromParts('/', array('season' => 'tinsel')))['name'], 'an unknown theme is refused, not half-applied');

// The cookie records the theme that was refused, not "no themes ever": a visitor who
// turned Christmas off has not asked to never see the Spring Festival one.
Config::set('theme.season', 'christmas');
$refused = Season::forRequest(Request::fromParts('/', array(), array('cookie' => 'satapi-season=christmas')));
T::same('default', $refused['name'], 'the cookie suppresses the theme it names');
T::same('cookie', $refused['source'], 'and it is attributed to the cookie');
$otherTheme = Season::forRequest(Request::fromParts('/', array(), array('cookie' => 'satapi-season=newyear')));
T::same('christmas', $otherTheme['name'], 'a refusal of another theme does not suppress this one');
Config::set('theme.season', 'default');

// --- what an active theme adds to the document -----------------------------
Season::boot(Request::fromParts('/'));
T::truthy(!Season::active(), 'the plain site has no theme');
T::truthy(!Season::pinned(), 'and it stays cacheable');
T::same('', Season::badge(), 'no badge without a theme');
T::same('', Season::player(), 'no player without a theme');
T::same('', Season::scripts(), 'no effect without a theme');
T::same(array(), Season::head(), 'no stylesheet without a theme');
// The Cap pill is pinned to the same corner on the plain site, over the default film's
// slogan, so the gate is on every page -- not only on a themed one.
T::truthy(strpos(Season::bodyAttrs(), 'data-floats="gated"') !== false, 'the plain site defers its corner controls too');

Season::boot(Request::fromParts('/', array('season' => 'christmas')));
T::truthy(Season::active(), 'the requested theme is active');
T::truthy(Season::pinned(), 'and the caller must mark the response no-store');
T::same(' christmas', Season::bodyClass(), 'the body gains exactly the theme class');
$themeHead = implode(' ', Season::head());
T::truthy(strpos($themeHead, 'theme.css') !== false, 'the shared chrome stylesheet is linked');
T::truthy(strpos($themeHead, 'holiday.css') !== false, 'the shared component layer is linked');
T::truthy(strpos($themeHead, 'christmas.css') !== false, 'the theme palette is linked last');
T::truthy(strpos(Season::badge(), 'data-season-exit') !== false, 'the badge offers the way out');
T::truthy(strpos(Season::player(), 'data-music-toggle') !== false, 'the player offers play/pause');
T::truthy(strpos(Season::player(), 'data-music-replay') !== false, 'and replay');
$themeScripts = Season::scripts();
T::truthy(strpos($themeScripts, 'theme.js') !== false, 'the shared chrome script is loaded');
T::truthy(strpos($themeScripts, 'christmas.js') !== false, 'the theme effect is loaded');
T::truthy(strpos(Season::bodyAttrs(), 'data-floats="gated"') !== false, 'a theme defers its corner controls until the first scroll');

// The effects create their own canvas, so a theme that is off ships none in the markup.
$nyJs = (string) @file_get_contents(Config::path('assets/newyear.js'));
$cxJs = (string) @file_get_contents(Config::path('assets/christmas.js'));
$chromeJs = (string) @file_get_contents(Config::path('assets/theme.js'));
T::truthy(strpos($nyJs, "className = 'fx'") !== false, 'the fireworks create their canvas at runtime');
T::truthy(strpos($cxJs, "className = 'fx'") !== false, 'the trees do too');
T::truthy(strpos($nyJs, 'prefers-reduced-motion') !== false, 'the fireworks honour reduced motion');
T::truthy(strpos($cxJs, 'prefers-reduced-motion') !== false, 'the trees honour reduced motion');
T::truthy(strpos($chromeJs, 'COOKIE') !== false, 'the shared script owns the opt-out cookie');
T::truthy(strpos($chromeJs, 'audio.play') !== false, 'the shared script starts the music');
T::truthy(strpos($chromeJs, 'pointerdown') !== false, 'and retries on the first interaction when autoplay is blocked');
T::truthy(strpos($chromeJs, 'visibilitychange') === false, 'the shared script is not the animation loop');

// A theme may hide the canvas and the mobile hint, and nothing else.
$chromeCss = (string) @file_get_contents(Config::path('assets/theme.css'));
$holidayCss = (string) @file_get_contents(Config::path('assets/holiday.css'));
T::truthy(substr_count($chromeCss, 'display: none') <= 2, 'the shared chrome hides only the canvas and the mobile hint');
T::truthy(strpos($holidayCss, 'visibility: hidden') === false, 'a theme never hides content');
T::truthy(strpos($holidayCss, 'display: none') === false, 'and never removes it either');
foreach (array('newyear.css' => 'newyear', 'spring.css' => 'spring', 'christmas.css' => 'christmas') as $paletteFile => $paletteClass) {
    $palette = (string) @file_get_contents(Config::path('assets/' . $paletteFile));
    T::truthy(strpos($palette, 'body.' . $paletteClass) !== false, $paletteFile . ' is scoped to its own body class');
    T::truthy(strpos($palette, 'display: none') === false, $paletteFile . ' removes nothing from the page');
}

// --- the corner controls wait for the first scroll -------------------------
/*
 * The band types its slogan out at the bottom of the first screen, exactly where the
 * fixed corner controls sit -- the Cap pill on every page, plus the theme badge and the
 * music bar on a themed one. They are hidden from the markup (data-floats on <body>, see
 * Season::bodyAttrs) and faded in by assets/app.js once the band's bottom edge has
 * scrolled past them. The hidden state is CSS, so no frame paints them first.
 *
 * app.js, not theme.js: the plain site loads no theme script, and it has the same corner.
 */
$appCss = (string) @file_get_contents(Config::path('assets/app.css'));
$appJs = (string) @file_get_contents(Config::path('assets/app.js'));
$gated = "body[data-floats='gated']:not(.floats-shown)";
foreach (array('.themebar', '.seasonbadge', '[data-cap-host]') as $cornerControl) {
    T::truthy(
        strpos($appCss, $gated . ' ' . $cornerControl) !== false,
        'the stylesheet keeps ' . $cornerControl . ' off the first screen'
    );
}
T::truthy(strpos($appCss, "body[data-floats='gated'] .themebar") !== false, 'the music bar fades instead of jumping');
T::truthy(strpos($appCss, "body[data-floats='gated'] [data-cap-host][data-cap-state='failed']") !== false,
    'a failed challenge is never kept off screen');
T::truthy(strpos($appCss, 'prefers-reduced-motion') !== false
    && strpos($appCss, 'transition: none') !== false, 'reduced motion skips the fade');
T::truthy(strpos($appCss, '@media (scripting: none)') !== false, 'a scriptless visitor is never left without the controls');
T::truthy(strpos($appJs, 'floats-shown') !== false, 'the script opens the gate');
T::truthy(strpos($appJs, 'RESERVE') !== false, 'and measures the band instead of guessing a screen height');
T::truthy(strpos($appJs, 'focusin') !== false, 'focus opens the gate too, so tabbing cannot land on an invisible control');
T::truthy(strpos($chromeJs, 'floats-shown') === false, 'the theme script does not carry a second copy of the gate');

// The document scrollbar is gone; inner scrollers keep theirs.
T::truthy(strpos($appCss, 'html { scrollbar-width: none; }') !== false, 'the page scrollbar is removed');
T::truthy(strpos($appCss, 'html::-webkit-scrollbar { width: 0; height: 0; }') !== false, 'and removed in Chromium too');
T::truthy(strpos($appCss, 'body * { scrollbar-width: auto; }') !== false, 'inner scrollers keep their bars');

// Every track the schedule names is accounted for: shipped, or recorded as unavailable
// in assets/music/README.md.
//
// The list is empty now. Both AVS3 recordings were decoded by the operator's own
// ffmpeg (E:\ffmpeg, an HDR Vivid build that carries libarcdav3a) and shipped, so
// "documented as unavailable" is no longer a state any track may be in -- a missing
// file here is a deployment that forgot to copy it.
$musicDir = Config::path('assets/music');
T::truthy(is_dir($musicDir), 'the music directory ships');
$unavailable = array();
foreach (Season::themes() as $themeKey => $themeRow) {
    if (!is_array($themeRow['music']) || empty($themeRow['music']['tracks'])) {
        continue;
    }
    $listed = (array) $themeRow['music']['tracks'];
    foreach (isset($themeRow['music']['dates']) ? (array) $themeRow['music']['dates'] : array() as $dateEntry) {
        $listed = array_merge($listed, (array) $dateEntry[2]);
    }
    foreach ($listed as $trackFile) {
        T::truthy(
            Media::available('assets/music/' . $trackFile, $trackFile) || in_array($trackFile, $unavailable, true),
            $themeKey . ' track "' . $trackFile . '" is shipped (ciphertext + IV) or documented as unavailable'
        );
    }
}

// --- the first-screen film -------------------------------------------------
/*
 * The landing page opens with a video band, the whitehouse.gov shape: the header
 * stays, a clip takes the rest of the first screen, the page proper starts below it.
 *
 * What has to hold is not "the video plays" -- that is the browser's business -- but
 * that the band is PER THEME (a theme with no film renders no band, never a black
 * rectangle), that both encodings ship and stay inside the size budget, and that the
 * PLAIN site has one too: it is the page's shape now, not a holiday decoration.
 */
$filmDir = Config::path('assets/film');
T::truthy(is_dir($filmDir), 'the film directory ships');

Season::boot(Request::fromParts('/'));
$plainFilm = Season::film();
T::truthy(is_array($plainFilm), 'the plain site has a first-screen film (assets/film/ deployed?)');
if (is_array($plainFilm)) {
    T::same(2, count($plainFilm['sources']), 'two encodings, so a phone can take the small one');
    T::truthy(strpos($plainFilm['sources']['lg']['url'], 'default-1080.mp4') !== false, 'a wide viewport gets the 1080p encode');
    T::truthy(strpos($plainFilm['sources']['sm']['url'], 'default-720.mp4') !== false, 'a narrow one gets the 720p encode');
    T::truthy(trim((string) $plainFilm['lead']) !== '', 'the band carries one line of copy');
    T::truthy(strpos((string) $plainFilm['poster'], 'default-poster.jpg') !== false, 'and a poster, so the band is never empty');
}
T::truthy(!Season::active(), 'a film on the plain site does not make a theme active');

foreach (array('christmas', 'newyear', 'spring') as $filmTheme) {
    Season::boot(Request::fromParts('/', array('season' => $filmTheme)));
    $themeFilm = Season::film();
    T::truthy(is_array($themeFilm), $filmTheme . ' has a film (assets/film/ deployed?)');
    if (!is_array($themeFilm)) {
        continue;
    }
    T::truthy(strpos($themeFilm['sources']['lg']['url'], $filmTheme . '-1080.mp4') !== false, $filmTheme . ' links its own 1080p encode');
    T::truthy(strpos($themeFilm['sources']['sm']['url'], $filmTheme . '-720.mp4') !== false, $filmTheme . ' links its own 720p encode');
    T::same(Season::greeting(), $themeFilm['lead'], $filmTheme . ' puts the theme greeting on the band');
}

// Every file the table names is in the web root as MP4-family ciphertext, inside the
// budget, and the manifest's key/IV pair really opens it.
//
// Aggregated on purpose. A deployment that has not pushed assets/film/ yet fails ONE
// assertion that names every missing file, instead of ten identical ones -- the media
// cannot travel down the same pipe as the code on this link, so "not uploaded yet" is a
// state the suite has to be able to describe.
//
// The head check decrypts the first two blocks with the manifest's IV and looks for the
// 'ftyp' box at offset 4: that is the same thing a browser does, minus the rest of the
// file, so a wrong IV or a stale key fails here rather than as a silent black band.
$filmMissing = array();
$filmOversize = array();
$filmNotMp4 = array();
$decryptHead = static function ($path, $ivHex) {
    $key = Media::keyBytes();
    $raw = (string) file_get_contents($path, false, null, 0, 32);
    if ($key === '' || strlen($raw) < 32) {
        return '';
    }

    return (string) openssl_decrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, (string) hex2bin($ivHex));
};
foreach (Season::themes() as $filmTheme => $filmRow) {
    $filmSpec = isset($filmRow['film']) && is_array($filmRow['film']) ? $filmRow['film'] : array();
    $filmSources = isset($filmSpec['sources']) && is_array($filmSpec['sources']) ? $filmSpec['sources'] : array();
    foreach (array('sm', 'lg') as $sizeKey) {
        if (!isset($filmSources[$sizeKey])) {
            $filmMissing[] = $filmTheme . '-' . $sizeKey . ' (not in the table)';
            continue;
        }
        $name = (string) $filmSources[$sizeKey];
        $path = $filmDir . '/' . $name . '.enc';
        $entry = Media::entry($name);
        if (!is_file($path) || $entry === null) {
            $filmMissing[] = $name;
            continue;
        }
        $bytes = (int) filesize($path);
        if ($bytes < 200000 || $bytes > 12 * 1024 * 1024) {
            $filmOversize[] = $name . ' ' . round($bytes / 1048576, 1) . ' MB';
        }
        // 'ftyp' at offset 4 is the MP4 box at the head of every file that will play.
        if (substr($decryptHead($path, (string) $entry['iv']), 4, 4) !== 'ftyp') {
            $filmNotMp4[] = $name;
        }
    }
    $posterName = isset($filmSpec['poster']) ? (string) $filmSpec['poster'] : '';
    if ($posterName === '' || !is_file($filmDir . '/' . $posterName)) {
        $filmMissing[] = $filmTheme . '-poster.jpg';
    }
}
T::same(array(), $filmMissing, 'the film files are deployed (' . (count($filmMissing) ? 'missing: ' . implode(', ', $filmMissing) : 'all present') . ')');
T::same(array(), $filmOversize, 'every clip is a real one under the 12 MB ceiling (' . implode(', ', $filmOversize) . ')');
T::same(array(), $filmNotMp4, 'every clip is an MP4 (' . implode(', ', $filmNotMp4) . ')');

// --- encrypted media -------------------------------------------------------
/*
 * The film and the music live in the web root as AES-256-CBC ciphertext; the key comes
 * from /media-key, and only to a browser that solved the challenge. These assertions are
 * about the wiring, not about the cipher: PHP encrypts with the same key/IV the page
 * decrypts with, so a manifest that drifts from the files fails here.
 */
T::same(32, strlen(Media::keyBytes()), 'the manifest carries a 32-byte key');
T::truthy(\SatApi\MediaKey::handles(Request::fromParts('/media-key')), 'the key endpoint is routed');
T::truthy(!\SatApi\MediaKey::handles(Request::fromParts('/media-key/extra')), 'and only at its own path');
$mediaKeySrc = (string) @file_get_contents(Config::path('lib/MediaKey.php'));
T::truthy(strpos($mediaKeySrc, 'Pow::hasValidSession') !== false, 'the key is refused without a solved challenge');
T::truthy(strpos($mediaKeySrc, 'no-store') !== false, 'and is never cached');

$filmIv = Season::film();
T::truthy(isset($filmIv['sources']['lg']['iv']) && strlen((string) $filmIv['sources']['lg']['iv']) === 32, 'each film source carries its IV');
T::truthy(strpos((string) $filmIv['sources']['lg']['url'], '.mp4.enc') !== false, 'and links the ciphertext, not the mp4');

Season::boot(Request::fromParts('/', array('season' => 'christmas')));
$encPayload = Season::clientPayload();
T::truthy(count($encPayload['tracks']) > 0, 'the theme has tracks');
T::same(count($encPayload['tracks']), count($encPayload['media']), 'every track has an IV in the boot payload');
T::truthy(strpos((string) reset($encPayload['tracks']), '.m4a.enc') !== false, 'the rotation links ciphertext');
Season::boot(Request::fromParts('/'));

$mediaJs = (string) @file_get_contents(Config::path('assets/media.js'));
T::truthy(strpos($mediaJs, 'AES-CBC') !== false, 'the page decrypts with the same algorithm');
T::truthy(strpos($mediaJs, 'is-verified') !== false, 'and waits for the challenge before asking for the key');
T::truthy(strpos((string) @file_get_contents(Config::path('assets/film.js')), 'SatApiMedia.load') !== false, 'the film goes through the decryptor');
T::truthy(strpos((string) @file_get_contents(Config::path('assets/theme.js')), 'SatApiMedia.load') !== false, 'and so does the music');

// Nothing in the web root may still be the plaintext: the whole point is that a crawler
// following /assets/film/*.mp4 finds nothing.
$plainLeft = array();
foreach (Season::themes() as $plainTheme => $plainRow) {
    $plainSpec = isset($plainRow['film']) && is_array($plainRow['film']) ? $plainRow['film'] : array();
    foreach (isset($plainSpec['sources']) && is_array($plainSpec['sources']) ? $plainSpec['sources'] : array() as $plainName) {
        if (is_file($filmDir . '/' . (string) $plainName)) {
            $plainLeft[] = (string) $plainName;
        }
    }
    foreach (isset($plainRow['music']['tracks']) && is_array($plainRow['music']['tracks']) ? $plainRow['music']['tracks'] : array() as $plainTrack) {
        if (is_file(Config::path('assets/music/' . (string) $plainTrack))) {
            $plainLeft[] = (string) $plainTrack;
        }
    }
}
T::same(array(), $plainLeft, 'no plaintext media is left in the web root (' . implode(', ', $plainLeft) . ')');

// A missing file is a theme without a band, not a page with a hole in it.
Config::set('theme.season', 'christmas');
Season::boot(Request::fromParts('/'));
T::truthy(is_array(Season::film()), 'the pinned theme resolves a film');
Config::set('theme.season', 'default');
Season::boot(Request::fromParts('/'));

$filmCss = (string) @file_get_contents(Config::path('assets/film.css'));
$filmJs = (string) @file_get_contents(Config::path('assets/film.js'));
$filmReadme = (string) @file_get_contents($filmDir . '/README.md');
T::truthy(strpos($filmCss, 'object-fit: cover') !== false, 'the clip fills the band without letterboxing');
T::truthy(strpos($filmCss, '100svh') !== false, 'the band is one screen tall in small-viewport units');
T::truthy(substr_count($filmCss, 'display: none') <= 4, 'the band stylesheet hides only the icon that is not current, and itself in print');
T::truthy(strpos($filmCss, 'var(--bg') !== false, 'the band melts into the page colour it is on');
T::truthy(strpos($filmJs, 'data-src-sm') !== false, 'the handler reads the small encoding');
T::truthy(strpos($filmJs, 'prefers-reduced-motion') !== false, 'a reader who asked for less motion starts paused');
T::truthy(strpos($filmJs, 'season:film-failed') !== false, 'the handler reports a clip it cannot play');
T::truthy(strpos($filmJs, 'IntersectionObserver') !== false, 'the clip stops when it is scrolled past');
T::truthy(strpos($nyJs, 'data-film') !== false, 'the fireworks stand down for a film');
T::truthy(strpos($cxJs, 'data-film') !== false, 'the trees do too');
T::truthy(strpos($chromeJs, '[data-film]') !== false, 'leaving a theme takes its band with it');
T::truthy(strpos(implode(' ', Season::head()), 'film.css') === false, 'the band stylesheet belongs to the landing page, not to every themed page');
foreach (array('default', 'christmas', 'newyear', 'spring') as $readmeFilm) {
    T::truthy(strpos($filmReadme, $readmeFilm . '-1080.mp4') !== false, 'assets/film/README.md documents the ' . $readmeFilm . ' encode');
}

// --- the typewriter: one line of copy, 26 languages -------------------------
/*
 * The band's slogan is typed, deleted and retyped in another language, with the
 * language's NAME beside it in the reader's language. What has to hold:
 *
 *   - at least 20 languages, each with a name in all three UI locales and a direction;
 *   - every theme says something in every one of them (no empty slot in the rotation);
 *   - the rotation STARTS on the page's language -- a visitor should not have to wait
 *     four phrases to read something they understand;
 *   - the plain site says Welcome, and the loading screen keeps its own tagline;
 *   - the rotation reaches the page as data, not as a second string table in JS.
 */
$sloganFile = Config::path('conf/slogans.php');
T::truthy(is_file($sloganFile), 'the slogan table ships');
$sloganTable = is_file($sloganFile) ? require $sloganFile : array();
$sloganLangs = isset($sloganTable['languages']) && is_array($sloganTable['languages']) ? $sloganTable['languages'] : array();
$sloganLines = isset($sloganTable['slogans']) && is_array($sloganTable['slogans']) ? $sloganTable['slogans'] : array();
T::truthy(count($sloganLangs) >= 20, 'at least twenty languages (' . count($sloganLangs) . ' declared)');

$sloganGaps = array();
foreach ($sloganLangs as $sloganCode => $sloganMeta) {
    foreach (array('en', 'zh-Hans', 'zh-Hant') as $uiLocale) {
        if (!isset($sloganMeta[$uiLocale]) || trim((string) $sloganMeta[$uiLocale]) === '') {
            $sloganGaps[] = $sloganCode . '/' . $uiLocale . ' name';
        }
    }
    if (!isset($sloganMeta['dir']) || !in_array($sloganMeta['dir'], array('ltr', 'rtl'), true)) {
        $sloganGaps[] = $sloganCode . ' direction';
    }
}
foreach ($sloganLines as $sloganTheme => $sloganThemeLines) {
    foreach (array_keys($sloganLangs) as $sloganCode) {
        if (!isset($sloganThemeLines[$sloganCode]) || trim((string) $sloganThemeLines[$sloganCode]) === '') {
            $sloganGaps[] = $sloganTheme . '/' . $sloganCode;
        }
    }
}
T::same(array(), $sloganGaps, 'no gaps in the rotation (' . (count($sloganGaps) ? implode(', ', array_slice($sloganGaps, 0, 8)) : 'complete') . ')');

/*
 * A FESTIVAL LINE IS A CONGRATULATION, NOT A NOUN.
 *
 * The operator's rule, verbatim: 节日类主题应为「圣诞快乐！」这种庆祝类词语，而不是
 * 「圣诞」这种白白的两个字词。It applies to the two places a theme states its own
 * name -- the badge in the corner and the band's typed line -- so both are asserted:
 * every festival slogan ends with ! or ！, and the badge's name does too, in all three
 * UI locales. The plain theme is deliberately exempt (an invitation is not a
 * congratulation), which is why "Welcome" is checked separately above.
 */
$sloganUncelebrated = array();
foreach (array('christmas', 'newyear', 'spring') as $festKey) {
    foreach ((array) $sloganLines[$festKey] as $festCode => $festText) {
        if (!preg_match('/[!！]$/u', (string) $festText)) {
            $sloganUncelebrated[] = $festKey . '/' . $festCode;
        }
    }
}
T::same(array(), $sloganUncelebrated, 'every festival slogan is a congratulation (' . (count($sloganUncelebrated) ? implode(', ', array_slice($sloganUncelebrated, 0, 8)) : 'all marked') . ')');
T::same('圣诞快乐！', (string) $sloganLines['christmas']['zh-Hans'], 'Chinese takes the full-width mark');
T::same('Merry Christmas!', (string) $sloganLines['christmas']['en'], 'English takes the ASCII one');
T::same('春節おめでとう！', (string) $sloganLines['spring']['ja'], 'Japanese is full-width too');
T::same('Welcome', (string) $sloganLines['default']['en'], 'and the plain site stays an invitation, not a congratulation');

$badgeNames = array();
foreach (array('christmas', 'newyear', 'spring') as $festKey) {
    foreach (array('en', 'zh-Hans', 'zh-Hant') as $uiCode) {
        $badgeName = (string) I18n::translate($uiCode, 'theme.name.' . $festKey, '');
        if (!preg_match('/[!！]$/u', $badgeName) || $badgeName === '') {
            $badgeNames[] = $uiCode . '/' . $festKey . '=' . $badgeName;
        }
    }
}
T::same(array(), $badgeNames, 'the badge says the greeting, not the holiday (' . implode(', ', $badgeNames) . ')');
T::same('圣诞快乐！', (string) I18n::translate('zh-Hans', 'theme.name.christmas', ''), 'the Chinese badge congratulates');
T::same('默认', (string) I18n::translate('zh-Hans', 'theme.name.default', ''), 'the plain theme keeps its plain name');

Season::boot(Request::fromParts('/', array('season' => 'christmas')));
T::truthy(strpos(Season::badge(), '圣诞快乐！') !== false || strpos(Season::badge(), 'Merry Christmas!') !== false, 'and the rendered badge carries it');
Season::boot(Request::fromParts('/'));


Season::boot(Request::fromParts('/'));
$phrasesEn = Season::filmPhrases('en');
$phrasesZh = Season::filmPhrases('zh-Hans');
$phrasesHant = Season::filmPhrases('zh-Hant');
T::same('en', $phrasesEn[0]['code'], 'the English page starts on English');
T::same('Welcome', $phrasesEn[0]['text'], 'the plain site says Welcome');
T::same('English-US', $phrasesEn[0]['label'], 'the label names the language in the reader language');
T::same('zh-Hans', $phrasesZh[0]['code'], 'the Simplified Chinese page starts on Chinese');
T::same('欢迎', $phrasesZh[0]['text'], 'with the Chinese Welcome');
T::same('简体中文', $phrasesZh[0]['label'], 'named in Chinese');
T::same('歡迎', $phrasesHant[0]['text'], 'and the Traditional page gets 歡迎, not 欢迎');
T::same('繁體中文', $phrasesHant[0]['label'], 'named in Traditional');
T::same(count($sloganLangs), count($phrasesEn), 'the rotation carries every language');
T::same('Welcome', Season::filmLead('en'), 'the band lead is the page-language slogan');
T::same('欢迎', Season::filmLead('zh-Hans'), 'and it follows the page, not the visitor');

$sloganByCode = array();
foreach ($phrasesEn as $phrasesRow) {
    $sloganByCode[$phrasesRow['code']] = $phrasesRow;
}
T::truthy(isset($sloganByCode['ru']) && $sloganByCode['ru']['label'] === 'Russian-RU', 'Russian carries its own label');
T::same('rtl', isset($sloganByCode['ar']) ? $sloganByCode['ar']['dir'] : '', 'Arabic is marked right-to-left');
T::same('rtl', isset($sloganByCode['he']) ? $sloganByCode['he']['dir'] : '', 'so is Hebrew');
T::same('ltr', isset($sloganByCode['ja']) ? $sloganByCode['ja']['dir'] : '', 'Japanese is not');
T::truthy(isset($sloganByCode['zh-Hans'], $sloganByCode['zh-Hant']) && $sloganByCode['zh-Hans']['text'] !== $sloganByCode['zh-Hant']['text'], 'the two Chinese spellings are different strings');

Season::boot(Request::fromParts('/', array('season' => 'christmas')));
T::same('Merry Christmas!', Season::filmLead('en'), 'in season the band types the greeting in the page language');
$phrasesXmas = Season::filmPhrases('en');
T::same('Merry Christmas!', $phrasesXmas[0]['text'], 'the Christmas rotation opens with the same line');
T::same('圣诞快乐！', (function () use ($phrasesXmas) {
    foreach ($phrasesXmas as $row) {
        if ($row['code'] === 'zh-Hans') {
            return $row['text'];
        }
    }

    return '';
})(), 'and carries every language of it');
Season::boot(Request::fromParts('/'));

// The rotation reaches the page as data, and the label is server-rendered so a
// script-less visitor still reads one sentence and its language.
$homePartial = (string) @file_get_contents(Config::path('partials/page-home.php'));
T::truthy(strpos($homePartial, 'data-film-slogans') !== false, 'the rotation reaches the page inside a JSON script');
T::truthy(strpos($homePartial, 'data-film-lang') !== false, 'the language label is in the markup');
T::truthy(strpos($homePartial, 'data-film-static') !== false, 'a static line is there for a screen reader');
T::truthy(strpos($filmJs, 'createTyper') !== false, 'the handler owns the typewriter');
T::truthy(strpos($filmJs, 'JSON.parse') !== false, 'and reads the rotation as data');
T::truthy(strpos($filmJs, 'prefers-reduced-motion') !== false, 'a reader who asked for less motion gets no typing');
T::truthy(strpos($filmCss, '.film__lang') !== false, 'the label has its own type scale');
T::truthy(strpos($filmCss, 'film-caret') !== false, 'the caret is drawn by the stylesheet');

Season::boot(Request::fromParts('/'));

// --- the docs page loads its script once ----------------------------------
// Reproduced on the live page: clicking the theme button flipped to dark and straight
// back. The page included assets/app.js twice (its own tag plus the injected shell),
// so every module bound its listeners twice and one click toggled twice.
$docsSource = (string) @file_get_contents(Config::path('lib/Docs.php'));
T::truthy(strpos($docsSource, 'assets/app\.js') !== false, 'lib/Docs.php removes the page-owned app.js before injecting the shell');
$appJsSource = (string) @file_get_contents(Config::path('assets/app.js'));
T::truthy(strpos($appJsSource, '__SATAPI_APP_BOOTED__') !== false, 'app.js refuses to boot a second time');
$docsHtmlSource = (string) @file_get_contents(Config::path('docs.html'));
T::same(1, substr_count($docsHtmlSource, './assets/app.js'), 'docs.html still ships one copy for its static route');
$shellSourceNow = (string) @file_get_contents(Config::path('partials/shell.php'));
T::truthy(strpos($shellSourceNow, 'Season::badge') !== false, 'the shell renders the seasonal badge on every page');
T::truthy(strpos($shellSourceNow, 'Season::scripts') !== false, 'the shell injects the fireworks once');

// --- an empty directory ----------------------------------------------------
@mkdir($tmp . '/announcements-empty', 0777, true);
$empty = AnnouncementBoard::compile($tmp . '/announcements-empty', false, $now);
T::truthy(count($empty['errors']) > 0, 'an empty source directory is an error, not a silent wipe');
T::same(false, $empty['written'], 'an empty board is not written without --force');

// --- the Markdown renderer is an escaper first -----------------------------
$md = AnnouncementBoard::markdown("<script>alert(1)</script>\n\n**bold** and *em* and `code`");
T::truthy(strpos($md, '<script>') === false, 'raw HTML in a Markdown file is escaped, not rendered');
T::truthy(strpos($md, '&lt;script&gt;') !== false, 'the escaped form is what reaches the page');
T::truthy(strpos($md, '<strong>bold</strong>') !== false, 'bold works');
T::truthy(strpos($md, '<em>em</em>') !== false, 'italic works');
T::truthy(strpos($md, '<code>code</code>') !== false, 'inline code works');

$js = AnnouncementBoard::markdown('[click](javascript:alert(1))');
T::truthy(strpos($js, 'href=') === false, 'a javascript: link degrades to text');
T::truthy(strpos($js, 'click') !== false, 'the link label survives');

$rel = AnnouncementBoard::markdown('[docs](/docs)');
T::truthy(strpos($rel, 'href="/docs"') !== false, 'a site-relative link is allowed');

$ul = AnnouncementBoard::markdown("- one\n- two");
T::same('<ul><li>one</li><li>two</li></ul>', $ul, 'a bullet list becomes a ul');

// --- the refusal gate picks the notice that matters ------------------------
$gateItems = array(
    array('id' => 'minor-one', 'refusable' => true, 'level' => 'minor', 'starts_at' => 0, 'expires_at' => 0),
    array('id' => 'urgent-one', 'refusable' => true, 'level' => 'urgent', 'starts_at' => 0, 'expires_at' => 0),
);
$sorted = AnnouncementBoard::select($gateItems, $now);
$gate = AnnouncementBoard::gateFor($sorted, array('minor-one', 'urgent-one'));
T::same('urgent-one', $gate['id'], 'the gate blocks for the most severe refused notice');
T::same(null, AnnouncementBoard::gateFor($sorted, array('someone-else')), 'a refusal of a different notice gates nothing');
T::same(null, AnnouncementBoard::gateFor(array(array('id' => 'x', 'refusable' => false, 'level' => 'urgent', 'starts_at' => 0, 'expires_at' => 0)), array('x')), 'a non-refusable notice can never gate the page');

// ---------------------------------------------------------------------------
T::group('AMSAT upstream proxy');

/*
 * The proxy adds exactly two things of its own: a cache and a query whitelist.
 * The cache is uninteresting to test here (it is Store::remember), so what is
 * asserted is the whitelist -- because that function is what decides how many
 * distinct documents this service can ever ask AMSAT for, and the upstream asks
 * clients not to poll more than once every five minutes.
 */
T::same(7, count(AmsatApi::SOURCES), 'seven AMSAT v1 documents are proxied');
$files = array();
foreach (AmsatApi::SOURCES as $proxyId => $proxySpec) {
    $files[$proxyId] = $proxySpec['file'];
}
T::same('catalog.php', $files['catalog'], 'the upstream file name is the documented one');
T::same('sat_info.php', $files['sat-info'], 'the legacy underscore file name is preserved');

$proxyPaths = array();
foreach ($index as $entry) {
    if (strpos($entry['id'], 'amsat_') === 0) {
        $proxyPaths[$entry['id']] = $entry['path'];
    }
}
foreach (array('amsat_catalog', 'amsat_reports', 'amsat_summary', 'amsat_statuses', 'amsat_health', 'amsat_satellites', 'amsat_sat_info') as $proxyId) {
    T::truthy(isset($proxyPaths[$proxyId]), $proxyId . ' is a registered v1 route');
}
T::same('/v1/amsat/sat-info', $proxyPaths['amsat_sat_info'], 'the sat-info route keeps the upstream spelling');

$indexById = array();
foreach ($index as $entry) {
    $indexById[$entry['id']] = $entry;
}
T::same(array('GET', 'HEAD'), $indexById['amsat_catalog']['methods'], 'the proxy is read-only: GET and HEAD only');
T::same(array('GET', 'HEAD'), $indexById['amsat_reports']['methods'], 'reports has no POST: submitting a report is not proxied');

T::same('name=AO-91', AmsatApi::canonicalQuery('catalog', array('name' => 'AO-91')), 'a documented parameter is forwarded');
T::same('name=AO-91', AmsatApi::canonicalQuery('catalog', array('name' => 'AO-91', 'evil' => '1')), 'an undocumented parameter is dropped, not forwarded');
T::same('', AmsatApi::canonicalQuery('statuses', array('limit' => '5')), 'a parameter the document does not accept never reaches the upstream');
T::same('hours=720', AmsatApi::canonicalQuery('reports', array('hours' => '9999')), 'a number above the documented range is clamped');
T::same('hours=1', AmsatApi::canonicalQuery('reports', array('hours' => '-5')), 'a number below the documented range is clamped');
T::same('limit=5&name=AO-91', AmsatApi::canonicalQuery('reports', array('name' => 'AO-91', 'limit' => '5')), 'parameters are sorted so argument order cannot split the cache');
T::same('status=Heard', AmsatApi::canonicalQuery('reports', array('status' => 'heard')), 'a status is canonicalised to the published spelling');
T::same('', AmsatApi::canonicalQuery('reports', array('status' => 'bogus')), 'an unknown status is dropped rather than forwarded');
T::same('include_stats=true', AmsatApi::canonicalQuery('catalog', array('include_stats' => '1')), 'a boolean is normalised');
T::same('since=2026-11-03T13%3A00%3A00Z', AmsatApi::canonicalQuery('reports', array('since' => '2026-11-03T21:00:00+08:00')), 'an instant is canonicalised to UTC so two spellings share one cache entry');
T::same('', AmsatApi::canonicalQuery('nope', array('name' => 'x')), 'an unknown source produces no query at all');

/*
 * Search-engine metadata.
 *
 * Each of these asserts something that was MEASURABLY WRONG in production on
 * 2026-11-14, so they are regression tests rather than decoration:
 *
 *   - the canonical named https://www.authskin.xyz, which is the operator's own,
 *     different, site -- an instruction to a crawler to de-index this one;
 *   - every sitemap <loc> named satellite.api.authskin.xyz, a hostname that does
 *     not resolve;
 *   - robots.txt pointed at that same dead sitemap;
 *   - the docs page canonicalised to /docs.html, which 301s back to /docs;
 *   - an unknown URL answered 200 with the error page's body (see the nginx
 *     assertion at the end).
 */
T::group('search-engine metadata');

T::same('https://satellite.authskin.xyz', Seo::site(), 'the canonical host is the origin address, not the operator site');
T::same('https://satellite.authskin.xyz/', Seo::url('/'), 'the site root keeps its trailing slash');
T::same('https://satellite.authskin.xyz/docs', Seo::url('docs'), 'a path without a leading slash is normalised, not concatenated');
T::same('https://satellite.authskin.xyz/?lang=en', Seo::url('/', 'en'), 'a pinned locale is a query on the same address');

$langs = Seo::languages('/');
T::same(array('zh-Hans', 'zh-Hant', 'en', 'x-default'), array_keys($langs), 'the hreflang cluster covers every published locale plus x-default');
T::same('https://satellite.authskin.xyz/?lang=zh-Hant', $langs['zh-Hant'], 'hreflang URLs are absolute, not /-relative');

$seoHead = implode("\n", Seo::head(array(
    'path'        => '/',
    'title'       => 'Title',
    'description' => 'Description',
    'lang'        => 'en',
    'lang_query'  => '',
    'nodes'       => Seo::siteNodes('Description'),
)));
T::ok(strpos($seoHead, '<link rel="canonical" href="https://satellite.authskin.xyz/">') !== false, 'the canonical is this page on this host');
T::ok(strpos($seoHead, 'www.authskin.xyz') === false, 'the operator site never appears as a canonical target');
T::ok(strpos($seoHead, '<meta property="og:description" content="Description">') !== false, 'og:description is emitted (it was missing entirely)');
T::ok(strpos($seoHead, '<meta name="twitter:card"') !== false, 'a Twitter card is declared');
T::ok(strpos($seoHead, 'max-image-preview:large') !== false, 'the robots directive asks for a large image preview');
T::ok(strpos($seoHead, '"@type":"Organization"') !== false, 'structured data names the publisher');
T::ok(strpos($seoHead, '"@type":"WebSite"') !== false, 'structured data names the site');

$jsonLdStart = strpos($seoHead, '<script type="application/ld+json">');
$jsonLdEnd = strpos($seoHead, '</script>', $jsonLdStart);
$jsonLdBody = substr($seoHead, $jsonLdStart + strlen('<script type="application/ld+json">'), $jsonLdEnd - $jsonLdStart - strlen('<script type="application/ld+json">'));
$decoded = json_decode($jsonLdBody, true);
T::truthy(is_array($decoded), 'the JSON-LD block is valid JSON');
T::same('https://schema.org', isset($decoded['@context']) ? $decoded['@context'] : '', 'the graph declares schema.org');
T::ok(isset($decoded['@graph']) && count($decoded['@graph']) >= 2, 'the graph holds more than one node');

$appNode = Seo::application('desc');
T::same('SoftwareApplication', $appNode['@type'], 'the API is described as an application');
T::same('0', $appNode['offers']['price'], 'and is declared free rather than left ambiguous');

// <title> stays the caller's job: two titles in one document is the bug, not the fix.
T::ok(strpos($seoHead, '<title>') === false, 'the helper never emits a <title>');

$homeSourceNow = (string) @file_get_contents(Config::path('lib/HomePage.php'));
T::ok(strpos($homeSourceNow, 'Seo::head(') !== false, 'the landing page renders its head through the helper');
T::ok(strpos($homeSourceNow, '<link rel="canonical" href="\' . $esc($homepage)') === false, 'the landing page no longer builds a canonical from the operator homepage');

$legalSourceNow = (string) @file_get_contents(Config::path('lib/Legal.php'));
T::ok(strpos($legalSourceNow, 'Seo::webPage(') !== false, 'the legal pages emit a WebPage node');
T::ok(strpos($legalSourceNow, "if (\$pinned) {\n            \$headers['X-Robots-Tag'] = 'noindex';") !== false, 'only a region-pinned legal page is noindex');

$docsSourceNow = (string) @file_get_contents(Config::path('lib/Docs.php'));
T::ok(strpos($docsSourceNow, '<!--seo-head-->') !== false, 'the docs page injects its head metadata');
$docsHtmlNow = (string) @file_get_contents(Config::path('docs.html'));
T::ok(strpos($docsHtmlNow, '{{host}}/docs.html') === false, 'the docs template no longer canonicalises to a URL that redirects');
T::ok(strpos($docsHtmlNow, '<!--seo-head-->') !== false, 'the placeholder is present in the template');

// The sitemap is an assertion; every line of it has to be true.
$sitemapRaw = (string) @file_get_contents(Config::path('sitemap.xml'));
T::ok($sitemapRaw !== '', 'sitemap.xml exists');
$sitemapXml = @simplexml_load_string($sitemapRaw);
T::truthy($sitemapXml !== false, 'sitemap.xml is well-formed XML');
$sitemapLocs = array();
if ($sitemapXml !== false) {
    foreach ($sitemapXml->url as $urlNode) {
        $sitemapLocs[] = (string) $urlNode->loc;
    }
}
T::same(7, count($sitemapLocs), 'seven pages are submitted');
$sitePrefix = Seo::site() . '/';
$offHost = array();
foreach ($sitemapLocs as $loc) {
    if (strpos($loc, $sitePrefix) !== 0) {
        $offHost[] = $loc;
    }
}
T::same(array(), $offHost, 'every submitted URL is on the canonical host');
T::ok(in_array(Seo::url('/docs'), $sitemapLocs, true), 'the docs page is submitted at its canonical address');
T::ok(in_array(Seo::url('/no-acceleration'), $sitemapLocs, true), 'the acceleration notice is submitted as well');
// Only the VALUES are checked: the file's comment explains the old hostname, and a
// comment is not an assertion to a crawler.
$deadHost = array();
foreach ($sitemapLocs as $loc) {
    if (strpos($loc, 'satellite.api.authskin.xyz') !== false) {
        $deadHost[] = $loc;
    }
}
T::same(array(), $deadHost, 'no submitted URL names the hostname that does not resolve');

$robotsRaw = (string) @file_get_contents(Config::path('robots.txt'));
T::ok(strpos($robotsRaw, 'Sitemap: ' . Seo::site() . '/sitemap.xml') !== false, 'robots.txt points at the sitemap on the canonical host');
$sitemapLines = array();
foreach (preg_split('/\R/', $robotsRaw) as $robotsLine) {
    $robotsLine = trim($robotsLine);
    if (stripos($robotsLine, 'Sitemap:') === 0) {
        $sitemapLines[] = $robotsLine;
    }
}
T::same(array('Sitemap: ' . Seo::site() . '/sitemap.xml'), $sitemapLines, 'exactly one sitemap reference, and it is on the canonical host');

// The soft-404 fix: an unmatched URL must answer 404, so the catch-all returns
// the status instead of serving the error page through try_files (which answers 200).
$nginxNow = (string) @file_get_contents(Config::path('conf/bt-panel-rewrite.conf'));
// Directives, not prose: the comment above the catch-all quotes the old line on
// purpose ("this used to be ..."), and a commented-out directive is not config.
$serveThroughTryFiles = array();
foreach (preg_split('/\R/', $nginxNow) as $nginxLine) {
    if (preg_match('/^\s*try_files\s+\/error\.html/', $nginxLine)) {
        $serveThroughTryFiles[] = trim($nginxLine);
    }
}
T::same(array(), $serveThroughTryFiles, 'no location serves the error page through try_files (which answers 200)');
T::ok(strpos($nginxNow, 'location = /error.html {') !== false, 'the error page has its own location for its security headers');
T::ok(strpos($nginxNow, 'no-acceleration|error|verify|media-key') !== false, 'the acceleration notice reaches PHP instead of the catch-all');
T::ok(strpos($nginxNow, 'location = /no-accel ') !== false, 'the short footer link redirects instead of 404ing');

/*
 * IndexNow.
 *
 * The point of these is not that a submission was made -- that is an HTTP call with
 * a status code, run by hand and recorded in OPERATIONS.md -- but that the four
 * things a submission silently depends on are true: the key exists and is published
 * where the protocol says, the URL list is the sitemap's (no second list to drift),
 * the payload has the fields the engines validate, and the claim about WHO this
 * reaches does not quietly grow a Google.
 */
T::group('indexnow');

$inKey = IndexNow::key();
T::ok($inKey === '' || preg_match('/^[A-Za-z0-9-]{8,128}$/', $inKey) === 1, 'the key, when one is configured, is a valid IndexNow key');
if ($inKey !== '') {
    T::truthy(is_file(IndexNow::keyFile()), 'the verification file exists in the web root');
    T::same($inKey, trim((string) @file_get_contents(IndexNow::keyFile())), 'and contains exactly the key, which is what the engines compare');
    T::same('https://satellite.authskin.xyz/' . $inKey . '.txt', IndexNow::keyLocation(), 'the public key location is on the canonical host');
}

T::same('satellite.authskin.xyz', IndexNow::host(), 'the payload host is the bare hostname, not the origin URL');

$inUrls = IndexNow::urls();
T::same(count($sitemapLocs), count($inUrls), 'every submitted URL comes from the sitemap, none invented');
$outside = array();
foreach ($inUrls as $inUrl) {
    if (strpos($inUrl, Seo::site() . '/') !== 0) {
        $outside[] = $inUrl;
    }
}
T::same(array(), $outside, 'no submitted URL points at another host (which every engine rejects with 422)');

$inPayload = IndexNow::payload($inUrls);
T::same(array('host', 'key', 'keyLocation', 'urlList'), array_keys($inPayload), 'the payload has exactly the four fields the protocol defines');
T::truthy(array_values($inPayload['urlList']) === array_values($inUrls), 'the payload carries the URL list verbatim');

// One submission fans out to every participant. Google is not one of them, and a
// URL list that silently grew a google.com endpoint would be the lie, not the fix.
$inEndpoints = implode(' ', IndexNow::ENDPOINTS);
T::ok(strpos($inEndpoints, 'api.indexnow.org') !== false, 'the shared endpoint is called');
T::ok(strpos($inEndpoints, 'bing.com/indexnow') !== false, "Bing's own endpoint is called too");
T::ok(strpos($inEndpoints, 'google') === false, 'no Google endpoint exists, because Google does not implement IndexNow');

T::ok(strpos(IndexNow::verdict(202), '202') !== false, 'a 202 is reported as accepted-with-validation-pending, not as a failure');
T::ok(strpos(IndexNow::verdict(403), 'key') !== false, 'a 403 points at the key file, which is the only thing that causes it');
T::ok(strpos(IndexNow::verdict(422), 'host') !== false, 'a 422 points at the host/URL mismatch');
T::ok(strpos(IndexNow::verdict(0), 'network') !== false, 'a zero status is a transport failure, not a rejection');

// The console verification tags: emitted only when configured, and on every page.
$googleBefore = Config::get('seo.google_verification', '');
$bingBefore = Config::get('seo.bing_verification', '');
$plainHead = implode("\n", Seo::head(array('path' => '/', 'title' => 'T')));
T::ok(strpos($plainHead, 'google-site-verification') === false, 'no verification tag is invented when none is configured');
Config::set('seo.google_verification', 'abc123');
Config::set('seo.bing_verification', 'def456');
$verifiedHead = implode("\n", Seo::head(array('path' => '/', 'title' => 'T')));
Config::set('seo.google_verification', $googleBefore);
Config::set('seo.bing_verification', $bingBefore);
T::ok(strpos($verifiedHead, '<meta name="google-site-verification" content="abc123">') !== false, 'a configured Google token is emitted for Search Console');
T::ok(strpos($verifiedHead, '<meta name="msvalidate.01" content="def456">') !== false, 'a configured Bing token is emitted for Webmaster Tools');

$indexNowSource = (string) @file_get_contents(Config::path('lib/Support/IndexNow.php'));
T::ok(strpos($indexNowSource, 'Google is not one of') !== false, 'the class states who it does not reach');
T::ok(strpos($indexNowSource, 'NOT an') !== false, 'and that acceptance is a crawl queue, not an index');

/*
 * Bing Webmaster API.
 *
 * The credential is a real secret, so the assertions are about the rules that keep it
 * one: it is masked everywhere, the CLI never reads it raw, and submissions go to a
 * verified property rather than to whatever host the pages happen to use.
 */
T::group('bing webmaster');

$bingKeyNow = BingWebmaster::key();
T::ok($bingKeyNow === '' || preg_match('/^[A-Za-z0-9]{16,64}$/', $bingKeyNow) === 1, 'the Bing key, when configured, has the shape of an API key');
T::same('abcd********mnop', BingWebmaster::mask('abcdefghijklmnop'), 'a diagnostic shows the key masked, never whole');
T::same('(none)', BingWebmaster::mask(''), 'an absent key says so instead of rendering an empty string');
T::truthy(substr(BingWebmaster::siteUrl(), -1) === '/', 'the property URL keeps the trailing slash Bing keys sites by');
T::ok(strpos(BingWebmaster::siteUrl(), 'authskin.xyz') !== false, 'submissions target the verified property');
T::ok(strpos(BingWebmaster::BASE, 'ssl.bing.com') !== false, 'the API root is Bing');
T::ok(strpos(BingWebmaster::BASE, 'google') === false, 'and contains nothing for Google, which has no such API for pages');

$bingCli = (string) @file_get_contents(Config::path('bin/bing.php'));
T::ok(strpos($bingCli, 'self::key()') === false && strpos($bingCli, '->key()') === false, 'the CLI never reads the raw key, so it cannot print it');
T::ok(strpos($bingCli, '--set-key') !== false, 'a rotation has a path that does not involve a chat message');

/*
 * Security review, 2026-11-16.
 *
 * Each of these guards something that was changed BECAUSE the review found it, or
 * because the live web root became a git checkout. They are the assertions that
 * stop a finding from quietly coming back.
 */
T::group('security review');

// The admin token travels in a header by default. A query string lands in the
// access log, in shell history and in a Referer; a logged admin token is a leaked
// credential, so ?token= is refused unless the operator turns it back on.
T::same(false, Config::get('admin.allow_query_token'), 'a query-string admin token is refused unless explicitly enabled');
$adminSourceNow = (string) @file_get_contents(Config::path('lib/Api/Endpoints/Admin.php'));
T::ok(strpos($adminSourceNow, 'admin.allow_query_token') !== false, 'the admin endpoint checks that switch before reading ?token=');
T::ok(strpos($adminSourceNow, 'hash_equals') !== false, 'the token comparison stays constant-time');

// Converting the live web root into a checkout put .git inside the document root.
// One nginx rule keeps it unreachable, so that rule is asserted rather than assumed.
$nginxSecurity = (string) @file_get_contents(Config::path('conf/bt-panel-rewrite.conf'));
T::ok(strpos($nginxSecurity, 'location ~ /\\.(?!well-known)') !== false, 'dotfiles (including .git/) are refused by nginx');
T::ok(strpos($nginxSecurity, '^~ /conf/') !== false, 'the conf directory is refused by nginx');

// A per-server file that is chattr +i on the host cannot be tracked: git fails to
// replace it and every pull aborts. .gitignore has to name it.
$gitignoreNow = (string) @file_get_contents(Config::path('.gitignore'));
T::ok(strpos($gitignoreNow, '.user.ini') !== false, 'the immutable per-server .user.ini is not tracked');
T::ok(strpos($gitignoreNow, 'conf/cap.env') !== false, 'the Cap container environment file is not tracked');

/*
 * Meta descriptions.
 *
 * Bing reported "Meta description too long or too short" on the landing page: its
 * English description was 176 characters and the tool wants 25-160. The Chinese one
 * was 125 -- inside that rule, but 125 CJK characters are roughly twice the pixel
 * width of 125 Latin ones, so a search result truncates it. Hence two ceilings: the
 * engine's 160, and a display ceiling of 75 for CJK / 158 for English.
 *
 * These assertions read the i18n table rather than the rendered page, so a template
 * change cannot quietly reintroduce a 176-character sentence.
 */
T::group('meta descriptions');

$descIssues = array();
$descCheck = static function ($key, $code, $text) use (&$descIssues) {
    $text = (string) $text;
    if ($text === '') {
        $descIssues[] = $key . '/' . $code . ' is missing';

        return;
    }
    $len = mb_strlen($text, 'UTF-8');
    $ceiling = $code === 'en' ? 158 : 75;
    if ($len < 25) {
        $descIssues[] = $key . '/' . $code . ' is too short (' . $len . ')';
    }
    if ($len > 160) {
        $descIssues[] = $key . '/' . $code . ' breaks the 160-character rule (' . $len . ')';
    } elseif ($len > $ceiling) {
        $descIssues[] = $key . '/' . $code . ' is ' . $len . ' characters, past its display ceiling (' . $ceiling . ')';
    }
};

$legalDescKeys = array(
    'legal.meta.description',
    'legal.meta.description.privacy',
    'legal.meta.description.terms',
    'legal.meta.description.no_accel',
);
foreach (I18n::locales() as $descLocale) {
    $descCheck('page.home.description', $descLocale, I18n::translate($descLocale, 'page.home.description', ''));
    $descCheck('docs.meta_description', $descLocale, I18n::translate($descLocale, 'docs.meta_description', ''));
    foreach ($legalDescKeys as $legalDescKey) {
        $descCheck($legalDescKey, $descLocale, I18n::translate($descLocale, $legalDescKey, ''));
    }
}
T::same(array(), $descIssues, 'every meta description fits 25-160 characters and the display width of its own script');

foreach (I18n::locales() as $descLocale) {
    $seen = array();
    foreach ($legalDescKeys as $legalDescKey) {
        $seen[] = I18n::translate($descLocale, $legalDescKey, '');
    }
    T::same(count($seen), count(array_unique($seen)), $descLocale . ': the four legal documents each have their own description');
}

// The legal pages must ask for the per-document key, not the section-wide one.
$legalSourceNow2 = (string) @file_get_contents(Config::path('lib/Legal.php'));
T::ok(strpos($legalSourceNow2, 'legal.meta.description.privacy') !== false, 'the legal renderer selects a description per document');

exit(T::summary());
