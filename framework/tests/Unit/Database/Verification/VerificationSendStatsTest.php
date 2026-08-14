<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Verification;

use Hilos\Database\Verification\VerificationSendStats;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the send-stats value object (HIL-421).
 *
 * Locks the one distinction the send gate reads it for: "never mailed" is a null
 * last issue and not a zero, so a target with no history can never be mistaken
 * for one mailed at the epoch.
 */
final class VerificationSendStatsTest extends TestCase
{
    private const int LAST_ISSUED_AT = 1_770_000_000;

    public function testNeverCarriesNoIssueAndAnEmptyWindow(): void
    {
        $stats = VerificationSendStats::never();

        self::assertNull($stats->lastIssuedAt);
        self::assertSame(0, $stats->sentInWindow);
    }

    public function testIssuedCarriesBothNumbersVerbatim(): void
    {
        $stats = VerificationSendStats::issued(self::LAST_ISSUED_AT, 3);

        self::assertSame(self::LAST_ISSUED_AT, $stats->lastIssuedAt);
        self::assertSame(3, $stats->sentInWindow);
    }

    public function testIssuedOutsideTheWindowStillCarriesTheLastIssue(): void
    {
        $stats = VerificationSendStats::issued(self::LAST_ISSUED_AT, 0);

        self::assertSame(self::LAST_ISSUED_AT, $stats->lastIssuedAt);
        self::assertSame(0, $stats->sentInWindow);
    }
}
