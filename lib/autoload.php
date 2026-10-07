<?php
/**
 * Minimal PSR-4 style autoloader.
 *
 * Maps the `SatApi\` namespace onto this directory so the project can be
 * deployed without Composer / vendor/ on shared hosting.
 *
 * PHP 7.4+ compatible, no external dependencies.
 */

spl_autoload_register(static function ($class) {
    $prefix = 'SatApi\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    if ($relative === false || $relative === '') {
        return;
    }

    // Reject anything that is not a plain class path (defence in depth).
    if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
