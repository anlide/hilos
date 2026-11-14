<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * Ordinary RT notifications carry segmented values from the writer to every reader: the source
 * fact and the signal hold the writer's own lease, the forwarding readers pass it on, and only an
 * explicit public array read materializes it. The portioned update publishes a draft or nothing.
 */
final class RtSegmentedCarrierTest extends TestCase
{
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testRuntimeFactCarriesTheWritersLeaseAndDatabaseFactStaysAnArray(): void
    {
        $row = RtRowValues::fromNative(['noteId' => 'n1', 'text' => 'Ada']);
        $previous = RtRowValues::fromNative(['text' => 'Grace']);

        $created = SourceChange::rtCreated('notes', 'n1', $row);
        $updated = SourceChange::rtUpdated('notes', 'n1', $row, previous: $previous);
        $fromArray = SourceChange::rtUpdated('notes', 'n1', ['text' => 'Ada'], previous: ['text' => 'Grace']);
        $database = SourceChange::dbUpdated('notes', '1', ['text' => 'Ada'], previous: ['text' => 'Grace']);

        $this->assertSame($row, $created->rtRowValues());
        $this->assertSame($row, $created->carriedRow());
        $this->assertTrue($created->rtPreviousValues()?->isEmptyMap());
        $this->assertSame($previous, $updated->rtPreviousValues());
        $this->assertSame(['noteId' => 'n1', 'text' => 'Ada'], $updated->row);
        $this->assertSame(['text' => 'Grace'], $updated->previous);
        $this->assertSame(['text' => 'Ada'], $fromArray->rtRowValues()?->toNative());
        $this->assertSame(['text' => 'Grace'], $fromArray->rtPreviousValues()?->toNative());
        $this->assertNull($database->rtRowValues());
        $this->assertNull($database->rtPreviousValues());
        $this->assertSame($updated->toArray(), SourceChange::fromArray($updated->toArray())->toArray());
    }

    public function testSignalDataHandsTheSameSegmentsOnAndKeepsItsWireShape(): void
    {
        $row = RtRowValues::fromNative(['jobsDone' => 3]);
        $previous = RtRowValues::fromNative(['jobsDone' => 2]);

        $segmented = new RtSyncUpdatedSignalData('fleet', 'w1', $row, 'ak-1', 'rq-1', previous: $previous);
        $native = new RtSyncUpdatedSignalData('fleet', 'w1', ['jobsDone' => 3], 'ak-1', 'rq-1', previous: ['jobsDone' => 2]);

        $this->assertSame($row, $segmented->rowValues());
        $this->assertSame($previous, $segmented->previousValues());
        $this->assertSame($row, $segmented->carriedRow());
        $this->assertSame(['jobsDone' => 3], $native->rowValues()->toNative());
        $this->assertSame($native->toArray(), $segmented->toArray());
        $this->assertSame(['jobsDone' => 3], $segmented->row);
        $this->assertSame(['jobsDone' => 2], RtSyncUpdatedSignalData::fromArray($segmented->toArray())->previous);
    }

    public function testCollectionAnnouncesABoundRowSegmentedAndKeepsItsFieldsWhole(): void
    {
        $collection = $this->arrangeCollection();
        $heard = [];
        SourceChangeBus::subscribe(new class ($heard) implements SourceChangeSubscriberInterface {
            /**
             * @param list<SourceChange> $heard Facts heard, in order
             */
            public function __construct(private array &$heard)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                $this->heard[] = $change;
            }
        });

        $this->arrangeRow('w1', 4);
        $status = $collection->get('w1');
        $this->assertInstanceOf(HilosProbeFleetStatus::class, $status);
        RtSyncApplicator::applyDeleted(new RtSyncDeletedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1'));

        $this->assertCount(2, $heard);
        $this->assertInstanceOf(RtRowValues::class, $heard[0]->carriedRow());
        $this->assertSame($status->toArray(), $heard[0]->row);
        $this->assertInstanceOf(RtRowValues::class, $heard[1]->carriedRow());
        $this->assertSame($status->toArray(), $heard[1]->row);
    }

