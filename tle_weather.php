<?php
/**
 * Legacy endpoint: /tle/weather  (previously tle_weather.php).
 * New integrations should use /v1/tle/weather.
 */

define('SATAPI_LEGACY_ROUTE', 'tle_weather');
require __DIR__ . '/index.php';
