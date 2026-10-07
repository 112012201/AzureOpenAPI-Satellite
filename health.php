<?php
/**
 * Legacy endpoint: /health and /status (previously health.php).
 * New integrations should use /v1/health, /v1/health/ready and /v1/status.
 */

define('SATAPI_LEGACY_ROUTE', 'health');
require __DIR__ . '/index.php';