    public function testPortionedUpdateRefusedAtItsSecondFieldPublishesNothing(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('w1', 2);
        $row = $collection->get('w1');
        $this->assertInstanceOf(HilosProbeFleetStatus::class, $row);

        $refusal = null;
        try {
            RtWorkDriver::runSynchronously(RtSyncApplicator::applyUpdatedInPortions(new RtSyncUpdatedSignalData(
                HilosProbeFleetStatus::RT_COLLECTION,
                'w1',
                RtRowValues::fromNative([HilosProbeFleetStatus::jobsDone => 9, HilosProbeFleetStatus::rowsSeen => 'many']),
            )));
        } catch (InvalidFormatException $failure) {
            $refusal = $failure;
        }

        $this->assertInstanceOf(InvalidFormatException::class, $refusal);
        $this->assertSame($row, $collection->get('w1'));
        $this->assertSame(2, $row->jobsDone);
        $this->assertSame(0, $row->rowsSeen);
        $this->assertTrue($this->pendingChanges($row)->isEmptyMap());
    }

    public function testPortionedUpdatePublishesTheWholeDraftInSmallPassesAndMovesTheBaseline(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('w1', 2);
        $row = $collection->get('w1');
        $this->assertInstanceOf(HilosProbeFleetStatus::class, $row);

        $driver = new RtWorkDriver(RtSyncApplicator::applyUpdatedInPortions(new RtSyncUpdatedSignalData(
            HilosProbeFleetStatus::RT_COLLECTION,
            'w1',
            RtRowValues::fromNative([HilosProbeFleetStatus::jobsDone => 9, HilosProbeFleetStatus::rowsSeen => 5]),
        )));
        $passes = 0;
        while (!$driver->isFinished()) {
            $driver->advance(new RtWorkBudget(4));
            ++$passes;
            if (!$driver->isFinished()) {
                $this->assertSame(2, $row->jobsDone);
                $this->assertSame(0, $row->rowsSeen);
            }
        }

        $this->assertGreaterThan(1, $passes);
        $this->assertSame($row, $collection->get('w1'));
        $this->assertSame(9, $row->jobsDone);
        $this->assertSame(5, $row->rowsSeen);
        $this->assertTrue($this->pendingChanges($row)->isEmptyMap());
    }

    private function arrangeCollection(): RtStates
    {
        Hilos::$rt = new SegmentedCarrierTestRtContext();
        Hilos::$rt->configure();
        $collection = Hilos::$rt->getStateCollection(HilosProbeFleetStatus::RT_COLLECTION);
        $this->assertInstanceOf(RtStates::class, $collection);

        return $collection;
    }

    private function arrangeRow(string $id, int $jobsDone): void
    {
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            HilosProbeFleetStatus::RT_COLLECTION,
            $id,
            [
                HilosProbeFleetStatus::workerIndex => $id,
                HilosProbeFleetStatus::jobsDone => $jobsDone,
                HilosProbeFleetStatus::rowsSeen => 0,
                HilosProbeFleetStatus::updatedAt => 1710000000,
            ],
        ));
    }

    private function pendingChanges(HilosProbeFleetStatus $row): RtRowValues
    {
        return RtWorkDriver::runSynchronously($row->freezeRow()->changes(RtRowArena::process()))->diff;
    }
}

/** One named probe collection, enough for an announcing store. */
final class SegmentedCarrierTestRtContext extends RtContext
{
    public function configure(): void
    {
        $collection = HilosProbeFleetStatuses::init();
        $collection->setCollectionName(HilosProbeFleetStatus::RT_COLLECTION);
        $this->_stateCollections[HilosProbeFleetStatus::RT_COLLECTION] = $collection;
    }
}
