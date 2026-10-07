<?php
/**
 * Legacy endpoint: /amsat/status (previously amsat_status_proxy.php).
 * New integrations should use /v1/amsat/status.
 */

define('SATAPI_LEGACY_ROUTE', 'amsat_status');
require __DIR__ . '/index.php';
