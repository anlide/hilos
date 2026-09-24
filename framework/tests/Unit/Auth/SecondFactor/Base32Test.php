<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the unpadded base32 an authenticator app reads a secret in (HIL-494).
 *
 * The vectors are RFC 4648's own (section 10) with the padding dropped, which is the form
 * an otpauth address carries.
 */
final class Base32Test extends TestCase
{
    /**
     * @return array<string, array{string, string}> Raw bytes and their unpadded encoding
     */
    public static function rfcVectors(): array
    {
        return [
            'empty' => ['', ''],
            'f' => ['f', 'MY'],
            'fo' => ['fo', 'MZXQ'],
            'foo' => ['foo', 'MZXW6'],
            'foob' => ['foob', 'MZXW6YQ'],
            'fooba' => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI'],
        ];
    }

    /**
     * Encoding gives the RFC's text without padding.
     */
    #[DataProvider('rfcVectors')]
    public function testEncodesTheRfcVectors(string $bytes, string $text): void
    {
        self::assertSame($text, Base32::encode($bytes));
    }

    /**
     * Decoding gives the RFC's bytes back.
     */
    #[DataProvider('rfcVectors')]
    public function testDecodesTheRfcVectors(string $bytes, string $text): void
    {
        self::assertSame($bytes, Base32::decode($text));
    }

    /**
     * A secret typed by hand - lower case, grouped by spaces, padded - still decodes.
     */
    public function testDecodingIgnoresCaseSpacesAndPadding(): void
    {
        self::assertSame('foobar', Base32::decode('mzxw 6ytb oi======'));
    }

    /**
     * A character outside the alphabet is refused rather than skipped.
     */
    public function testACharacterOutsideTheAlphabetIsRefused(): void
    {
        self::assertNull(Base32::decode('MZXW1'));
    }

    /**
     * Twenty random bytes survive the round trip - the size of every secret the framework draws.
     */
    public function testTwentyBytesRoundTrip(): void
    {
        $bytes = random_bytes(20);

        self::assertSame($bytes, Base32::decode(Base32::encode($bytes)));
    }
}
