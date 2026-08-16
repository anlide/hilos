<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Constants\TimeConstants;

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
     * Get the current server moment in epoch milliseconds.
     *
     * The form every absolute moment takes once it leaves for the browser
     * (HIL-486): the client is told the server's own "now" at the handshake and
     * reads the other moments against it, so both sides speak one scale and no
     * countdown depends on how right the browser's clock happens to be.
     *
     * @return int Milliseconds since the Unix epoch
     */
    public static function nowMs(): int
    {
        return (int)(microtime(true) * TimeConstants::MS_PER_SECOND);
    }

    /**
     * Convert an SQL datetime into the epoch milliseconds the browser is told.
     *
     * The bridge between the two scales the backend keeps (HIL-486): rows are
     * written in SQL datetimes, the wire speaks moments in milliseconds, and a
     * screen counting down to an expiry needs the second form of the first. An
     * unreadable datetime answers the epoch rather than the current moment: a
     * countdown that is already over is visibly wrong, while one starting now would
     * quietly promise time that no row grants.
     *
     * @param string $sqlDateTime Datetime in format 'Y-m-d H:i:s'
     * @return int Milliseconds since the Unix epoch, or 0 when the datetime cannot be read
     */
    public static function sqlToMs(string $sqlDateTime): int
    {
        $timestamp = strtotime($sqlDateTime);

        return $timestamp === false ? 0 : $timestamp * TimeConstants::MS_PER_SECOND;
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
        $milliseconds = (int) (($microtime - $timestamp) * TimeConstants::MS_PER_SECOND);
        return date('Y-m-d H:i:s', $timestamp) . '.' . str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT);
    }
}
