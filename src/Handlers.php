<?php

declare(strict_types=1);

namespace Specrig;

// The process is Behat's, and so are its error handlers: a framework that installs
// its own while it boots has them taken back, so a step's PHP warning is still
// Behat's to report.
final class Handlers
{
    private const DEEPEST = 32;

    /**
     * @template T
     * @param \Closure(): T $boot
     * @return T
     */
    public static function kept(\Closure $boot): mixed
    {
        $error = self::error();
        $exception = self::exception();
        $reporting = error_reporting();
        $display = ini_get('display_errors');
        try {
            return $boot();
        } finally {
            for ($i = 0; $i < self::DEEPEST && self::error() !== $error; $i++) {
                restore_error_handler();
            }
            for ($i = 0; $i < self::DEEPEST && self::exception() !== $exception; $i++) {
                restore_exception_handler();
            }
            error_reporting($reporting);
            if ($display !== false) {
                ini_set('display_errors', $display);
            }
        }
    }

    private static function error(): mixed
    {
        $current = set_error_handler(static fn () => false);
        restore_error_handler();
        return $current;
    }

    private static function exception(): mixed
    {
        $current = set_exception_handler(static fn () => null);
        restore_exception_handler();
        return $current;
    }
}
