<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\ServerTextTimeZoneMode;
use Hilos\Hilos;

/**
 * TimeHelper - Utility functions for time formatting.
 */
class TimeHelper
{
    /**
     * Uses exact IANA names, preserving aliases and skipping unusable candidates.
     * Recipient mode with an ID requires that person's row and ignores the browser argument.
     * Without an ID it uses the supplied browser zone; project and UTC modes need no DB or RT.
     * Nothing changes the process clock or remembers a zone between calls.
     *
     * @param ?int $recipientUserId Required person in recipient mode; null for an anonymous recipient
     * @param ?string $browserTimeZone Saved anonymous recipient browser zone, used only without an ID
     * @return DateTimeZone First usable policy candidate, falling back to UTC
     * @throws EnvException When the environment accessor refuses a key or value
     * @throws EnvInvalidValueException When the server text mode is invalid
     * @throws ItemNotFoundException When the required recipient no longer exists
     * @throws DatabaseException When loading the recipient fails
     * @throws LogicException When the users collection is not configured
     * @throws InvalidArgumentException When the loaded object does not match the users collection
     */
    public static function serverTextTimeZone(?int $recipientUserId = null, ?string $browserTimeZone = null): DateTimeZone
    {
        $mode = ServerTextTimeZoneMode::fromEnv();
        if ($mode === ServerTextTimeZoneMode::UTC) {
            return new DateTimeZone(TimeConstants::UTC_TIME_ZONE);
        }

        $candidates = [];
        if ($mode === ServerTextTimeZoneMode::RECIPIENT) {
            if ($recipientUserId !== null) {
                $candidates[] = Hilos::$db->users[$recipientUserId]->timeZone;
                $candidates[] = Hilos::$db->users[$recipientUserId]->lastBrowserTimeZone;
            } else {
                $candidates[] = $browserTimeZone;
            }
        }
        $candidates[] = Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string();
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $identifiers, true)) {
                return new DateTimeZone($candidate);
            }
        }

        return new DateTimeZone(TimeConstants::UTC_TIME_ZONE);
    }

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
     * @return string UTC ISO timestamp with milliseconds (e.g., '2025-11-02T16:26:26.123Z')
     */
    public static function getTimestampWithMs(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone(TimeConstants::UTC_TIME_ZONE))
            ->format(TimeConstants::ISO_UTC_MILLISECONDS_FORMAT);
    }
}
