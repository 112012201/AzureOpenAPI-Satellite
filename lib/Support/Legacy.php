<?php

namespace SatApi\Support;

use SatApi\Config;

/**
 * Helpers for the frozen pre-v1 surface.
 *
 * RFC 9745 (Deprecation) + RFC 8594 (Sunset) + RFC 8288 (Link) tell clients
 * exactly what is going away and where to migrate, instead of silently
 * changing payload shapes under them.
 */
final class Legacy
{
    public static function isLegacy(array $route)
    {
        return !empty($route['legacy']);
    }

    /**
     * @return array<string,string>
     */
    public static function headers(array $route)
    {
        if (!self::isLegacy($route)) {
            return array();
        }

        $headers = array(
            'Deprecation' => 'true',
        );

        if (!empty($route['successor'])) {
            $headers['Link'] = '<' . $route['successor'] . '>; rel="successor-version"';
        }

        $sunset = (string) Config::get('legacy.sunset', '');
        if ($sunset !== '') {
            $headers['Sunset'] = $sunset;
        }

        return $headers;
    }

    /**
     * Merge legacy headers into a header array when legacy warnings are on.
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public static function apply(array $headers, array $route)
    {
        if (!(bool) Config::get('legacy.warn', true)) {
            return $headers;
        }

        foreach (self::headers($route) as $name => $value) {
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
