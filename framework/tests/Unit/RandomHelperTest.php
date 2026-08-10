<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Helpers\RandomHelper;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * Unit tests for both axes of RandomHelper: the secure pair a secret is drawn from
 * and the tolerant trio that falls back to pseudorandom (HIL-568).
 *
 * What cannot be tested from PHP is the refusal itself: random_bytes() cannot be
 * made to fail, and a seam in production code that let a test replace the source
 * would be a seam the production code never asked for.
 */
final class RandomHelperTest extends TestCase
{
    /**
     * @throws RandomException When the platform's secure random source refuses
     */
    public function testSecureBytesReturnsRequestedLength(): void
    {
        $this->assertSame(16, strlen(RandomHelper::secureBytes(16)));
    }

    /**
     * @throws RandomException When the platform's secure random source refuses
     */
    public function testSecureHexReturnsTwoLowercaseHexCharactersPerByte(): void
    {
        $hex = RandomHelper::secureHex(8);

        $this->assertSame(16, strlen($hex));
        $this->assertTrue(ctype_xdigit($hex));
        $this->assertSame(strtolower($hex), $hex);
    }

    /**
     * The boundary is the one place the two axes have to agree, or a caller moved
     * from one to the other would silently change what it hands out.
     *
     * @throws RandomException When the platform's secure random source refuses
     */
    public function testSecurePairReturnsEmptyStringForNonPositiveLength(): void
    {
        $this->assertSame('', RandomHelper::secureBytes(0));
        $this->assertSame('', RandomHelper::secureBytes(-1));
        $this->assertSame('', RandomHelper::secureHex(0));
        $this->assertSame('', RandomHelper::secureHex(-1));
    }

    public function testBytesReturnsRequestedLength(): void
    {
        $this->assertSame(16, strlen(RandomHelper::bytes(16)));
    }

    public function testBytesReturnsEmptyStringForNonPositiveLength(): void
    {
        $this->assertSame('', RandomHelper::bytes(0));
        $this->assertSame('', RandomHelper::bytes(-1));
    }

    public function testHexReturnsEncodedRequestedLength(): void
    {
        $hex = RandomHelper::hex(8);

        $this->assertSame(16, strlen($hex));
        $this->assertSame(1, preg_match('/^[0-9a-f]+$/', $hex));
    }

    public function testIntegerReturnsValueInsideInclusiveRange(): void
    {
        $value = RandomHelper::integer(10, 20);

        $this->assertGreaterThanOrEqual(10, $value);
        $this->assertLessThanOrEqual(20, $value);
    }

    public function testIntegerReturnsMinForCollapsedOrInvalidRange(): void
    {
        $this->assertSame(10, RandomHelper::integer(10, 10));
        $this->assertSame(10, RandomHelper::integer(10, 5));
    }
}
