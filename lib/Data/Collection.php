<?php

namespace SatApi\Data;

/**
 * Array helpers for list endpoints: filtering, projection, sorting and
 * cursor-free pagination (the previous API always returned the complete
 * upstream payload - up to 4 MB for SatNOGS transmitters).
 */
final class Collection
{
    /**
     * Keep only the requested fields, always preserving the key order of the
     * projection list so the JSON stays stable for CDN caching.
     *
     * @param array<int,array>  $rows
     * @param array<int,string>|null $fields null or empty means "keep everything"
     * @return array<int,array>
     */
    public static function project(array $rows, $fields)
    {
        if (empty($fields)) {
            return $rows;
        }

        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = array();
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $item[$field] = $row[$field];
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Case-insensitive substring search over the listed fields.
     *
     * @param array<int,array> $rows
     * @param string[]         $fields
     * @return array<int,array>
     */
    public static function search(array $rows, $needle, array $fields)
    {
        $needle = trim((string) $needle);
        if ($needle === '') {
            return $rows;
        }
        $needle = strtolower($needle);

        return array_values(array_filter($rows, static function ($row) use ($needle, $fields) {
            foreach ($fields as $field) {
                if (isset($row[$field]) && is_scalar($row[$field]) && strpos(strtolower((string) $row[$field]), $needle) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Equality filter on a field (accepts a list of allowed values).
     *
     * @param array<int,array> $rows
     * @param mixed            $value
     * @return array<int,array>
     */
    public static function where(array $rows, $field, $value)
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return $rows;
        }
        $allowed = is_array($value) ? array_map('strval', $value) : array((string) $value);

        return array_values(array_filter($rows, static function ($row) use ($field, $allowed) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            $candidate = $row[$field];
            if (is_bool($candidate)) {
                $candidate = $candidate ? 'true' : 'false';
            }

            return in_array((string) $candidate, $allowed, true);
        }));
    }

    /**
     * @param array<int,array> $rows
     * @param string           $key
     * @param string           $direction asc|desc
     * @return array<int,array>
     */
    public static function sort(array $rows, $key, $direction = 'asc')
    {
        $key = (string) $key;
        if ($key === '') {
            return $rows;
        }
        $descending = strtolower((string) $direction) === 'desc';

        usort($rows, static function ($a, $b) use ($key, $descending) {
            $av = array_key_exists($key, $a) ? $a[$key] : null;
            $bv = array_key_exists($key, $b) ? $b[$key] : null;
            if (is_numeric($av) && is_numeric($bv)) {
                $cmp = ($av <=> $bv);
            } else {
                $cmp = strcmp((string) $av, (string) $bv);
            }

            return $descending ? -$cmp : $cmp;
        });

        return $rows;
    }

    /**
     * @param array<int,mixed> $rows
     * @return array{items:array,page:int,per_page:int,total:int,total_pages:int,has_more:bool}
     */
    public static function paginate(array $rows, $page, $perPage)
    {
        $total = count($rows);
        $perPage = max(1, (int) $perPage);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $page = max(1, (int) $page);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $items = array_slice(array_values($rows), $offset, $perPage);

        return array(
            'items'       => $items,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'has_more'    => $page < $totalPages,
        );
    }

    /**
     * Reduce TLE records to the requested NORAD ids.
     *
     * @param array<string,array> $records
     * @param string[]            $noradIds
     * @return array<string,array>
     */
    public static function onlyNorad(array $records, array $noradIds)
    {
        if (empty($noradIds)) {
            return $records;
        }
        $wanted = array();
        foreach ($noradIds as $id) {
            $normalized = \SatApi\Tle\Parser::normalizeNorad($id);
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }
        if (empty($wanted)) {
            return $records;
        }

        return array_intersect_key($records, $wanted);
    }
}
