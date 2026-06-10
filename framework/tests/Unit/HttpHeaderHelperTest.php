<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Helpers\HttpHeaderHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for case-insensitive HTTP header reads.
 */
final class HttpHeaderHelperTest extends TestCase
{
    public function testReadsLowercaseNormalizedMapByCanonicalName(): void
    {
        $this->assertSame(
            'UnitAgent/1.0',
            HttpHeaderHelper::get(['user-agent' => 'UnitAgent/1.0'], 'User-Agent'),
        );
    }

    public function testFallsBackToCaseInsensitiveScanForNonNormalizedMap(): void
    {
        $this->assertSame(
            'UnitAgent/1.0',
            HttpHeaderHelper::get(['User-Agent' => 'UnitAgent/1.0'], 'user-agent'),
        );
    }

    public function testReturnsNullForMissingHeader(): void
    {
        $this->assertNull(HttpHeaderHelper::get(['host' => 'localhost'], 'User-Agent'));
    }

    public function testReturnsNullForNonStringValue(): void
    {
        $this->assertNull(HttpHeaderHelper::get(['content-length' => 42], 'Content-Length'));
    }
}
