<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Draws, shows and reads back the one-shot backup codes of a second factor (HIL-494).
 *
 * A code is ten characters from an alphabet without the letters and digits people confuse
 * on paper (no i, l, o, 0, 1), about fifty bits each. It is stored in one form - lower case,
 * no separator - and shown in another, two groups of five joined by a hyphen, so it reads
 * aloud and copies by eye. {@see normalize()} turns whatever a person typed back into the
 * stored form: case, spaces and hyphens do not matter.
 */
final class BackupCodeGenerator
{
    /** The characters a code is drawn from. */
    private const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Characters in one code. */
    private const int LENGTH = 10;

    /** Characters in one displayed group. */
    private const int GROUP = 5;

    /** Separator between the two displayed groups. */
    private const string SEPARATOR = '-';

    /** Values of a byte, the range a draw is taken from. */
    private const int BYTE_VALUES = 256;

    /**
     * Draws a set of codes, in their stored form.
     *
     * @param int $count Codes in the set
     * @return list<string> Distinct codes, lower case, no separator
     * @throws RandomException When the operating system refuses secure randomness
     */
    public static function generate(int $count): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = self::draw();
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Shows a stored code the way a person reads it.
     *
     * @param string $code Code in its stored form
     * @return string Two groups joined by a hyphen
     */
    public static function display(string $code): string
    {
        return substr($code, 0, self::GROUP) . self::SEPARATOR . substr($code, self::GROUP);
    }

    /**
     * Reads a typed code back into its stored form.
     *
     * @param string $typed Code as typed
     * @return string Lower case, spaces and hyphens removed
     */
    public static function normalize(string $typed): string
    {
        return strtolower(str_replace([' ', self::SEPARATOR], '', trim($typed)));
    }

    /**
     * Draws one code.
     *
     * A byte is kept only below the largest multiple of the alphabet's size, so every
     * character is equally likely.
     *
     * @return string Code in its stored form
     * @throws RandomException When the operating system refuses secure randomness
     */
    private static function draw(): string
    {
        $size = strlen(self::ALPHABET);
        $limit = self::BYTE_VALUES - (self::BYTE_VALUES % $size);
        $code = '';
        while (strlen($code) < self::LENGTH) {
            foreach (str_split(RandomHelper::secureBytes(self::LENGTH)) as $byte) {
                $value = ord($byte);
                if ($value < $limit && strlen($code) < self::LENGTH) {
                    $code .= self::ALPHABET[$value % $size];
                }
            }
        }

        return $code;
    }
}
