<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;

/** An unambiguous instant serialized for storage and transport. */
final class UtcMoment
{
    private const string FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @param DateTimeInterface $moment Instant in any time zone; left unchanged
     * @return string ISO-8601 UTC instant with six fractional digits and Z
     */
    public static function format(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(TimeConstants::UTC_TIME_ZONE))
            ->format(self::FORMAT);
    }

    /**
     * Accepts only a complete ISO instant with T and an explicit Z or numeric offset.
     * Calendar and clock overflows are refused rather than repaired by DateTime.
     *
     * @param string $moment Zoned ISO instant, with up to six fractional digits
     * @return string The same instant in canonical UTC form
     * @throws InvalidFormatException When the instant is malformed, impossible or lacks a zone
     */
    public static function normalize(string $moment): string
    {
        if (preg_match(
            '/\A(?!0000)[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])'
            . 'T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?'
            . '(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/',
            $moment,
        ) !== 1) {
            throw new InvalidFormatException('Moment must be a complete ISO-8601 instant with an explicit time zone');
        }

        $parsed = DateTimeImmutable::createFromFormat(
            str_contains($moment, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP',
            $moment,
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidFormatException('Moment carries an invalid calendar date or time');
        }

        return self::format($parsed);
    }
}
