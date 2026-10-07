<?php
/**
 * Development router for the PHP built-in server:
 *
 *   php -S 127.0.0.1:8080 router.php
 *
 * It mirrors what the nginx configuration does in production: serve existing
 * static files as-is, send everything else through the front controller.
 */

$root = __DIR__;
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$file = realpath($root . $path);

// Static assets that exist on disk are served by the built-in server itself.
if ($file !== false
    && strpos($file, $root) === 0
    && is_file($file)
    && !preg_match('/\.php$/', $file)
    && basename($file) !== 'router.php'
) {
    return false;
}

// Directory request with an index.html (e.g. "/").
if ($path === '/' || substr($path, -1) === '/') {
    $index = realpath($root . rtrim($path, '/') . '/index.html');
    if ($index !== false && is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);

        return true;
    }
}

require $root . '/index.php';

return true;
