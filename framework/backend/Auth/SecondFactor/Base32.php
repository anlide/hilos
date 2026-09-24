<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

/**
 * Base32 without padding (RFC 4648, section 6) - the alphabet an authenticator app reads a secret in (HIL-494).
 *
 * Written here rather than taken from a library: the framework's backend carries its own code
 * for what fits in a page (docs/agents/framework-development.md, "Dependencies"), and this is
 * a bit shuffle. Decoding is lenient in the way a person typing the secret by hand needs -
 * case, spaces and padding are ignored - and strict about everything else.
 */
final class Base32
{
    /** The RFC 4648 alphabet, one character per 5-bit group. */
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Bits one character carries. */
    private const int BITS_PER_CHAR = 5;

    /** Bits one byte carries. */
    private const int BITS_PER_BYTE = 8;

    /** Mask of the low five bits. */
    private const int CHAR_MASK = 0x1f;

    /** Mask of the low eight bits. */
    private const int BYTE_MASK = 0xff;

    /**
     * Encodes bytes as unpadded base32.
     *
     * @param string $bytes Raw bytes
     * @return string Upper-case base32, no padding
     */
    public static function encode(string $bytes): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            if ($byte === '') {
                continue;
            }
            $buffer = ($buffer << self::BITS_PER_BYTE) | ord($byte);
            $bits += self::BITS_PER_BYTE;
            while ($bits >= self::BITS_PER_CHAR) {
                $bits -= self::BITS_PER_CHAR;
                $out .= self::ALPHABET[($buffer >> $bits) & self::CHAR_MASK];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (self::BITS_PER_CHAR - $bits)) & self::CHAR_MASK];
        }

        return $out;
    }

    /**
     * Decodes base32, ignoring case, spaces and padding.
     *
     * @param string $text Base32 text
     * @return ?string Raw bytes, or null when the text holds a character outside the alphabet
     */
    public static function decode(string $text): ?string
    {
        $clean = strtoupper(str_replace([' ', '='], '', $text));
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($clean) as $char) {
            if ($char === '') {
                continue;
            }
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                return null;
            }
            $buffer = ($buffer << self::BITS_PER_CHAR) | $value;
            $bits += self::BITS_PER_CHAR;
            if ($bits >= self::BITS_PER_BYTE) {
                $bits -= self::BITS_PER_BYTE;
                $out .= chr(($buffer >> $bits) & self::BYTE_MASK);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $out;
    }
}
