<?php
/**
 * Legacy compatibility layer.
 *
 * The historical script set (tle_merge.php, health.php, ...) included this
 * file for shared helpers. The implementation now lives in lib/, but these
 * function names keep working for any external tooling.
 *
 * Security changes vs. the previous version:
 *   - the AES credential check no longer falls back to a hard-coded key; if
 *     AES_KEY / AES_IV are not configured, verification fails closed,
 *   - Redis access uses the shared, time-bounded connection from the cache
 *     layer (1s timeout) instead of a new unbounded connection per call.
 */

use SatApi\Cache\Store;
use SatApi\Support\Maintenance;

require_once __DIR__ . '/bootstrap.php';

/**
 * @return Redis|false
 */
function get_redis_connection()
{
    $client = Store::redisClient();

    return $client === false ? false : $client;
}

/**
 * Legacy guard: halts the request when the endpoint is in maintenance.
 *
 * @param string $script_key
 */
function check_maintenance($script_key)
{
    $request = SatApi\Http\Request::capture();
    SatApi\Http\Response::bind($request);
    SatApi\Support\Problem::bind($request);
    SatApi\Support\Problem::setTextPreferred(true);
    Maintenance::enforce($script_key, $request);
}

/**
 * @param string $message
 * @param string $script_key
 * @return string
 */
function getMaintenanceHtml($message, $script_key)
{
    return Maintenance::html($message, $script_key);
}

/**
 * @param string $script_key
 * @param bool   $enable
 * @param string $message
 * @return bool
 */
function set_maintenance_status($script_key, $enable, $message = '')
{
    return Maintenance::set($script_key, (bool) $enable, (string) $message);
}

/**
 * @return array<string,array>
 */
function get_all_maintenance_status()
{
    $out = array();
    foreach (Maintenance::all() as $id => $state) {
        $out[$id] = array('enabled' => (bool) $state['enabled'], 'message' => (string) $state['message']);
    }

    return $out;
}

/**
 * Verify an AES-256-CBC ticket issued by the operator tooling.
 *
 * Format (unchanged): base64( openssl_encrypt("<timestamp>|<nonce>") ).
 *
 * @param string $encrypted_data
 * @return bool
 */
function verify_ticket($encrypted_data)
{
    $key = getenv('AES_KEY');
    $iv = getenv('AES_IV');
    if ($key === false || $key === '' || $iv === false || $iv === '' || strlen($iv) !== 16) {
        error_log('[satapi] verify_ticket called without a configured AES_KEY/AES_IV');

        return false;
    }

    $decoded = base64_decode((string) $encrypted_data, true);
    if ($decoded === false) {
        return false;
    }
    if (!function_exists('openssl_decrypt')) {
        error_log('[satapi] verify_ticket requires the openssl extension');

        return false;
    }
    $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return false;
    }
    $parts = explode('|', $decrypted);
    if (count($parts) !== 2) {
        return false;
    }
    $timestamp = (int) $parts[0];
    if ($timestamp <= 0 || (time() - $timestamp) > 3600) {
        return false;
    }

    return true;
}
