<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * TimeHelper - Utility functions for time formatting.
 */
class TimeHelper
{
    /**
     * Get current datetime in SQL format.
     *
     * @return string Datetime in format 'Y-m-d H:i:s' (e.g., '2025-02-19 14:30:45')
     */
    public static function getSqlDateTime(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get current timestamp with milliseconds.
     *
     * @return string Timestamp in format 'Y-m-d H:i:s.v' (e.g., '2025-11-02 16:26:26.123')
     */
    public static function getTimestampWithMs(): string
    {
        $microtime = microtime(true);
        $timestamp = (int) $microtime;
        $milliseconds = (int) (($microtime - $timestamp) * 1000);
        return date('Y-m-d H:i:s', $timestamp) . '.' . str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT);
    }
}
