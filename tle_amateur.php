<?php
/**
 * Legacy endpoint: /tle/amateur  (previously tle_amateur.php).
 * New integrations should use /v1/tle/amateur.
 */

define('SATAPI_LEGACY_ROUTE', 'tle_amateur');
require __DIR__ . '/index.php';
