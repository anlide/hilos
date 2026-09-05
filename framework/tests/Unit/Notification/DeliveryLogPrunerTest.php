<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use DateTimeImmutable;
use Hilos\Notification\DeliveryLogPruner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-log pruner's pure decision surface (HIL-201).
 *
 * Locks the retention semantics without touching the database: a retention of 0 (or
 * less) disables cleanup and makes {@see DeliveryLogPruner::prune()} a no-op, and a
 * positive retention yields a `$now`-minus-window cutoff. The batched delete itself is
 * exercised at the integration level (it needs a live table), and the settings key the
 * window is read from is locked by the test of the catalog fragment that declares it.
 */
final class DeliveryLogPrunerTest extends TestCase
{
    public function testZeroOrNegativeRetentionHasNoCutoff(): void
    {
        $pruner = new DeliveryLogPruner();
        $now = new DateTimeImmutable('2026-07-28 12:00:00');

        self::assertNull($pruner->cutoff(0, $now));
        self::assertNull($pruner->cutoff(-5, $now));
    }

    public function testPositiveRetentionSubtractsWindow(): void
    {
        $pruner = new DeliveryLogPruner();
        $now = new DateTimeImmutable('2026-07-28 12:00:00');

        self::assertSame('2026-04-29 12:00:00', $pruner->cutoff(90, $now));
        self::assertSame('2026-07-27 12:00:00', $pruner->cutoff(1, $now));
    }

    public function testZeroRetentionPruneIsNoOp(): void
    {
        // A disabled retention must never reach the database; prune returns 0 deleted.
        self::assertSame(0, new DeliveryLogPruner()->prune(0, new DateTimeImmutable('2026-07-28 12:00:00')));
    }
}
