<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Random\RandomException;
use ValueError;

/**
 * RandomHelper - random values on two axes, and the caller picks the axis by what
 * the value is for.
 *
 * A secret takes secureBytes()/secureHex(): they are the only place the secure
 * source is called, and a refusal of that source leaves as `RandomException`. A
 * session token or a connection identity minted from mt_rand() is guessable, and
 * nothing outside can tell it from a real one - so the caller has to see the
 * refusal rather than a value.
 *
 * Everything that only wants values unlikely to collide - a correlation id, a
 * temporary directory name, a demo user's number - takes bytes()/hex()/integer():
 * they catch that same refusal and fall back to pseudorandom, because a crooked
 * correlation id is not worth stopping the work over.
 *
 * Which callers may take the tolerant axis is not left to the reader: the
 * RANDOM-SOURCE guard holds the list (see
 * docs/agents/code-style/random-source.md).
 */
class RandomHelper
{
    /**
     * Generate random bytes for a secret: the secure source, or nothing.
     *
     * The helper's only call to random_bytes(): the tolerant bytes() catches this
     * method's refusal instead of asking the source a second time of its own.
     *
     * @param int $length Byte length; non-positive values return an empty string
     * @return string Random byte string
     * @throws RandomException When the platform's secure random source refuses
     */
    public static function secureBytes(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return random_bytes($length);
    }

    /**
     * Generate a hex string for a secret: the secure source, or nothing.
     *
     * @param int $byteLength Source byte length; non-positive values return an empty string
     * @return string Hex encoded random bytes, lowercase
     * @throws RandomException When the platform's secure random source refuses
     */
    public static function secureHex(int $byteLength): string
    {
        return bin2hex(self::secureBytes($byteLength));
    }

    /**
     * Generate random bytes, falling back to pseudorandom bytes when the secure source fails.
     *
     * @param int $length Byte length; non-positive values return an empty string
     * @return string Random byte string
     */
    public static function bytes(int $length): string
    {
        try {
            return self::secureBytes($length);
        } catch (RandomException) {
            return self::pseudoRandomBytes($length);
        }
    }

    /**
     * Generate a random hex string from the requested byte length.
     *
     * @param int $byteLength Source byte length; non-positive values return an empty string
     * @return string Hex encoded random bytes
     */
    public static function hex(int $byteLength): string
    {
        return bin2hex(self::bytes($byteLength));
    }

    /**
     * Generate a random integer in the inclusive range.
     *
     * @param int $min Inclusive lower bound
     * @param int $max Inclusive upper bound
     * @return int Random integer, or $min when the range is invalid
     */
    public static function integer(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        try {
            return random_int($min, $max);
        } catch (RandomException) {
            return self::pseudoRandomInteger($min, $max);
        }
    }

    /**
     * Generate fallback pseudorandom bytes when the secure random source fails.
     *
     * @param int $length Byte length
     * @return string Pseudorandom byte string of the requested length
     */
    private static function pseudoRandomBytes(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    /**
     * Generate a fallback pseudorandom integer without surfacing mt_rand() range errors.
     *
     * @param int $min Inclusive lower bound
     * @param int $max Inclusive upper bound
     * @return int Pseudorandom integer, or $min if the fallback rejects the range
     */
    private static function pseudoRandomInteger(int $min, int $max): int
    {
        try {
            return mt_rand($min, $max);
        } catch (ValueError) {
            return $min;
        }
    }
}
