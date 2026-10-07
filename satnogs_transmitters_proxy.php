<?php
/**
 * Legacy endpoint: /satnogs/transmitters (previously satnogs_transmitters_proxy.php).
 * New integrations should use /v1/transmitters.
 */

define('SATAPI_LEGACY_ROUTE', 'satnogs_transmitters');
require __DIR__ . '/index.php';
