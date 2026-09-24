<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226) - the codes an authenticator app shows (HIL-494).
 *
 * HMAC-SHA1, six digits, a thirty-second step: the parameters every authenticator app reads
 * from a QR code without asking, and the only ones this framework issues. Written here rather
 * than taken from a library, by the same rule as {@see Base32}.
 *
 * {@see verify()} answers the STEP a code matched rather than a yes, because the step is what
 * the replay guard stores: a code accepted once must not pass again inside its own window.
 */
final class Totp
{
    /** Seconds one code lives. */
    public const int PERIOD_SEC = 30;

    /** Digits in a code. */
    public const int DIGITS = 6;

    /** Steps either side of now a code is still accepted for - the drift of a phone's clock. */
    public const int WINDOW_STEPS = 1;

    /** Bytes of a freshly drawn secret: 160 bits, the HMAC-SHA1 block the RFC recommends. */
    public const int SECRET_BYTES = 20;

    /** Hash the codes are computed with. */
    private const string ALGORITHM = 'sha1';

    /** pack() format of the moving factor: 64-bit unsigned, big endian (RFC 4226, 5.2). */
    private const string COUNTER_FORMAT = 'J';

    /** unpack() format of the truncated word: 32-bit unsigned, big endian. */
    private const string WORD_FORMAT = 'N';

    /** Low nibble of the last HMAC byte - the dynamic truncation offset (RFC 4226, 5.3). */
    private const int OFFSET_MASK = 0x0f;

    /** Mask dropping the sign bit of the truncated 31-bit value. */
    private const int SIGN_MASK = 0x7fffffff;

    /** Bytes the truncation reads. */
    private const int TRUNCATED_BYTES = 4;

    /** Base of the digits a code is written in. */
    private const int RADIX = 10;

    /**
     * Draws a fresh secret, in base32, the way an authenticator app reads it.
     *
     * @return string Base32 secret
     * @throws RandomException When the operating system refuses secure randomness
     */
    public static function newSecret(): string
    {
        return Base32::encode(RandomHelper::secureBytes(self::SECRET_BYTES));
    }

    /**
     * The step a moment falls into.
     *
     * @param int $nowSec Unix time in seconds
     * @return int Step number
     */
    public static function stepAt(int $nowSec): int
    {
        return intdiv($nowSec, self::PERIOD_SEC);
    }

    /**
     * Computes the code of one step.
     *
     * @param string $secretBytes Raw secret
     * @param int $step Step number
     * @return string Code, zero-padded to six digits
     */
    public static function codeAt(string $secretBytes, int $step): string
    {
        $hmac = hash_hmac(self::ALGORITHM, pack(self::COUNTER_FORMAT, $step), $secretBytes, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & self::OFFSET_MASK;
        $value = unpack(self::WORD_FORMAT, substr($hmac, $offset, self::TRUNCATED_BYTES));
        $truncated = (is_array($value) ? (int)$value[1] : 0) & self::SIGN_MASK;

        return str_pad((string)($truncated % (self::RADIX ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a typed code against a secret, a step either side of now included.
     *
     * The current step is tried first, then the one before, then the one after. Spaces in the
     * typed code are ignored - apps show the code in two halves - and anything but six digits
     * matches nothing. The comparison does not stop at the first differing digit.
     *
     * @param string $secretBase32 Base32 secret
     * @param string $code Code as typed
     * @param int $nowSec Unix time in seconds
     * @param int $window Steps either side of now to accept
     * @return ?int The step the code matched, or null when it matched none
     */
    public static function verify(string $secretBase32, string $code, int $nowSec, int $window = self::WINDOW_STEPS): ?int
    {
        $typed = str_replace(' ', '', $code);
        if (strlen($typed) !== self::DIGITS || !ctype_digit($typed)) {
            return null;
        }

        $secret = Base32::decode($secretBase32);
        if ($secret === null || $secret === '') {
            return null;
        }

        $now = self::stepAt($nowSec);
        $steps = [$now];
        for ($offset = 1; $offset <= $window; $offset++) {
            $steps[] = $now - $offset;
            $steps[] = $now + $offset;
        }
        foreach ($steps as $step) {
            if (hash_equals(self::codeAt($secret, $step), $typed)) {
                return $step;
            }
        }

        return null;
    }
}
