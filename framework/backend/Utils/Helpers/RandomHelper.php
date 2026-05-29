<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Random\RandomException;
use ValueError;

/**
 * RandomHelper - random value utilities with non-throwing fallbacks.
 */
class RandomHelper
{
    /**
     * Generate random bytes, falling back to pseudorandom bytes when the secure source fails.
     *
     * @param int $length Byte length; non-positive values return an empty string
     * @return string Random byte string
     */
    public static function bytes(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        try {
            return random_bytes($length);
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
