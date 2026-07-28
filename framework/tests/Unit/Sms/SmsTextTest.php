<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms;

use Hilos\Sms\SmsText;
use PHPUnit\Framework\TestCase;

/**
 * Tests SMS segment sizing, single-segment truncation, and number masking (HIL-285).
 *
 * The alphabet drives the budget: a GSM-7 (ASCII) body fits the configured limit, a body with
 * any non-ASCII character (Cyrillic) fits only one UCS-2 segment. {@see SmsText::maskNumber()}
 * leaves only the last digits so a log line never carries the full number.
 */
final class SmsTextTest extends TestCase
{
    public function testAsciiBodyTruncatesToTheGsmBudget(): void
    {
        $truncation = SmsText::truncate(str_repeat('a', 200), 160);

        self::assertTrue($truncation->truncated);
        self::assertSame(160, mb_strlen($truncation->text));
        self::assertStringEndsWith(SmsText::ELLIPSIS, $truncation->text);
    }

    public function testCyrillicBodyTruncatesToTheUcs2Budget(): void
    {
        $truncation = SmsText::truncate(str_repeat('я', 100), 160);

        self::assertTrue($truncation->truncated);
        self::assertSame(SmsText::UCS2_SEGMENT, mb_strlen($truncation->text));
    }

    public function testShortBodyPassesThroughUnchanged(): void
    {
        $truncation = SmsText::truncate('short code 123', 160);

        self::assertFalse($truncation->truncated);
        self::assertSame('short code 123', $truncation->text);
    }

    public function testSegmentLimitPicksAlphabetByContent(): void
    {
        self::assertSame(160, SmsText::segmentLimit('plain ascii', 160));
        self::assertSame(SmsText::UCS2_SEGMENT, SmsText::segmentLimit('привет', 160));
    }

    public function testMaskNumberKeepsOnlyTheLastFourDigits(): void
    {
        $masked = SmsText::maskNumber('+15551234567');

        self::assertSame('********4567', $masked);
        self::assertStringEndsWith('4567', $masked);
    }

    public function testMaskNumberMasksAShortNumberEntirely(): void
    {
        self::assertSame('***', SmsText::maskNumber('123'));
    }
}
