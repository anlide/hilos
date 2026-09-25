<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Files\Upload\UploadMime;
use PHPUnit\Framework\TestCase;

/**
 * Tests the one reading of a MIME type the upload checks share (HIL-135).
 */
final class UploadMimeTest extends TestCase
{
    public function testNormalizationDropsParametersCaseAndSpace(): void
    {
        $this->assertSame('image/png', UploadMime::normalize(' Image/PNG; charset=x'));
    }

    public function testNothingDeclaredIsTheFallbackType(): void
    {
        $this->assertSame(UploadMime::FALLBACK, UploadMime::normalize(''));
        $this->assertSame(UploadMime::FALLBACK, UploadMime::normalize(' ; charset=x'));
    }

    public function testOnlyTheTypeSlashSubtypeShapeIsWellFormed(): void
    {
        $this->assertTrue(UploadMime::isWellFormed('image/png'));
        $this->assertTrue(UploadMime::isWellFormed('application/vnd.ms-excel'));
        $this->assertTrue(UploadMime::isWellFormed('image/svg+xml'));
        $this->assertFalse(UploadMime::isWellFormed('image'));
        $this->assertFalse(UploadMime::isWellFormed('image/'));
        $this->assertFalse(UploadMime::isWellFormed('/png'));
        $this->assertFalse(UploadMime::isWellFormed('image/png/x'));
        $this->assertFalse(UploadMime::isWellFormed('image/ png'));
    }

    public function testAnExactEntryMatchesItsTypeOnly(): void
    {
        $this->assertTrue(UploadMime::matches('image/png', ['text/plain', 'image/png']));
        $this->assertFalse(UploadMime::matches('image/jpeg', ['image/png']));
    }

    public function testAMaskMatchesEverySubtypeOfItsType(): void
    {
        $this->assertTrue(UploadMime::matches('image/png', ['image/*']));
        $this->assertTrue(UploadMime::matches('image/svg+xml', ['image/*']));
        $this->assertFalse(UploadMime::matches('imagex/png', ['image/*']));
        $this->assertFalse(UploadMime::matches('text/plain', ['image/*']));
    }

    public function testAnEmptyListMatchesNothing(): void
    {
        $this->assertFalse(UploadMime::matches('image/png', []));
    }
}
