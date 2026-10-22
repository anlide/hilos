<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use DateTimeZone;

/**
 * BrowserTimeZone - the one place that says whether a name a browser reported is a usable time zone (HIL-1582).
 *
 * A browser names its IANA zone on every socket connection. The name is a person's data, so it is kept only
 * when it is exactly an identifier of the IANA catalog, backward aliases included: UTC and the historical
 * aliases are accepted as they are, never rewritten to a canonical name. An empty or padded string, an
 * unknown name, a numeric offset and an abbreviation are not zones and are not kept.
 *
 * Stateless: the catalog is asked on every call, nothing is cached and no offset is computed here.
 */
final class BrowserTimeZone
{
    /** @var string Query parameter of the socket address that carries the browser's zone */
    public const string QUERY_PARAM = 'browserTimeZone';

    /** @var string Change-log action of the receipt under which the person's agent writes the last usable zone */
    public const string JOURNAL_ACTION = 'browser_time_zone_report';

    /**
     * Turns what the browser sent into what may be kept.
     *
     * Never refuses a connection: a zone that cannot be kept is simply absent.
     *
     * @param ?string $value Raw value of the query parameter, or null when the browser sent none
     * @return ?string The same name when it is a usable zone, null otherwise
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || !self::isValid($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Predicate indicating whether the name is exactly an identifier of the IANA catalog.
     *
     * @param string $value Name as the browser reported it
     * @return bool True for UTC, a canonical identifier or a backward alias, byte for byte
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
