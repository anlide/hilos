<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use DateTimeImmutable;
use Hilos\Notification\Delivery\DeliveryLogRetention;
use PHPUnit\Framework\TestCase;

/** Clock-free coverage of the delivery journal's retention boundary. */
final class DeliveryLogRetentionTest extends TestCase
{
    public function testZeroOrNegativeRetentionHasNoCutoff(): void
    {
        $retention = new DeliveryLogRetention();
        $now = new DateTimeImmutable('2026-07-28 12:00:00.123 UTC');

        self::assertNull($retention->cutoff(0, $now));
        self::assertNull($retention->cutoff(-5, $now));
    }

    public function testPositiveRetentionSubtractsWindow(): void
    {
        $retention = new DeliveryLogRetention();
        $now = new DateTimeImmutable('2026-07-28 12:00:00.123 UTC');

        self::assertSame(1777464000123, $retention->cutoff(90, $now));
        self::assertSame(1785153600123, $retention->cutoff(1, $now));
    }

    public function testProjectClockDoesNotShiftUtcCutoffAcrossDaylightSavingChanges(): void
    {
        $previousZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Warsaw');
            $retention = new DeliveryLogRetention();

            self::assertSame(1774699200123, $retention->cutoff(1, new DateTimeImmutable('2026-03-29 12:00:00.123 UTC')));
            self::assertSame(1792843200123, $retention->cutoff(1, new DateTimeImmutable('2026-10-25 12:00:00.123 UTC')));
        } finally {
            date_default_timezone_set($previousZone);
        }
    }
}
