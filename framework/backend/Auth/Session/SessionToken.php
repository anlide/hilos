<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * SessionToken - the single owner of the session token's form (HIL-556).
 *
 * The master mints a token on the 101 and checks the cookie a client sends
 * back; the worker side - session writes and the demo agents guarding their
 * handshake seam - checks what arrives over the wire. Everyone reads the rule
 * from here, so it cannot drift apart again: a token is exactly what mint()
 * emits, 32 lowercase hex characters. Uppercase hex is refused because no
 * issued token can look like that.
 */
final class SessionToken
{
    /** @var int Byte length drawn from the random source; a token is these bytes in hex */
    private const int RANDOM_BYTES = 16;

    /** @var int Token length in hex characters */
    private const int HEX_LENGTH = self::RANDOM_BYTES * 2;

    /** @var string Token form: lowercase hex, exactly HEX_LENGTH characters */
    private const string FORMAT_PATTERN = '/\A[0-9a-f]{' . self::HEX_LENGTH . '}\z/';

    /**
     * @return string Freshly minted session token
     */
    public static function mint(): string
    {
        return RandomHelper::hex(self::RANDOM_BYTES);
    }

    /**
     * Predicate for the caller that replaces a bad token instead of failing:
     * the master mints a new one for a client whose cookie does not pass.
     *
     * @param string $token Token value to check
     * @return bool True when the value has the minted form
     */
    public static function isValid(string $token): bool
    {
        return preg_match(self::FORMAT_PATTERN, $token) === 1;
    }

    /**
     * @param string $token Token value to check
     * @throws InvalidFormatException When the value is not a 32-character lowercase hex token
     */
    public static function ensureValid(string $token): void
    {
        if (!self::isValid($token)) {
            throw new InvalidFormatException(
                'Invalid session token format. Expected ' . self::HEX_LENGTH . ' lowercase hex characters.'
            );
        }
    }
}
