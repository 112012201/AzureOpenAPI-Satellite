<?php
/**
 * Legacy endpoint: /tle  (previously implemented inline in this file).
 *
 * Kept as a thin shim so existing nginx/Apache rewrite rules and cron entries
 * keep working. New integrations should use /v1/tle and /docs.html.
 */

define('SATAPI_LEGACY_ROUTE', 'tle_merge');
require __DIR__ . '/index.php';
