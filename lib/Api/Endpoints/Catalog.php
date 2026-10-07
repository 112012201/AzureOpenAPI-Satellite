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
 * SatNOGS catalogue endpoints.
 *
 * GET /v1/satellites     - satellite metadata
 * GET /v1/transmitters   - transmitter metadata (the upstream document is
 *                          ~4 MB, so /v1 returns a projected, paginated view
 *                          while the legacy route keeps the full document).
 */
final class Catalog extends Base
{
    /** Default projections keep payloads small; `fields=all` returns everything. */
    const DEFAULT_FIELDS = array(
        'satnogs_satellites' => array(
            'sat_id', 'norad_cat_id', 'name', 'names', 'status', 'launched', 'operator', 'countries', 'updated',
        ),
        'satnogs_transmitters' => array(
            'uuid', 'norad_cat_id', 'description', 'mode', 'baud', 'type', 'status', 'alive',
            'uplink_low', 'downlink_low', 'updated',
        ),
    );

    const MAX_PER_PAGE = 1000;

    public static function satellites(Request $request, array $params, array $route)
    {
        self::serve($request, $route, 'satnogs_satellites', array('name', 'names', 'operator', 'countries', 'sat_id'));
    }

    public static function transmitters(Request $request, array $params, array $route)
    {
        self::serve($request, $route, 'satnogs_transmitters', array('description', 'mode', 'type', 'uuid'));
    }

    private static function serve(Request $request, array $route, $datasetId, array $searchFields)
    {
        $maintenanceKey = $datasetId;
        Maintenance::enforce($maintenanceKey, $request);

        $legacy = !empty($route['legacy']);
        if (!$legacy) {
            $format = $request->format(array('json'));
            if ($format === null) {
                Problem::send(400, 'Bad Request', 'This endpoint only serves application/json.', 'bad_request', array('parameter' => 'format'));
            }
        }

        $source = Config::source($datasetId);
        $result = Repository::dataset($datasetId);

        if (empty($result['data'])) {
            Problem::send(
                503,
                'Service Unavailable',
                'Upstream "' . $datasetId . '" is unavailable and no cached copy exists.',
                'upstream_unavailable',
                array('source' => array(
                    'id'    => $datasetId,
                    'label' => isset($source['label']) ? $source['label'] : $datasetId,
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

        // Legacy contract: the complete upstream array, unchanged.
        if ($legacy) {
            Response::json(array_values($result['data']), 200, $headers, $deferred);

            return;
        }

        $rows = $result['data'];
        $rows = Collection::where($rows, 'norad_cat_id', $request->list('norad_cat_id'));
        $rows = Collection::where($rows, 'uuid', $request->list('uuid'));
        $rows = Collection::where($rows, 'sat_id', $request->list('sat_id'));
        foreach (array('mode', 'status', 'type', 'alive', 'operator') as $field) {
            $value = $request->param($field, null);
            if ($value !== null && $value !== '') {
                $rows = Collection::where($rows, $field, $value);
            }
        }
        $search = (string) $request->param('search', '');
        if ($search !== '') {
            $rows = Collection::search($rows, $search, $searchFields);
        }
        $since = (string) $request->param('since', '');
        if ($since !== '') {
            $sinceTs = strtotime($since);
            if ($sinceTs === false) {
                Problem::send(400, 'Bad Request', 'Parameter "since" must be an ISO-8601 timestamp or a Unix timestamp.', 'bad_request', array('parameter' => 'since'));
            }
            $rows = array_values(array_filter($rows, static function ($row) use ($sinceTs) {
                if (!isset($row['updated'])) {
                    return false;
                }
                $ts = strtotime((string) $row['updated']);

                return $ts !== false && $ts >= $sinceTs;
            }));
        }

        $sort = (string) $request->param('sort', '');
        if ($sort !== '') {
            $rows = Collection::sort($rows, $sort, (string) $request->param('order', 'asc'));
        }

        $fields = self::resolveFields($request, $datasetId);
        $rows = Collection::project($rows, $fields);

        $total = count($rows);
        $pagination = null;
        if (!$request->bool('all', false)) {
            $pagination = Collection::paginate($rows, $request->int('page', 1, 1), $request->int('per_page', 100, 1, self::MAX_PER_PAGE));
            $rows = $pagination['items'];
        }

        $meta = self::envelopeMeta($request, array(
            'count'   => count($rows),
            'total'   => $total,
            'source'  => array(
                'id'          => $datasetId,
                'label'       => isset($source['label']) ? $source['label'] : $datasetId,
                'url'         => $source['url'],
                'attribution' => isset($source['attribution']) ? $source['attribution'] : '',
                'cache'       => $result['cache']['status'],
                'age_seconds' => $result['cache']['age'],
                'ttl'         => $ttl,
            ),
            'fields'  => $fields === null ? 'all' : $fields,
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
                $meta['pagination']['next'] = self::nextLink($request, $pagination['page'] + 1, $pagination['per_page']);
            }
        }

        $headers['ETag'] = self::etag(array(
            'v'        => Config::get('service.version'),
            'dataset'  => $datasetId,
            'stamp'    => isset($result['cache']['stored_at']) ? $result['cache']['stored_at'] : 0,
            'page'     => $pagination === null ? 'all' : $pagination['page'],
            'total'    => $total,
            'rows'     => $rows,
        ));

        self::respondData($request, $rows, $meta, 200, $headers, $deferred);
    }

    /**
     * @return array<int,string>|null null means "all fields"
     */
    private static function resolveFields(Request $request, $datasetId)
    {
        $raw = trim((string) $request->param('fields', ''));
        if ($raw === '' || $raw === 'all' || $raw === '*') {
            if ($raw === '') {
                return self::DEFAULT_FIELDS[$datasetId];
            }

            return null;
        }

        $fields = array();
        foreach (explode(',', $raw) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]{1,40}$/', $field)) {
                Problem::send(400, 'Bad Request', 'Invalid field name "' . $field . '". Field names must match [a-z0-9_].', 'bad_request', array('parameter' => 'fields'));
            }
            $fields[] = $field;
        }
        if (count($fields) > 30) {
            Problem::send(400, 'Bad Request', 'At most 30 fields may be requested.', 'bad_request', array('parameter' => 'fields'));
        }

        return $fields;
    }

    private static function nextLink(Request $request, $page, $perPage)
    {
        $query = $request->all();
        $query['page'] = $page;
        $query['per_page'] = $perPage;

        return $request->path() . '?' . http_build_query($query);
    }
}
