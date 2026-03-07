<?php

declare(strict_types=1);

namespace DrupalEvolver;

/**
 * Configures Swoole safely.
 */
class SwooleConfig
{
    /**
     * Configures Swoole Coroutines safely, disabling io_uring if it's likely to fail.
     */
    public static function configure(): void
    {
        if (!class_exists('Swoole\Coroutine')) {
            return;
        }

        $enable_io_uring = true;

        // 1. Check if the Kernel has explicitly disabled io_uring (Linux 6.6+)
        // 0 = Enabled, 1 = Disabled for unprivileged, 2 = Disabled for all.
        $sysctl_path = '/proc/sys/kernel/io_uring_disabled';
        if (is_readable($sysctl_path)) {
            $status = trim(file_get_contents($sysctl_path));
            if ($status === '2' || $status === '1') {
                $enable_io_uring = false;
            }
        }

        // 2. Check for Docker/Container environment
        // Most default Docker Seccomp profiles block io_uring_setup() 
        // unless --security-opt seccomp=unconfined is used.
        if ($enable_io_uring && file_exists('/.dockerenv')) {
            // In Docker, we check if the user has explicitly requested io_uring.
            // If not, we disable it to prevent the "Operation not permitted" crash.
            // We use 'on' or '1' as truthy values.
            $env = getenv('SWOOLE_IO_URING');
            if ($env !== 'on' && $env !== '1') {
                $enable_io_uring = false;
            }
        }

        // 3. Fallback for explicit environment variables
        if (getenv('SWOOLE_IO_URING') === 'off' || getenv('SWOOLE_IO_URING') === '0') {
            $enable_io_uring = false;
        }

        \Swoole\Coroutine::set([
            'io_uring' => $enable_io_uring,
        ]);
    }
}
