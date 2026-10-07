<?php
/**
 * Legacy endpoint: /tle/satnogs  (previously tle_satnogs.php).
 * New integrations should use /v1/tle/satnogs.
 */

define('SATAPI_LEGACY_ROUTE', 'tle_satnogs');
require __DIR__ . '/index.php';
