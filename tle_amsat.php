<?php
/**
 * Legacy endpoint: /tle/amsat  (previously tle_amsat.php).
 * New integrations should use /v1/tle/amsat.
 */

define('SATAPI_LEGACY_ROUTE', 'tle_amsat');
require __DIR__ . '/index.php';
