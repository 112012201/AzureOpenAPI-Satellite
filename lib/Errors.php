<?php

namespace SatApi;

use SatApi\Support\Problem;

/**
 * Error handling: converts every PHP error, uncaught exception and fatal
 * shutdown error into a uniform RFC 9457 problem+json response (or a plain
 * text 500 for TLE/plain-text clients), while never leaking stack traces.
 */
final class Errors
{
    private static $registered = false;

    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $debug = (bool) Config::get('debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler(array(__CLASS__, 'handleError'));
        set_exception_handler(array(__CLASS__, 'handleException'));
        register_shutdown_function(array(__CLASS__, 'handleShutdown'));
    }

    public static function handleError($severity, $message, $file = '', $line = 0)
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        Logger::warning('php_error', array(
            'severity' => $severity,
            'message'  => $message,
            'file'     => basename((string) $file),
            'line'     => $line,
        ));

        // Let PHP's own handler deal with warnings/notices (they are logged).
        if ($severity !== E_USER_ERROR && $severity !== E_RECOVERABLE_ERROR) {
            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException($e)
    {
        Logger::error('uncaught_exception', array(
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ));

        Problem::send(500, 'Internal Server Error', 'The service encountered an unexpected condition.', 'internal_error');
    }

    public static function handleShutdown()
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        Logger::error('fatal_error', array(
            'message' => $error['message'],
            'file'    => basename((string) $error['file']),
            'line'    => $error['line'],
        ));

        if (!headers_sent()) {
            Problem::send(500, 'Internal Server Error', 'The service terminated unexpectedly.', 'fatal_error');
        }
    }
}
