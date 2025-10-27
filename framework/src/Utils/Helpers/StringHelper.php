<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * StringHelper - string manipulation utilities
 *
 * Provides helper functions for string formatting and manipulation.
 *
 * @package Hilos\Utils\Helpers
 */
class StringHelper
{
    /**
     * Format bytes to human readable format
     *
     * Converts byte count to human-readable format (B, KB, MB, GB).
     * Uses standard 1024-based calculation.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "15.2 MB")
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Format uptime in seconds to HH:MM:SS format
     *
     * Converts seconds to human-readable time format.
     *
     * @param int $seconds Number of seconds
     * @return string Formatted string (e.g., "01:23:45")
     */
    public static function formatUptime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}

