<?php

namespace MajorDoMo\Core;

/**
 * Deduplicates operational failures before they reach logs and the admin error
 * feed. A broken broker or a database outage can fail hundreds of property
 * notifications per minute; each key is reported once per window.
 */
final class ErrorReporter
{
    /** Per-process fallback when the shared cache is unavailable. */
    private static array $local = array();

    public function __construct(private Cache $cache) {}

    /**
     * @param string $code    group shown by the system_errors module
     * @param string $message human-readable details
     * @param string $key     scope (module/object/property); defaults to code
     * @param int    $window  seconds between repeated reports of the same key
     */
    public function report(string $code, string $message, string $key = '', int $window = 300): bool
    {
        $window = max(30, $window);
        $key = $code . ':' . ($key !== '' ? $key : 'general');
        $now = time();

        if (isset(self::$local[$key]) && $now - self::$local[$key] < $window) return false;

        try {
            $last = (int)$this->cache->get('report:' . sha1($key));
            if ($last > 0 && $now - $last < $window) {
                self::$local[$key] = $last;
                return false;
            }
            $this->cache->set('report:' . sha1($key), (string)$now);
        } catch (\Throwable $e) {
            // The database/cache may be the very failure being reported.
        }

        self::$local[$key] = $now;
        if (function_exists('registerError')) {
            try {
                registerError($code, $message);
                return true;
            } catch (\Throwable $e) {
                // Fall through to the file log.
            }
        }
        if (function_exists('DebMes')) {
            try {
                DebMes($message, 'property_delivery', 'error');
                return true;
            } catch (\Throwable $e) {
                // Fall through to the PHP log.
            }
        }
        error_log($message);
        return true;
    }

    /** Test/tooling hook: clears the per-process suppression window. */
    public static function reset(): void
    {
        self::$local = array();
    }
}
