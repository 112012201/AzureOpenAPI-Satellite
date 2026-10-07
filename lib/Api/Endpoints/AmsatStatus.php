<?php

namespace SatApi\Api\Endpoints;

use SatApi\Config;
use SatApi\Data\Collection;
use SatApi\Data\Repository;
use SatApi\Http\Request;
use SatApi\Http\Response;
use SatApi\Support\Maintenance;
use SatApi\Support\Problem;

/**
 * AMSAT live status reports.
 *
 * GET /v1/amsat/status   paginated, filterable JSON
 * GET /amsat/status      legacy envelope (complete list, "Y-m-d H:i:s" times)
 */
final class AmsatStatus extends Base
{
    const MAX_PER_PAGE = 1000;

    public static function handle(Request $request, array $params, array $route)
    {
        Maintenance::enforce('amsat_status', $request);

        $source = Config::source('amsat_status');
        $result = Repository::amsatReports();

        if (empty($result['data'])) {
            Problem::send(
                503,
                'Service Unavailable',
                'The AMSAT status page is unavailable and no cached report set exists.',
                'upstream_unavailable',
                array('source' => array(
                    'id'    => 'amsat_status',
                    'label' => isset($source['label']) ? $source['label'] : 'AMSAT Status Reports',
                    'error' => $result['error'],
                )),
                array('Retry-After' => '120', 'Cache-Control' => 'no-store')
            );
        }

        $ttl = (int) $source['ttl'];
        $headers = self::cacheHeaders($result['cache'], $ttl, $route);
        if (!empty($result['cache']['stored_at'])) {
            $headers['Last-Modified'] = (int) $result['cache']['stored_at'];
        }
        $deferred = self::deferred(isset($result['refresh']) ? array($result['refresh']) : array());

        $reports = $result['data'];

        if (!empty($route['legacy'])) {
            $legacyRows = array();
            foreach ($reports as $report) {
                $legacyRows[] = array(
                    'status'    => $report['status'],
                    'callsign'  => $report['callsign'],
                    'grid'      => $report['grid'],
                    'timestamp' => gmdate('Y-m-d H:i:s', $report['timestamp_unix']),
                    'satellite' => $report['satellite'],
                    'norad_id'  => $report['norad_id'],
                );
            }
            Response::json(array(
                'status'        => 'success',
                'generated'     => gmdate('Y-m-d H:i:s') . ' UTC',
                'total_reports' => count($legacyRows),
                'data'          => $legacyRows,
            ), 200, $headers, $deferred);

            return;
        }

        foreach (array('satellite', 'callsign', 'status') as $field) {
            $value = $request->list($field);
            if (!empty($value)) {
                $reports = Collection::where($reports, $field, $value);
            }
        }

        $since = (string) $request->param('since', '');
        if ($since !== '') {
            $sinceTs = is_numeric($since) ? (int) $since : strtotime($since);
            if ($sinceTs === false) {
                Problem::send(400, 'Bad Request', 'Parameter "since" must be an ISO-8601 timestamp or a Unix timestamp.', 'bad_request', array('parameter' => 'since'));
            }
            $reports = array_values(array_filter($reports, static function ($report) use ($sinceTs) {
                return $report['timestamp_unix'] >= $sinceTs;
            }));
        }

        $until = (string) $request->param('until', '');
        if ($until !== '') {
            $untilTs = is_numeric($until) ? (int) $until : strtotime($until);
            if ($untilTs === false) {
                Problem::send(400, 'Bad Request', 'Parameter "until" must be an ISO-8601 timestamp or a Unix timestamp.', 'bad_request', array('parameter' => 'until'));
            }
            $reports = array_values(array_filter($reports, static function ($report) use ($untilTs) {
                return $report['timestamp_unix'] <= $untilTs;
            }));
        }

        $rows = array();
        foreach ($reports as $report) {
            $rows[] = array(
                'status'         => $report['status'],
                'callsign'       => $report['callsign'],
                'grid'           => $report['grid'],
                'satellite'      => $report['satellite'],
                'norad_id'       => $report['norad_id'],
                'timestamp'      => $report['timestamp'],
                'timestamp_unix' => $report['timestamp_unix'],
            );
        }

        $total = count($rows);
        $pagination = null;
        if (!$request->bool('all', false)) {
            $pagination = Collection::paginate($rows, $request->int('page', 1, 1), $request->int('per_page', 100, 1, self::MAX_PER_PAGE));
            $rows = $pagination['items'];
        }

        $meta = self::envelopeMeta($request, array(
            'count'       => count($rows),
            'total'       => $total,
            'source'      => array(
                'id'          => 'amsat_status',
                'label'       => isset($source['label']) ? $source['label'] : 'AMSAT Status Reports',
                'url'         => $source['url'],
                'attribution' => isset($source['attribution']) ? $source['attribution'] : '',
                'cache'       => $result['cache']['status'],
                'age_seconds' => $result['cache']['age'],
                'ttl'         => $ttl,
            ),
            'warnings'    => isset($result['warnings']) ? $result['warnings'] : array(),
        ));
        if ($pagination !== null) {
            $meta['pagination'] = array(
                'page'        => $pagination['page'],
                'per_page'    => $pagination['per_page'],
                'total'       => $pagination['total'],
                'total_pages' => $pagination['total_pages'],
                'has_more'    => $pagination['has_more'],
            );
            if ($pagination['has_more']) {
                $query = $request->all();
                $query['page'] = $pagination['page'] + 1;
                $query['per_page'] = $pagination['per_page'];
                $meta['pagination']['next'] = $request->path() . '?' . http_build_query($query);
            }
        }

        $headers['ETag'] = self::etag(array(
            'v'     => \SatApi\Config::get('service.version'),
            'set'   => 'amsat_status',
            'stamp' => isset($result['cache']['stored_at']) ? $result['cache']['stored_at'] : 0,
            'page'  => $pagination === null ? 'all' : $pagination['page'],
            'total' => $total,
            'rows'  => $rows,
        ));

        Response::json(self::envelope($rows, $meta), 200, $headers, $deferred);
    }
}
