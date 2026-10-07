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
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Maintenance;
use SatApi\Support\Metrics;
use SatApi\Support\Problem;
use SatApi\Support\RateLimit;
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

// Isolate every writable location inside tests/tmp.
if (is_dir($tmp)) {
    foreach ((array) glob($tmp . '/*') as $path) {
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
T::same(12, count($index), 'twelve v1 routes are indexed');
$paths = array();
foreach ($index as $entry) {
    $paths[] = $entry['path'];
}
T::truthy(in_array('/v1/tle', $paths, true), '/v1/tle indexed');
T::truthy(in_array('/v1/tle/{source}', $paths, true), 'source route indexed with its placeholder');
T::truthy(in_array('/v1/health/ready', $paths, true), 'readiness probe indexed');
$legacy = \SatApi\Api\Router::legacyRoutes();
T::same(10, count($legacy), 'ten legacy route aliases are registered');
T::truthy(isset($legacy['tle_weather']), 'legacy tle_weather alias present');
T::same('/v1/tle/weather', $legacy['tle_weather']['route']['successor'], 'legacy alias points at its v1 successor');
T::same('weather', $legacy['tle_weather']['params']['source'], 'legacy alias carries its route parameter');

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

T::same('2.2.0', Config::get('service.version'), 'service version exposed');
T::truthy(count(Config::sources()) >= 7, 'seven upstream sources configured');
T::same(10800, Config::get('sources.weather.ttl'), 'CelesTrak cache TTL preserved (3h)');
T::same(43200, Config::get('sources.amsat.ttl'), 'AMSAT cache TTL preserved (12h)');
T::same(21600, Config::get('sources.satnogs_transmitters.ttl'), 'SatNOGS catalogue TTL preserved (6h)');
T::same('07530', Config::get('norad_fallback.AO-7'), 'AO-7 NORAD id correct');
T::same('43017', Config::get('norad_fallback.AO-91'), 'AO-91 NORAD id corrected (was 43770)');
T::same('14781', Config::get('norad_fallback.UO-11'), 'UO-11 NORAD id corrected (was 07530)');
T::truthy(Config::get('http.verify_tls') === true, 'TLS verification is enabled by default');
T::same(false, Config::get('cache.redis.enabled'), 'runtime config overrides are visible to the application');

exit(T::summary());
