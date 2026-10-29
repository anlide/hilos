<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Error;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusMaintenance;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\StatusAgent;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Singleton shape, atomic diffs and represented owner-only writes. */
final class StatusMaintenanceRuntimeTest extends TestCase
{
    public function testUnknownStateAndNullableRoundTrip(): void
    {
        $row = HilosStatusMaintenance::create();
        self::assertSame([
            'id' => 'current', 'available' => false, 'active' => false, 'announcementKnown' => false,
            'periodId' => null, 'workPublicId' => null, 'title' => null, 'componentKeys' => [],
            'startsAt' => null, 'endsAt' => null, 'startedAt' => null, 'lastObservedAt' => null,
        ], $row->toArray());
        self::assertSame($row->toArray(), HilosStatusMaintenance::fromRow($row->toArray())->toArray());
        $row->applyDiff(['available' => true, 'active' => true, 'periodId' => str_repeat('a', 32), 'startedAt' => 1000]);
        $row->applyDiff(['available' => false]);
        self::assertTrue($row->active);
        self::assertSame(1000, $row->startedAt);
        self::assertSame(str_repeat('a', 32), $row->periodId);
        $row->applyDiff(['lastObservedAt' => 2000]);
        self::assertSame($row->toArray(), HilosStatusMaintenance::fromRow($row->toArray())->toArray());
        self::assertSame('current', $row->getId());
        self::assertSame('hilosStatusMaintenance', $row::getRtCollectionKey());
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed fields and invariants */
    public static function malformed(): iterable
    {
        foreach (['id' => ['other', null], 'available' => [null, 0], 'active' => ['false'], 'announcementKnown' => [1],
            'periodId' => ['12', 12], 'workPublicId' => [str_repeat('b', 32)], 'title' => [false],
            'componentKeys' => [['UPPER'], ['valid', 'valid'], ['key' => 'valid'], [3]],
            'startsAt' => ['1000', -1], 'endsAt' => [false, -1], 'startedAt' => [1.5, -1], 'lastObservedAt' => [-1],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . $index => [[$field => $value]];
            }
        }
        yield 'reversed window' => [['startsAt' => 2000, 'endsAt' => 1000]];
        yield 'reversed observation' => [['startedAt' => 2000, 'lastObservedAt' => 1000]];
    }

    /** @param array<string, mixed> $invalid Malformed full-row fields */
    #[DataProvider('malformed')]
    public function testMalformedRowsRefuse(array $invalid): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosStatusMaintenance::fromRow(array_replace(HilosStatusMaintenance::create()->toArray(), $invalid));
    }

    /** @param array<string, mixed> $invalid Malformed patch fields */
    #[DataProvider('malformed')]
    public function testRejectedDiffIsAtomic(array $invalid): void
    {
        $row = HilosStatusMaintenance::create();
        $before = $row->toArray();
        try {
            $row->applyDiff(array_replace(['title' => 'Changed'], $invalid));
            self::fail('Malformed diff accepted.');
        } catch (InvalidFormatException) {
            self::assertSame($before, $row->toArray());
        }
    }

    public function testRequiredFieldsCannotDisappearAndKeyIsImmutable(): void
    {
        foreach (['id', 'available', 'active', 'announcementKnown', 'componentKeys'] as $required) {
            $row = HilosStatusMaintenance::create()->toArray();
            unset($row[$required]);
            try {
                HilosStatusMaintenance::fromRow($row);
                self::fail('Missing required field accepted.');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
        $row = HilosStatusMaintenance::create();
        $this->expectException(Error::class);
        $row->id = 'other';
    }

    public function testFeatureMountActionsOwnershipAndFreshness(): void
    {
        $previous = Hilos::$rt;
        $owner = new StatusAgent();
        Hilos::$rt = new StatusMaintenanceTestRuntime();
        Hilos::$rt->mountFeatureRuntime([new StatusFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        OwnershipDeclaration::claimRt(StatusAgent::class, $owner->getId());
        ExecutionContext::setCurrentAgentId($owner->getId());
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        try {
            self::assertCount(0, Hilos::$rt->hilosStatusMaintenance);
            Hilos::$rt->hilosStatusMaintenance->actions->ensure();
            self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->available);
            Hilos::$rt->hilosStatusMaintenance['current']->actions->update(available: true, active: true);
            Hilos::$rt->hilosStatusMaintenance->actions->ensure();
            self::assertCount(1, Hilos::$rt->hilosStatusMaintenance);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
            RtStaleness::mark(HilosStatusMaintenance::RT_COLLECTION, ['current'], 1.0);
            self::assertSame(1.0, Hilos::$rt->hilosStatusMaintenance['current']->staleSince());
            ExecutionContext::setCurrentAgentId('foreign');
            $this->expectException(RtTruthSourceWriteNotAllowedException::class);
            Hilos::$rt->hilosStatusMaintenance['current']->actions->update();
        } finally {
            RtStaleness::clear(HilosStatusMaintenance::RT_COLLECTION, ['current']);
            RtTruthSourceRegistry::unregisterAgent($owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($owner->getId()));
            ExecutionContext::setCurrentAgentId(null);
            SourceChangeBus::reset();
            Hilos::$rt = $previous;
        }
    }
}

/** No project mounts; Status owns its whole representation. */
final class StatusMaintenanceTestRuntime extends RtContext
{
    /** Project declares no additional runtime. */
    public function configure(): void
    {
    }
}
