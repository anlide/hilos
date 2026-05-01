<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Helpers\RandomHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RandomHelper non-throwing random utilities.
 */
final class RandomHelperTest extends TestCase
{
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
