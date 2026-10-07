<?php

namespace SatApi;

/**
 * Tiny structured logger.
 *
 * Writes JSON-lines to logs/<file> so entries can be shipped to any log
 * pipeline (Loki / ELK / journald) without a parser. Falls back to
 * error_log() when the log directory is not writable.
 */
final class Logger
{
    const DEBUG   = 10;
    const INFO    = 20;
    const WARNING = 30;
    const ERROR   = 40;

    private static $threshold = self::WARNING;
    private static $dir = '';
    private static $file = 'app.log';
    private static $maxBytes = 5242880; // 5 MiB, then rotate once
    private static $requestId = '';

    public static function init($dir = null, $file = null, $level = null)
    {
        self::$dir = $dir !== null ? rtrim($dir, "/\\") : (string) Config::get('logs.dir');
        self::$file = $file !== null ? $file : (string) Config::get('logs.file', 'app.log');
        $level = $level !== null ? $level : (string) Config::get('logs.level', 'warning');
        self::$threshold = self::levelFromName($level);
    }

    public static function setRequestId($id)
    {
        self::$requestId = (string) $id;
    }

    public static function requestId()
    {
        return self::$requestId;
    }

    public static function levelFromName($name)
    {
        switch (strtolower((string) $name)) {
            case 'debug':
                return self::DEBUG;
            case 'info':
                return self::INFO;
            case 'error':
            case 'critical':
                return self::ERROR;
            case 'warning':
            case 'warn':
            default:
                return self::WARNING;
        }
    }

    public static function debug($message, array $context = array())
    {
        self::write(self::DEBUG, $message, $context);
    }

    public static function info($message, array $context = array())
    {
        self::write(self::INFO, $message, $context);
    }

    public static function warning($message, array $context = array())
    {
        self::write(self::WARNING, $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::write(self::ERROR, $message, $context);
    }

    private static function write($level, $message, array $context)
    {
        if ($level < self::$threshold) {
            return;
        }

        $record = array(
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'   => self::nameFromLevel($level),
            'msg'     => (string) $message,
            'req_id'  => self::$requestId,
        );
        if (!empty($context)) {
            $record['ctx'] = $context;
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = '{"level":"error","msg":"log encode failed"}';
        }

        self::rotateIfNeeded();

        $path = self::$dir . '/' . self::$file;
        $ok = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            error_log('[satapi] ' . $line);
        }
    }

    private static function rotateIfNeeded()
    {
        $path = self::$dir . '/' . self::$file;
        if (!is_file($path)) {
            return;
        }
        clearstatcache(true, $path);
        if ((int) @filesize($path) < self::$maxBytes) {
            return;
        }
        @rename($path, $path . '.1');
    }

    private static function nameFromLevel($level)
    {
        switch ($level) {
            case self::DEBUG:
                return 'debug';
            case self::INFO:
                return 'info';
            case self::ERROR:
                return 'error';
            default:
                return 'warning';
        }
    }
}
