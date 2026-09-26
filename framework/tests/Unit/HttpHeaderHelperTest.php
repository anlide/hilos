<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
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

    public function testContentDispositionNamesTheFileBothWays(): void
    {
        $this->assertSame(
            "inline; filename=\"photo.png\"; filename*=UTF-8''photo.png",
            HttpHeaderHelper::contentDisposition(HttpConstants::CONTENT_DISPOSITION_INLINE, 'photo.png'),
        );
    }

    /**
     * A name is the uploader's words: it may carry a path, a line break that would end the
     * header, or quotes that would end the ASCII name early - none of which reaches the header.
     */
    public function testContentDispositionKeepsOnlyASafeBaseName(): void
    {
        $this->assertSame(
            "attachment; filename=\"evil.pdfX-Injected: 1\"; filename*=UTF-8''evil.pdfX-Injected%3A%201",
            HttpHeaderHelper::contentDisposition(
                HttpConstants::CONTENT_DISPOSITION_ATTACHMENT,
                "../../etc/evil.pdf\r\nX-Injected: 1",
            ),
        );
        $this->assertSame(
            "attachment; filename=\"ab.txt\"; filename*=UTF-8''a%22b.txt",
            HttpHeaderHelper::contentDisposition(HttpConstants::CONTENT_DISPOSITION_ATTACHMENT, 'a"b.txt'),
        );
    }

    public function testContentDispositionFallsBackToAGenericNameWhenNothingIsLeft(): void
    {
        $this->assertSame(
            "attachment; filename=\"file\"; filename*=UTF-8''file",
            HttpHeaderHelper::contentDisposition(HttpConstants::CONTENT_DISPOSITION_ATTACHMENT, ''),
        );
    }
}
