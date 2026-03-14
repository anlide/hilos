<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * StringHelper - string manipulation utilities.
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

    /**
     * Pluralize a word (simple English pluralization)
     *
     * Converts singular word to plural form using basic English rules.
     * Examples: "User" -> "Users", "Event" -> "Events", "Box" -> "Boxes"
     *
     * @param string $word Singular word to pluralize
     * @return string Plural form of the word
     */
    public static function pluralize(string $word): string
    {
        // Basic pluralization rules
        if (str_ends_with($word, 'y')) {
            // city -> cities, country -> countries
            return substr($word, 0, -1) . 'ies';
        }
        if (str_ends_with($word, 's') || str_ends_with($word, 'x') || str_ends_with($word, 'z') || 
            str_ends_with($word, 'ch') || str_ends_with($word, 'sh')) {
            // box -> boxes, class -> classes, match -> matches
            return $word . 'es';
        }
        // Default: add 's'
        // user -> users, event -> events
        return $word . 's';
    }

    /**
     * Singularize a word (reverse of pluralization)
     *
     * Converts plural word to singular form using basic English rules.
     * Examples: "Users" -> "User", "Events" -> "Event", "Boxes" -> "Box"
     *
     * @param string $word Plural word to singularize
     * @return string Singular form of the word
     */
    public static function singularize(string $word): string
    {
        // Check for -ies ending (cities -> city)
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }
        // Check for -es ending (boxes -> box, classes -> class)
        if (str_ends_with($word, 'es') && (
            str_ends_with($word, 'ses') || str_ends_with($word, 'xes') || str_ends_with($word, 'zes') ||
            str_ends_with($word, 'ches') || str_ends_with($word, 'shes')
        )) {
            return substr($word, 0, -2);
        }
        // Check for -s ending (users -> user, events -> event)
        if (str_ends_with($word, 's')) {
            return substr($word, 0, -1);
        }
        // No change if doesn't end with 's'
        return $word;
    }
}
