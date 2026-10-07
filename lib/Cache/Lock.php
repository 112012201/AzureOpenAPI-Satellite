<?php

namespace SatApi\Cache;

use SatApi\Config;

/**
 * File based advisory lock used for cache single-flight.
 *
 * flock() is released automatically when the process dies, which is why it is
 * used instead of the "timestamp file" pattern from the previous code base
 * (that lock could stay stuck for minutes and permanently block refreshes).
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;
    private $path = '';

    public function __construct($key)
    {
        $dir = (string) Config::get('cache.dir', Config::path('data/cache')) . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $this->path = $dir . '/' . hash('sha256', (string) $key) . '.lock';
    }

    /**
     * @param int $timeoutMs Maximum time to wait for the lock.
     * @return bool
     */
    public function acquire($timeoutMs = 0)
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + max(0, (int) $timeoutMs) / 1000;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                @ftruncate($handle, 0);
                @fwrite($handle, (string) getmypid() . ' ' . time());

                return true;
            }
            if ($timeoutMs <= 0) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        @fclose($handle);

        return false;
    }

    public function release()
    {
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
