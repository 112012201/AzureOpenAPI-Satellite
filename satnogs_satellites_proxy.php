<?php
/**
 * Legacy endpoint: /satnogs/satellites (previously satnogs_satellites_proxy.php).
 * New integrations should use /v1/satellites.
 */

define('SATAPI_LEGACY_ROUTE', 'satnogs_satellites');
require __DIR__ . '/index.php';
