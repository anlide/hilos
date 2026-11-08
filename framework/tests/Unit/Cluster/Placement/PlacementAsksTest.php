<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\PlacementAsk;
use Hilos\Cluster\Placement\PlacementAsks;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Scalar deadline inputs; no clock is replaced or waited out. */
final class PlacementAsksTest extends TestCase
{
    public function testAnAddressExpiresAtItsExactDeadlineBeforePhysicalCleanup(): void
    {
        $asks = new PlacementAsks();
        $asks->remember('render:9', 10.0);
        self::assertTrue($asks->isPending('render:9', 9.999));
        self::assertFalse($asks->isPending('render:9', 10.0));
        self::assertFalse($asks->isPending('other', 9.999));
        self::assertCount(1, $this->pending($asks));
        $asks->expire(10.0, 1);
        self::assertSame([], $this->pending($asks));
    }

    public function testRetiredAndCanceledEntriesEachSpendOneVisitWithoutExpiringAReplacement(): void
    {
        $asks = new PlacementAsks();
        $asks->remember('replaced', 5.0);
        $asks->remember('replaced', 15.0);
        $asks->remember('canceled', 6.0);
        $asks->forget('canceled');
        $asks->remember('first', 7.0);
        $asks->remember('last', 8.0);
        $asks->expire(10.0, 1);
        self::assertSame(['replaced', 'first', 'last'], array_keys($this->pending($asks)));
        self::assertTrue($asks->isPending('replaced', 14.999));
        self::assertFalse($asks->isPending('canceled', 0.0));
        $asks->expire(10.0, 1);
        self::assertSame(['replaced', 'first', 'last'], array_keys($this->pending($asks)));
        $asks->expire(10.0, 1);
        self::assertSame(['replaced', 'last'], array_keys($this->pending($asks)));
        $asks->expire(10.0, 1);
        self::assertSame(['replaced'], array_keys($this->pending($asks)));
        self::assertTrue($asks->isPending('replaced', 14.999));
    }

    public function testDeadlineOrderIsIndependentOfInsertionOrderAndTimeMovingBackwards(): void
    {
        $asks = new PlacementAsks();
        $asks->remember('future', 100.0);
        $asks->remember('earlier', 5.0);
        $asks->expire(10.0, 64);
        self::assertSame(['future'], array_keys($this->pending($asks)));
        $asks->remember('backwards', 2.0);
        $asks->expire(3.0, 1);
        self::assertSame(['future'], array_keys($this->pending($asks)));
        $asks->expire(99.999, 64);
        self::assertTrue($asks->isPending('future', 99.999));
        $asks->expire(100.0, 1);
        self::assertSame([], $this->pending($asks));
    }

    /**
     * @param PlacementAsks $asks Process-private index under test
     * @return array<string, PlacementAsk> Physically retained address metadata, including entries not yet visited
     */
    private function pending(PlacementAsks $asks): array
    {
        return new ReflectionProperty(PlacementAsks::class, 'pending')->getValue($asks);
    }
}
