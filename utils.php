<?php
declare(strict_types=1);

/**
 * Shared helpers for the WeatherApp backend.
 *
 * Loaded by both logic.php and Forecast.php so that utility functions are
 * defined exactly once regardless of load order.
 */

if (!function_exists('esc')) {
    /**
     * Escape a value for safe HTML output (UTF-8).
     */
    function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
