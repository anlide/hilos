<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\State\Collection\HilosCodeSendAttempts as StateHilosCodeSendAttempts;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Actions\Collection\HilosCodeSendAttemptsActions;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\HilosCodeSendAttempts;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Real isolated Runtime mutations and the stream automaton between two channel ends the case moves frames between by hand. */
final class RtOrdinaryDeliveryTest extends TestCase
{
    private const string SECOND_COLLECTION = 'ordinaryOtherNotes';

    private ?string $previousAgent = null;
    /** @var list<RtSyncStreamFrame> Frames the sending end wrote, not yet moved */
    private array $toReceiver = [];
    /** @var list<RtSyncStreamFrame> Answers the receiving end wrote, not yet moved */
    private array $toSender = [];
    /** @var list<RtSyncStreamOperation> Operations the receiving end handed over */
    private array $delivered = [];
    /** Whether the receiving end's host refuses what it is handed */
    private bool $refuse = false;

    /** Restores precisely the grant and source context used by these isolated cases. */
    protected function setUp(): void
    {
        $this->previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::registerDaemon(HilosProbeNote::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(self::SECOND_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(HilosProbeFleetStatus::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
        RtOrdinaryAdmission::reset();
    }

    /** Memory-only Database transactions need no SQL connection. */
    protected function tearDown(): void
    {
        Database::transactionRollback();
        RtTruthSourceRegistry::unregisterDaemon(HilosProbeNote::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(self::SECOND_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(HilosProbeFleetStatus::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        RtOrdinaryAdmission::reset();
        RtStaleness::reset();
        Hilos::$rt = null;
        ExecutionContext::setCurrentAgentId($this->previousAgent);
    }

    /** The framework doors reserve before the store changes: a full source FIFO refuses C, U and D untouched. */
    public function testFrameworkDoorsRefuseEveryKindBeforeTheStoreChangesAndRollbackReturnsTheReservation(): void
    {
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        $doors = $this->mountedDoors();
        $doors->put('w1', 2);

        Database::transactionStart();
        $doors->put('w2', 3);
        $refusals = 0;
        foreach ([
            static fn () => $doors->put('w3', 4),
            static fn () => $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 9]),
            static fn () => $doors->drop('w1'),
            fn () => $this->fleetItem('w1')->actions->patchWithSync([HilosProbeFleetStatus::jobsDone => 9]),
            fn () => $this->fleetItem('w1')->actions->drop(),
        ] as $door) {
            try {
                $door();
            } catch (RtWriteBackpressureException) {
                ++$refusals;
            }
        }
        $this->assertSame(5, $refusals);
        $this->assertFalse($this->fleet()->has('w3'));
        $this->assertSame(2, $this->fleet()->get('w1')?->jobsDone);
        Database::transactionRollback();

        $this->assertFalse($this->fleet()->has('w2'));
        $doors->put('w3', 4);
        $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 9]);
        $doors->drop('w3');
        $this->assertSame(9, $this->fleet()->get('w1')?->jobsDone);
        $this->assertFalse($this->fleet()->has('w3'));
    }

    /** A wipe reserves its whole compound first; longer than the whole quota, it takes the exclusive slot. */
    public function testCompoundWipeIsAdmittedWholeBeforeItsFirstRemoval(): void
    {
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 3));
        $doors = $this->mountedDoors();
        $doors->put('w1', 1);
        $doors->put('w2', 2);

        Database::transactionStart();
        $doors->put('w3', 3);
        try {
            $doors->wipe();
            $this->fail('A wipe of three rows must not fit two free cells');
        } catch (RtWriteBackpressureException) {
            $this->assertSame(3, count($this->fleet()));
        }
        Database::transactionCommit();

        $doors->put('w4', 4);
        $doors->wipe();
        $this->assertSame(0, count($this->fleet()));
        $doors->put('w5', 5);
        $this->assertTrue($this->fleet()->has('w5'));
    }

    /** A patch refused at its second field publishes nothing, holds no reservation, and a valid one rolls back whole. */
    public function testRefusedPatchPublishesNoFieldAndARolledBackPatchComesBackWhole(): void
    {
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        $doors = $this->mountedDoors();
        $doors->put('w1', 2);
        $invalid = [HilosProbeFleetStatus::jobsDone => 9, HilosProbeFleetStatus::rowsSeen => 'many'];

        foreach ([
            static fn () => $doors->patch('w1', $invalid),
            fn () => $this->fleetItem('w1')->actions->patchWithSync($invalid),
        ] as $door) {
            try {
                $door();
                $this->fail('A diff with an int field holding text must be refused');
            } catch (InvalidFormatException) {
                $this->assertSame(2, $this->fleet()->get('w1')?->jobsDone);
                $this->assertSame(0, $this->fleet()->get('w1')?->rowsSeen);
            }
        }

        $row = $this->fleet()->get('w1');
        Database::transactionStart();
        $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 9, HilosProbeFleetStatus::rowsSeen => 5]);
        $this->assertSame(9, $row?->jobsDone);
        Database::transactionRollback();

        $this->assertSame($row, $this->fleet()->get('w1'));
        $this->assertSame(2, $row?->jobsDone);
        $this->assertSame(0, $row?->rowsSeen);
        $this->fleetItem('w1')->actions->patchWithSync([HilosProbeFleetStatus::rowsSeen => 7]);
        $this->assertSame(7, $row?->rowsSeen);
    }

    /** Committed C/U/D facts keep their order behind an unfinished head, with before, previous and the captured origin. */
    public function testCommittedFactsWaitBehindAnUnfinishedHeadWithBeforeValuesAndPrevious(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process());
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $doors = $this->mountedDoors();
        $blocker = $this->holdHead();

        ExecutionContext::withOrigin('accept-1', 'request-1', static function () use ($doors): void {
            $doors->put('w1', 2);
            $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 9]);
            $doors->drop('w1');
        });
        $this->assertNull($queue->head(), 'An unfinished head holds every later committed cell');
        $this->assertSame(4, $queue->pendingOperations());
        unset($blocker);
        $queue->advance(new RtWorkBudget());

        [$created] = $this->take($queue);
        [$updated] = $this->take($queue);
        [$deleted] = $this->take($queue);
        $this->assertSame(TableMutationType::Create, $created->type());
        $this->assertNull($created->before());
        $this->assertSame(SourceChangeProvenance::LocalWrite, $created->provenance());
        $this->assertSame(2, $created->after()?->values()->toNative()[HilosProbeFleetStatus::jobsDone]);
        $this->assertSame('accept-1', $created->context()->toNative()[SourceChange::FIELD_ORIGIN]);
        $this->assertSame('request-1', $created->context()->toNative()[SourceChange::FIELD_ORIGIN_REQUEST_ID]);
        $this->assertSame([HilosProbeFleetStatus::jobsDone => 9], $updated->values()->toNative());
        $this->assertSame([HilosProbeFleetStatus::jobsDone => 2], $updated->previous()->toNative());
        $this->assertSame(2, $updated->before()?->values()->toNative()[HilosProbeFleetStatus::jobsDone]);
        $this->assertSame(TableMutationType::Delete, $deleted->type());
        $this->assertSame(9, $deleted->values()->toNative()[HilosProbeFleetStatus::jobsDone]);
        $this->assertNull($deleted->after());
        $this->retire($queue);
        $this->assertSame(0, $queue->pendingOperations());
    }

    /** A compound of several doors under one transaction commits its cells together; a rollback returns rows and reservations. */
    public function testCompoundUnderOneTransactionCommitsWholeAndRollsBackRowsAndReservations(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 5);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $doors = $this->mountedDoors();
        $doors->put('w1', 1);
        $doors->put('w2', 2);

        Database::transactionStart();
        $this->compound($doors);
        $this->assertSame(4, $queue->pendingOperations(), 'The patch, the wipe of two and the create are all reserved');
        Database::transactionRollback();
        $this->retire($queue);
        $this->assertSame(0, $queue->pendingOperations(), 'A rollback hands every reservation of the compound back');
        $this->assertSame(1, $this->fleet()->get('w1')?->jobsDone);
        $this->assertSame(['w1', 'w2'], $this->fleet()->keys());

        $blocker = $this->holdHead();
        Database::transactionStart();
        $this->compound($doors);
        Database::transactionCommit();
        $this->assertSame(['w3'], $this->fleet()->keys());
        $this->assertSame(5, $queue->pendingOperations());
        unset($blocker);
        $queue->advance(new RtWorkBudget());
        $this->assertSame([TableMutationType::Update], $this->types($this->take($queue)));
        $this->assertSame([TableMutationType::Delete, TableMutationType::Delete], $this->types($this->take($queue)));
        $this->assertSame([TableMutationType::Create], $this->types($this->take($queue)));
        $this->retire($queue);
        $this->assertSame(0, $queue->pendingOperations());
    }

    /** One version longer than the whole allowance takes the exclusive slot alone; its previous text stays with it. */
    public function testLargeVersionTakesTheExclusiveSlotAndHoldsLaterWritesBack(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), 4, 1500);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $notes = $this->mountedDoors()->notes();
        $before = str_repeat('before-Ж', 1800);
        $after = str_repeat('after-Ж', 2100);
        $notes->write('n1', 'node', $before);
        $this->retire($queue);
        $this->assertSame(0, $queue->retainedBytes());
        $blocker = $this->holdHead();

        $notes->patch('n1', [HilosProbeNote::text => $after]);
        $this->assertGreaterThan(strlen($before) + strlen($after), $queue->retainedBytes());
        try {
            $notes->write('n2', 'node', 'small');
            $this->fail('Nothing else of the same source passes the exclusive slot while it is held');
        } catch (RtWriteBackpressureException) {
            $this->assertFalse($this->notes()->has('n2'));
        }
        unset($blocker);
        $queue->advance(new RtWorkBudget());
        [$mutation] = $this->take($queue);
        $this->assertSame([HilosProbeNote::text => $before], $mutation->previous()->toNative());
        $this->assertSame($after, $mutation->after()?->values()->toNative()[HilosProbeNote::text]);
        $this->retire($queue);
        $this->assertSame(0, $queue->retainedBytes());
        $notes->write('n2', 'node', 'small');
        $this->assertTrue($this->notes()->has('n2'));

        $releaseArena = new RtRowArena();
        $retired = RtRowValues::fromNative(str_repeat('retire-', 2000), $releaseArena, RtRowRole::Ordinary);
        $retired->release();
        $releaseArena->advanceRetirement(new RtWorkBudget(2, 0));
        $this->assertSame(0, $releaseArena->retention()->bytes(RtRowRole::Ordinary));
        $this->assertTrue($retired->isRetained(), 'Removing the role is not the end of its descendant retirement work');
        while ($releaseArena->retention()->pendingReleases > 0) {
            $releaseArena->advanceRetirement(new RtWorkBudget(3, 17));
        }
        $this->assertFalse($retired->isRetained());
        $this->assertSame(0, $releaseArena->retention()->physicalBytes);
    }

    /** A normal version that no longer fits is refused rather than moved into the exclusive slot. */
    public function testNormalVersionDoesNotBorrowTheExclusiveSlotWhenRoomRunsOut(): void
    {
        $text = str_repeat('note-', 120);
        $measure = new RtOrdinaryQueue(RtRowArena::process());
        RtOrdinaryAdmission::bindSourceQueue($measure);
        $notes = $this->mountedDoors()->notes();
        $blocker = $this->holdHead();
        $held = $measure->retainedBytes();
        $notes->write('n1', 'node', $text);
        $one = $measure->retainedBytes() - $held;
        unset($blocker);
        $this->retire($measure);
        RtOrdinaryAdmission::reset();

        $queue = new RtOrdinaryQueue(RtRowArena::process(), 8, $held + $one + intdiv($one, 2));
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $blocker = $this->holdHead();
        $notes->write('n2', 'node', $text);
        try {
            $notes->write('n3', 'node', $text);
            $this->fail('A normal-size version cannot take the exclusive slot because the allowance is used up');
        } catch (RtWriteBackpressureException) {
            $this->assertFalse($this->notes()->has('n3'));
            $this->assertSame($held + $one, $queue->retainedBytes());
        }
        unset($blocker);
        $this->retire($queue);
    }

    /** The right is judged before the reservation: a refused operation or set leaves rows and the FIFO untouched. */
    public function testRefusedRightReservesNothingAndChangesNothing(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process());
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $notes = $this->mountedDoors()->notes();
        RtTruthSourceRegistry::register(HilosProbeNote::RT_COLLECTION, TruthSourceKeys::set('node'),
            RtTruthSourceRegistry::DAEMON_SOURCE_ID, TruthSourceOperations::of(TruthSourceOperation::Add));
        $notes->write('n1', 'node', 'born in its set');
        $this->retire($queue);

        foreach ([
            static fn () => $notes->write('n2', 'other-node', 'outside the set'),
            static fn () => $notes->patch('n1', [HilosProbeNote::text => 'an Add grant edits nothing']),
            static fn () => $notes->drop('n1'),
        ] as $door) {
            try {
                $door();
                $this->fail('The grant covers creation in its own set alone');
            } catch (RtTruthSourceWriteNotAllowedException) {
                $this->assertSame(0, $queue->pendingOperations());
            }
        }
        $this->assertFalse($this->notes()->has('n2'));
        $this->assertSame('born in its set', $this->notes()->get('n1')?->text);
    }

    /** A framework action is admitted with its values before its row changes; a list grows in portions; no change reserves nothing. */
    public function testFrameworkActionIsAdmittedBeforeItsRowChangesAndAppendsWithoutSpread(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $this->mountedDoors();
        Hilos::$rt?->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        $runtime = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($runtime);
        $runtime->actions->enterVerifying();
        $runtime->actions->issuePass('first');
        $runtime->actions->issuePass('second');
        $runtime->actions->updateAgentDiagnostics('node', 1, ['keeper'], false);
        $this->assertSame(['first', 'second'], $runtime->passHashes);

        $blocker = $this->holdHead();
        $runtime->actions->issuePass('third');
        $runtime->actions->updateAgentDiagnostics('node', 1, ['keeper'], false);
        foreach ([
            static fn () => $runtime->actions->issuePass('fourth'),
            static fn () => $runtime->actions->enterDeactivating(),
            static fn () => $runtime->actions->updateAgentDiagnostics('node', 2, [], true),
        ] as $write) {
            try {
                $write();
                $this->fail('A full source FIFO refuses a framework action before its row changes');
            } catch (RtWriteBackpressureException) {
                $this->assertSame(['first', 'second', 'third'], $runtime->passHashes);
                $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $runtime->phase);
                $this->assertSame(1, $runtime->stoppedAgentCount);
                $this->assertSame(['keeper'], $runtime->preservedAgentTypes);
            }
        }
        unset($blocker);
        $queue->advance(new RtWorkBudget());
        [$appended] = $this->take($queue);
        $this->assertSame([ProtectedModeRuntime::passHashes => ['first', 'second', 'third']], $appended->values()->toNative());
        $this->assertSame([ProtectedModeRuntime::passHashes => ['first', 'second']], $appended->previous()->toNative());
        $this->retire($queue);
        $this->assertSame(0, $queue->pendingOperations(), 'The repeated diagnostics changed nothing and reserved nothing');
    }

    /** Mandatory cleanup is never refused: past its bounded reserve it is owed once per row and retried, by identity, as the FIFO frees. */
    public function testMandatoryCleanupIsOwedPastItsReserveAndRetriedByRowIdentity(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2, maxCleanupOperations: 1);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $doors = $this->mountedDoors();
        foreach (['w1' => 1, 'w2' => 2, 'w3' => 3] as $id => $jobsDone) {
            $doors->put($id, $jobsDone);
        }
        $blocker = $this->holdHead();
        $doors->put('w4', 4);
        try {
            $doors->put('w5', 5);
            $this->fail('The ordinary room is taken');
        } catch (RtWriteBackpressureException) {
            $this->assertFalse($this->fleet()->has('w5'));
        }

        $this->fleetItem('w1')->actions->cleanUp();
        $this->assertFalse($this->fleet()->has('w1'), 'The service reserve takes a removal the ordinary room cannot');
        $this->fleetItem('w2')->actions->cleanUp();
        $this->fleetItem('w3')->actions->cleanUp();
        $this->fleetItem('w2')->actions->cleanUp();
        $this->assertTrue($this->fleet()->has('w2'));
        $this->assertTrue($this->fleet()->has('w3'));
        $this->assertSame(2, RtOrdinaryAdmission::owedRemovals(), 'A row is owed once, however often its removal is asked for');

        unset($blocker);
        Database::transactionStart();
        $doors->drop('w3');
        $doors->put('w3', 9);
        $this->assertSame(2, RtOrdinaryAdmission::owedRemovals(), 'Nothing is retried before the FIFO hands committed cells on');
        Database::transactionCommit();

        $this->assertFalse($this->fleet()->has('w2'), 'The owed removal is made once the FIFO frees');
        $this->assertSame(9, $this->fleet()->get('w3')?->jobsDone, 'A row replaced under the owed key is not the owed row');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());
        $this->retire($queue);
        $this->assertSame(0, $queue->pendingOperations());
    }

    /** A stream consumer without room holds committed cells, so the FIFO's bound refuses at the door; its room releases them. */
    public function testCommittedCellsWaitForTheStreamConsumersRoomAndOwedCleanupFollowsItsRelease(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 3, maxCleanupOperations: 1);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $room = false;
        RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
            return $room;
        });
        $doors = $this->mountedDoors();
        foreach (['w1' => 1, 'w2' => 2, 'w3' => 3] as $id => $jobsDone) {
            $doors->put($id, $jobsDone);
        }
        $this->assertNotNull($queue->head(), 'A committed cell waits for the consumer');
        try {
            $doors->put('w4', 4);
            $this->fail('A consumer without room leaves the FIFO full');
        } catch (RtWriteBackpressureException) {
            $this->assertFalse($this->fleet()->has('w4'));
        }
        $this->fleetItem('w1')->actions->cleanUp();
        $this->assertFalse($this->fleet()->has('w1'), 'The service reserve takes the first removal');
        $this->fleetItem('w2')->actions->cleanUp();
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals(), 'The held service cell leaves the next removal owed');

        $room = true;
        RtOrdinaryAdmission::handOnCommitted();

        $this->assertNull($queue->head(), 'The consumer took every committed cell');
        $this->assertFalse($this->fleet()->has('w2'), 'The owed removal follows the release');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());
        $doors->put('w4', 4);
        $this->assertTrue($this->fleet()->has('w4'));
    }

    /**
     * A removal larger than the whole service reserve takes the exclusive large slot instead of
     * staying owed for ever; a debt that does not fit now is passed over, and the removals owed
     * after it are retried all the same.
     */
    public function testACleanupLargerThanTheReserveTakesTheLargeSlotAndARefusedDebtHoldsNoOther(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2, maxCleanupOperations: 2, maxCleanupBytes: 16384);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $notes = $this->mountedDoors()->notes();
        $long = str_repeat('a long note ', 3000);
        foreach (['big0' => $long, 'big1' => $long, 's0' => 'short', 's1' => 'short', 's2' => 'short'] as $id => $text) {
            $notes->write($id, 'node', $text);
        }
        $this->noteItem('big0')->actions->cleanUp();
        $this->assertFalse($this->notes()->has('big0'), 'A removal larger than the reserve takes the large slot');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());

        $room = false;
        RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
            return $room;
        });
        $this->noteItem('s0')->actions->cleanUp();
        $this->noteItem('s1')->actions->cleanUp();
        $this->assertFalse($this->notes()->has('s1'), 'The service reserve takes the first removals');
        $this->noteItem('big1')->actions->cleanUp();
        $this->noteItem('s2')->actions->cleanUp();
        $this->assertSame(2, RtOrdinaryAdmission::owedRemovals());
        $this->assertSame(0, $queue->retainedBytes(), 'No version holds the large slot or the ordinary reserve');
        $blocker = RtOrdinaryAdmission::reserve(3);
        $blocker->seal([[]]);

        $room = true;
        RtOrdinaryAdmission::handOnCommitted();
        $this->assertTrue($this->notes()->has('big1'), 'The large slot is held, so the large removal stays owed');
        $this->assertFalse($this->notes()->has('s2'), 'The removal owed after it is retried all the same');
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals());

        unset($blocker);
        $notes->write('s3', 'node', 'short');
        $this->assertFalse($this->notes()->has('big1'), 'The owed large removal takes the slot once it frees');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());
    }

    /**
     * A terminal transition takes the service reserve, so a full ordinary FIFO does not refuse it;
     * one the reserve cannot take either is owed by the row and retried as the FIFO frees, the last
     * one owed wins, and a later ordinary write of the row supersedes it.
     */
    public function testATerminalTransitionTakesTheServiceReserveAndAnOwedOneLosesToALaterWrite(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2, maxCleanupOperations: 1);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $doors = $this->mountedDoors();
        $doors->put('w1', 1);
        $doors->put('w2', 2);
        $room = false;
        RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
            return $room;
        });
        $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 2]);
        $doors->patch('w2', [HilosProbeFleetStatus::jobsDone => 3]);
        try {
            $this->fleetItem('w1')->actions->patchWithSync([HilosProbeFleetStatus::jobsDone => 5]);
            $this->fail('The ordinary room is taken');
        } catch (RtWriteBackpressureException) {
            $this->assertSame(2, $this->fleet()->get('w1')?->jobsDone);
        }
        $this->fleetItem('w1')->actions->finish([HilosProbeFleetStatus::jobsDone => 10]);
        $this->assertSame(10, $this->fleet()->get('w1')?->jobsDone, 'A full ordinary FIFO does not refuse a terminal transition');

        $this->fleetItem('w2')->actions->finish([HilosProbeFleetStatus::jobsDone => 20]);
        $this->fleetItem('w2')->actions->finish([HilosProbeFleetStatus::jobsDone => 21]);
        $this->assertSame(3, $this->fleet()->get('w2')?->jobsDone, 'The service reserve is taken, so the transition is owed');
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals(), 'A row owes one transition, the last');
        $room = true;
        RtOrdinaryAdmission::handOnCommitted();
        $this->assertSame(21, $this->fleet()->get('w2')?->jobsDone, 'The owed transition is made once the FIFO frees');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());

        $room = false;
        $this->fleetItem('w2')->actions->finish([HilosProbeFleetStatus::jobsDone => 30]);
        $this->fleetItem('w1')->actions->finish([HilosProbeFleetStatus::jobsDone => 40]);
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals());
        $this->fleetItem('w1')->actions->patchWithSync([HilosProbeFleetStatus::jobsDone => 50]);
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals(), 'A later ordinary write supersedes the owed transition');
        $room = true;
        RtOrdinaryAdmission::handOnCommitted();
        $this->assertSame(50, $this->fleet()->get('w1')?->jobsDone, 'The last value wins');
    }

    /**
     * A compound of several doors stands whole or not at all, with or without the caller's
     * transaction: replacing a session's code line runs under one, so a creation the FIFO refuses
     * takes the removal back with its reservation instead of leaving no line at all.
     */
    public function testACompoundOfSeveralDoorsWithoutACallersTransactionStandsWholeOrNotAtAll(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $context = new CompoundRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;
        RtTruthSourceRegistry::registerDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        try {
            $attempts = $context->getRtCollection(StateHilosCodeSendAttempt::RT_COLLECTION)?->actions;
            $this->assertInstanceOf(HilosCodeSendAttemptsActions::class, $attempts);
            $attempts->start('session', 'ticket-1', 'email');
            $room = false;
            RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
                return $room;
            });
            $attempts->start('other', 'ticket-9', 'email');
            try {
                $attempts->start('session', 'ticket-2', 'email');
                $this->fail('The FIFO has room for the removal alone');
            } catch (RtWriteBackpressureException) {
                $line = $context->getStateCollection(StateHilosCodeSendAttempt::RT_COLLECTION)?->get('session');
                $this->assertInstanceOf(StateHilosCodeSendAttempt::class, $line, 'The removal went back with the refused creation');
                $this->assertSame('ticket-1', $line->ticket);
            }
            $room = true;
            RtOrdinaryAdmission::handOnCommitted();
            $this->assertSame(0, $queue->pendingOperations(), 'The refused compound\'s reservation went back too');
            $attempts->start('session', 'ticket-2', 'email');
            $this->assertSame('ticket-2', $context->getStateCollection(StateHilosCodeSendAttempt::RT_COLLECTION)?->get('session')?->ticket);
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        }
    }

    /** A removal owed by a source that has since lost the right to remove is dropped, and the row stays. */
    public function testAnOwedRemovalWhoseRightWasRevokedIsDroppedAndRemovesNothing(): void
    {
        $queue = new RtOrdinaryQueue(RtRowArena::process(), maxCleanupOperations: 1);
        RtOrdinaryAdmission::bindSourceQueue($queue);
        $notes = $this->mountedDoors()->notes();
        foreach (['n1', 'n2'] as $id) {
            $notes->write($id, 'node', 'text ' . $id);
        }
        $room = false;
        RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
            return $room;
        });
        $this->noteItem('n1')->actions->cleanUp();
        $this->noteItem('n2')->actions->cleanUp();
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals());

        RtTruthSourceRegistry::unregisterDaemon(HilosProbeNote::RT_COLLECTION);
        RtTruthSourceRegistry::register(HilosProbeNote::RT_COLLECTION, TruthSourceKeys::set('node'),
            RtTruthSourceRegistry::DAEMON_SOURCE_ID, TruthSourceOperations::of(TruthSourceOperation::Add));
        $room = true;
        RtOrdinaryAdmission::handOnCommitted();

        $this->assertTrue($this->notes()->has('n2'), 'The revoked source removes nothing');
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals(), 'and owes nothing any more');
    }

    /** A large update crosses several bounded parts; only an answered `next` lets the following part out, and it reads back whole. */
    public function testLargeOperationTakesSeveralPartsEachCreditedByTheNextAnswer(): void
    {
        $row = [
            'blob' => str_repeat("ab\"\\\n\x01\u{e9}", 5000),
            'list' => [3 => 'x', 7 => [], '08' => 'y', -2 => 1.0],
            'empty' => '',
            'deep' => ['a' => ['b' => ['c' => PHP_INT_MAX, 'd' => null, 'e' => false]]],
            'float' => 1.0E+25,
            'negative' => -0.5,
        ];
        $previous = ['blob' => 'old', 'list' => [], 'float' => 2];
        [$sender, $receiver] = $this->streamPair();
        $sender->send(new RtSyncUpdatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', $row, 'accept-1', 'request-1', $previous));

        $this->assertTrue($sender->pump(new RtWorkBudget(3, 64)), 'A small allowance runs out before the first part is whole');
        $this->assertSame([], $this->toReceiver);
        $parts = 0;
        while (true) {
            $this->drain($sender);
            $this->assertCount(1, $this->toReceiver, 'One part is out at a time');
            $part = array_shift($this->toReceiver);
            $this->assertSame([1, $parts], [$part->sequence, $part->part]);
            $this->assertLessThanOrEqual(RtSyncStreamFrame::MAX_PART_BYTES, strlen($part->data));
            $this->assertFalse($sender->pump(new RtWorkBudget()));
            $this->assertSame([], $this->toReceiver, 'Writing a part is no credit for the next');
            $receiver->receive($part);
            $this->drain($receiver);
            ++$parts;
            if ($part->final) {
                break;
            }
            $this->assertSame([[1, $part->part, RtSyncStreamStatus::Next]], $this->answers($this->toSender));
            $sender->receive(array_shift($this->toSender));
        }

        $this->assertGreaterThanOrEqual(3, $parts);
        $this->assertCount(1, $this->delivered);
        $data = $this->delivered[0]->signalData();
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $data);
        $this->assertSame($row, $data->row);
        $this->assertSame($previous, $data->previous);
        $this->assertSame(['accept-1', 'request-1', null, false], [$data->origin, $data->originRequestId,
            $this->delivered[0]->originNodeId, $this->delivered[0]->partialOwner]);
        $this->assertFalse($sender->isIdle(), 'The operation is not done before the receiver applied it');
        $this->assertSame([[1, $parts - 1, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
        $sender->receive(array_shift($this->toSender));
        $this->assertTrue($sender->isIdle());
    }

    /** An old generation, a repeated final, a part from ahead and a stray answer move nothing; the next operation follows in order. */
    public function testGapObsoleteAndDuplicatePartsLeaveThePositionAndTheNextOperationFollows(): void
    {
        [$sender, $receiver] = $this->streamPair();
        [$stranger] = $this->streamPair();
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1]));
        $this->drain($sender);
        $first = array_shift($this->toReceiver);
        $receiver->receive($first);
        $this->drain($receiver);
        $this->assertCount(1, $this->delivered);
        $applied = array_shift($this->toSender);

        $receiver->receive($first);
        $receiver->receive(RtSyncStreamFrame::part($stranger->streamId, 1, 0, true, '{}'));
        $this->drain($receiver);
        $this->assertCount(1, $this->delivered, 'A repeated final is not applied again; another generation is not read');
        $this->assertSame([], $this->toSender);

        $receiver->receive(RtSyncStreamFrame::part($sender->streamId, 5, 0, true, '{}'));
        $this->assertSame([[5, 0, RtSyncStreamStatus::Gap]], $this->answers($this->toSender));
        $this->toSender = [];

        $sender->receive(RtSyncStreamFrame::ack($sender->streamId, 9, 0, RtSyncStreamStatus::Applied));
        $sender->receive(RtSyncStreamFrame::ack($stranger->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $this->assertFalse($sender->isIdle(), 'An answer nobody awaits completes nothing');
        $sender->receive($applied);
        $this->assertTrue($sender->isIdle());

        $this->refuse = true;
        $sender->send(new RtSyncDeletedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1'));
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 2]));
        $this->drain($sender);
        $this->assertSame([2, 3], array_map(static fn (RtSyncStreamFrame $part) => $part->sequence, $this->toReceiver),
            'Small operations follow each other without waiting for their answers');
        foreach ($this->toReceiver as $part) {
            $receiver->receive($part);
        }
        $this->toReceiver = [];
        $this->drain($receiver);
        $this->assertSame(['w1', 'w1', 'w2'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered));
        $this->assertSame([[2, 0, RtSyncStreamStatus::Refused], [3, 0, RtSyncStreamStatus::Refused]], $this->answers($this->toSender));
        foreach ($this->toSender as $answer) {
            $sender->receive($answer);
        }
        $this->assertTrue($sender->isIdle(), 'A refusal closes its operation and is not taken for success');

        $receiver->receive(RtSyncStreamFrame::part($sender->streamId, 4, 0, true, '{"signalType":'));
        $this->drain($receiver);
        $this->assertSame([4, 0, RtSyncStreamStatus::Refused], $this->answers($this->toSender)[2], 'A part that does not read is refused');
    }

    /** An apply the receiving host refuses closes that operation alone: the next independent one on the channel is applied. */
    public function testARefusedApplyClosesOnlyItsOwnOperationAndTheNextIsApplied(): void
    {
        [$sender, $receiver] = $this->streamPair();
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1]));
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 2]));
        $this->drain($sender);
        [$first, $second] = $this->toReceiver;
        $this->toReceiver = [];

        $this->refuse = true;
        $receiver->receive($first);
        $this->drain($receiver);
        $this->refuse = false;
        $receiver->receive($second);
        $this->drain($receiver);

        $this->assertSame(['w1', 'w2'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered));
        $this->assertSame([[1, 0, RtSyncStreamStatus::Refused], [2, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
        foreach ($this->toSender as $answer) {
            $sender->receive($answer);
        }
        $this->assertTrue($sender->isIdle(), 'The refusal takes its window slot back like an applied answer');
    }

    /**
     * A refused operation holds its row: the row's later operations are refused unread, while
     * other rows - and the same id in another collection - go on; a snapshot of the collection
     * published on the receiving end lifts the hold.
     */
    public function testARefusedOperationHoldsItsRowUntilASnapshotOfItsCollectionRecoversIt(): void
    {
        [$sender, $receiver] = $this->streamPair();
        $this->refuse = true;
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1]));
        $this->carry($sender, $receiver);
        $this->refuse = false;
        $sender->send(new RtSyncUpdatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 2], previous: ['jobsDone' => 1]));
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 3]));
        $sender->send(new RtSyncCreatedSignalData(self::SECOND_COLLECTION, 'w1', ['jobsDone' => 4]));
        $this->carry($sender, $receiver);

        $this->assertSame([[HilosProbeFleetStatus::RT_COLLECTION, 'w1'], [HilosProbeFleetStatus::RT_COLLECTION, 'w2'],
            [self::SECOND_COLLECTION, 'w1']], $this->deliveredRows(), 'The held row\'s update never reaches the host');
        $this->assertSame([[1, 0, RtSyncStreamStatus::Refused], [2, 0, RtSyncStreamStatus::Refused], [3, 0, RtSyncStreamStatus::Applied],
            [4, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
        $this->toSender = [];

        $receiver->recover(self::SECOND_COLLECTION);
        $sender->send(new RtSyncDeletedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1'));
        $this->carry($sender, $receiver);
        $this->assertSame([[5, 0, RtSyncStreamStatus::Refused]], $this->answers($this->toSender), 'Another collection\'s snapshot lifts nothing');
        $this->toSender = [];

        $receiver->recover(HilosProbeFleetStatus::RT_COLLECTION);
        $sender->send(new RtSyncDeletedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1'));
        $this->carry($sender, $receiver);
        $this->assertSame([[6, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
        $this->assertSame([HilosProbeFleetStatus::RT_COLLECTION, 'w1'], $this->deliveredRows()[3], 'The recovered row is applied again');
    }

    /**
     * The receiving end's copy of a held row is frozen since the refusal that held it, and says so; the
     * other rows, the same id in another collection included, stay current. The recovery lifts the mark.
     */
    public function testAHeldRowIsMarkedStaleOnTheHoldingEndUntilItsRecovery(): void
    {
        [$sender, $receiver] = $this->streamPair();
        $before = microtime(true);
        $this->refuse = true;
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1]));
        $this->carry($sender, $receiver);
        $this->refuse = false;
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 2]));
        $sender->send(new RtSyncCreatedSignalData(self::SECOND_COLLECTION, 'w1', ['jobsDone' => 3]));
        $sender->send(new RtSyncUpdatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 4], previous: ['jobsDone' => 1]));
        $this->carry($sender, $receiver);

        $frozenSince = RtStaleness::staleSince(HilosProbeFleetStatus::RT_COLLECTION, 'w1');
        $this->assertNotNull($frozenSince, 'The held row is frozen');
        $this->assertGreaterThanOrEqual($before, $frozenSince);
        $this->assertNull(RtStaleness::staleSince(HilosProbeFleetStatus::RT_COLLECTION, 'w2'));
        $this->assertNull(RtStaleness::staleSince(self::SECOND_COLLECTION, 'w1'));
        $this->assertSame(['w1'], $receiver->heldRows(HilosProbeFleetStatus::RT_COLLECTION));

        $receiver->recover(self::SECOND_COLLECTION);
        $this->assertSame($frozenSince, RtStaleness::staleSince(HilosProbeFleetStatus::RT_COLLECTION, 'w1'),
            'Another collection\'s recovery lifts nothing, and a refusal of a held row keeps the moment it first froze');

        $receiver->recover(HilosProbeFleetStatus::RT_COLLECTION);
        $this->assertNull(RtStaleness::staleSince(HilosProbeFleetStatus::RT_COLLECTION, 'w1'));
        $this->assertSame([], $receiver->heldRows(HilosProbeFleetStatus::RT_COLLECTION));
    }

    /**
     * A float JSON cannot carry is refused at the row door, before anything is sent; one that came
     * past the door is dropped before its first part. Neither spends a sequence, and the ordinary
     * operation after them is applied.
     */
    public function testANonFiniteFloatIsRefusedBeforeItsOperationAndTheNextOneIsApplied(): void
    {
        [$sender, $receiver] = $this->streamPair();
        foreach ([NAN, INF, -INF] as $float) {
            try {
                $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w0', ['jobsDone' => 1, 'ratio' => $float]));
                $this->fail('A float that is not finite must be refused before it is streamed');
            } catch (InvalidFormatException) {
                $this->assertTrue($sender->isIdle(), 'The refused operation took no place in the stream');
            }
        }
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w0', $this->rowPastTheDoor('')));
        $this->drain($sender);
        $this->assertSame([], $this->toReceiver, 'One that came past the door is dropped before its first part');
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1, 'ratio' => 0.5]));
        $this->drain($sender);
        foreach ($this->toReceiver as $part) {
            $receiver->receive($part);
        }
        $this->toReceiver = [];
        $this->drain($receiver);

        $this->assertSame(['w1'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered));
        $this->assertSame([[1, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
    }

    /**
     * An operation that cannot be written past a part already out closes its sequence with a final
     * part the receiver refuses, instead of leaving a gap: the operation after it is applied.
     */
    public function testAnOperationUnwritablePastItsFirstPartClosesItsSequenceAndTheNextOneIsApplied(): void
    {
        [$sender, $receiver] = $this->streamPair();
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1',
            $this->rowPastTheDoor(str_repeat('row ', RtSyncStreamFrame::MAX_PART_BYTES))));
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 2]));
        $this->drain($sender);
        $this->assertSame([[1, 0, false]], array_map(static fn (RtSyncStreamFrame $part) => [$part->sequence, $part->part, $part->final],
            $this->toReceiver), 'The first part is out before the float that cannot be written');
        for ($round = 0; $round < 8 && $this->toReceiver !== []; $round++) {
            foreach ($this->toReceiver as $part) {
                $receiver->receive($part);
            }
            $this->toReceiver = [];
            $this->drain($receiver);
            foreach ($this->toSender as $answer) {
                $sender->receive($answer);
            }
            $this->toSender = [];
            $this->drain($sender);
        }
        $this->drain($sender);

        $this->assertSame(['w2'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered),
            'The unwritable operation is not applied, the next one is');
        $this->assertTrue($sender->isIdle(), 'The closed sequence was answered refused and the next one applied');
    }

    /**
     * A host applying in portions hands its apply back: the channel advances it on the allowance of
     * its pumps, answers the operation only when the apply ends, and reads the next operation after it.
     */
    public function testAPortionedApplyRunsOnThePumpsAllowanceAndHoldsTheNextOperationBehindIt(): void
    {
        [$sender] = $this->streamPair();
        $steps = [];
        $receiver = new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->toSender[] = $frame;
            },
            static function (RtSyncStreamOperation $operation, Closure $settled) use (&$steps): Generator {
                for ($step = 1; $step <= 10; ++$step) {
                    yield new RtWork();
                    $steps[] = [$operation->stateId, $step];
                }
                $settled(true);
            },
            static function (): void {
            },
        );
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w1', ['jobsDone' => 1]));
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w2', ['jobsDone' => 2]));
        $this->drain($sender);
        foreach ($this->toReceiver as $part) {
            $receiver->receive($part);
        }
        $this->toReceiver = [];

        $pumps = 0;
        while ($this->toSender === []) {
            $this->assertLessThan(10, count($steps), 'No answer while the first apply runs');
            $receiver->pump(new RtWorkBudget(4, 4096));
            $this->assertLessThan(64, ++$pumps);
        }
        $this->assertGreaterThan(2, $pumps, 'The apply took more than one allowance');
        $this->assertSame([[1, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));

        $this->drain($receiver);
        $expected = [];
        foreach (['w1', 'w2'] as $id) {
            foreach (range(1, 10) as $step) {
                $expected[] = [$id, $step];
            }
        }
        $this->assertSame($expected, $steps, 'The next operation is applied only after the first one ended');
        $this->assertSame([[1, 0, RtSyncStreamStatus::Applied], [2, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
        $this->assertTrue($receiver->isIdle());
    }

    /**
     * A part lost on a live channel leaves its position standing: every later operation is answered
     * gap and none is applied, and nothing in the channel replaces it - only a new channel instance,
     * a new link, carries operations again.
     */
    public function testALostPartLeavesTheChannelAnsweringGapUntilANewInstanceReplacesIt(): void
    {
        [$sender, $receiver] = $this->streamPair();
        foreach (['w1' => 1, 'w2' => 2, 'w3' => 3] as $id => $jobsDone) {
            $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, $id, ['jobsDone' => $jobsDone]));
        }
        $this->drain($sender);
        $this->assertCount(3, $this->toReceiver);
        $receiver->receive($this->toReceiver[0]);
        $this->drain($receiver);
        $receiver->receive($this->toReceiver[2]);
        $this->toReceiver = [];
        $sender->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w4', ['jobsDone' => 4]));
        $this->drain($sender);
        $receiver->receive(array_shift($this->toReceiver));
        $this->drain($receiver);

        $this->assertSame(['w1'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered),
            'Nothing after the lost operation is applied');
        $this->assertSame([[1, 0, RtSyncStreamStatus::Applied], [3, 0, RtSyncStreamStatus::Gap], [4, 0, RtSyncStreamStatus::Gap]],
            $this->answers($this->toSender));
        foreach ($this->toSender as $answer) {
            $sender->receive($answer);
        }
        $this->toSender = [];
        $this->assertFalse($sender->isIdle(), 'The lost operation is never answered on this channel');

        [$replacement, $replacementReceiver] = $this->streamPair();
        $replacement->send(new RtSyncCreatedSignalData(HilosProbeFleetStatus::RT_COLLECTION, 'w5', ['jobsDone' => 5]));
        $this->drain($replacement);
        $replacementReceiver->receive(array_shift($this->toReceiver));
        $this->drain($replacementReceiver);
        $this->assertSame(['w1', 'w5'], array_map(static fn (RtSyncStreamOperation $operation) => $operation->stateId, $this->delivered));
        $this->assertSame([[1, 0, RtSyncStreamStatus::Applied]], $this->answers($this->toSender));
    }

    /**
     * A row no door admits - a text, then a NAN - built past the door, as only a broken writer
     * could hand one to a stream.
     *
     * @param string $blob Text written before the float
     * @return RtRowValues Field map whose float the encoder cannot write
     */
    private function rowPastTheDoor(string $blob): RtRowValues
    {
        $arena = RtRowArena::process();

        return RtWorkDriver::runSynchronously((static function () use ($arena, $blob): Generator {
            $writer = new RtRowWriter($arena, RtRowRole::Resident);
            yield from $writer->write('blob', yield from RtRowWriter::encode($blob, $arena));
            yield from $writer->write('ratio', new RtRowValues($arena, $arena->allocate(RtRowKind::Scalar, NAN, PHP_INT_SIZE),
                RtRowRole::Transient));

            return yield from $writer->finish();
        })());
    }

    /**
     * Two channel ends whose frames the case moves by hand: nothing is pumped but by the case.
     *
     * @return list<RtSyncStreamChannel> Sender and receiver
     */
    private function streamPair(): array
    {
        $sender = new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->toReceiver[] = $frame;
            },
            static function (): void {
            },
            static function (): void {
            },
        );
        $receiver = new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->toSender[] = $frame;
            },
            function (RtSyncStreamOperation $operation, Closure $settled): void {
                $this->delivered[] = $operation;
                $settled(!$this->refuse);
            },
            static function (): void {
            },
        );

        return [$sender, $receiver];
    }

    /**
     * Moves what the sender has out to the receiver and lets it read and answer; the answers stay.
     *
     * @param RtSyncStreamChannel $sender Sending end
     * @param RtSyncStreamChannel $receiver Receiving end
     */
    private function carry(RtSyncStreamChannel $sender, RtSyncStreamChannel $receiver): void
    {
        $this->drain($sender);
        foreach ($this->toReceiver as $part) {
            $receiver->receive($part);
        }
        $this->toReceiver = [];
        $this->drain($receiver);
    }

    /** @return list<array{string, string}> Collection and row of each operation handed to the receiving host */
    private function deliveredRows(): array
    {
        return array_map(static fn (RtSyncStreamOperation $operation) => [$operation->collectionKey, $operation->stateId], $this->delivered);
    }

    /**
     * @param RtSyncStreamChannel $channel Channel end to advance until only credit or input can move it
     */
    private function drain(RtSyncStreamChannel $channel): void
    {
        while ($channel->pump(new RtWorkBudget())) {
        }
    }

    /**
     * @param list<RtSyncStreamFrame> $frames Answers written
     * @return list<array{int, int, ?RtSyncStreamStatus}> Their positions and statuses
     */
    private function answers(array $frames): array
    {
        return array_map(static fn (RtSyncStreamFrame $frame) => [$frame->sequence, $frame->part, $frame->status], $frames);
    }

    /**
     * @return OrdinaryDoorsRtActions Fleet doors of a freshly mounted context
     */
    private function mountedDoors(): OrdinaryDoorsRtActions
    {
        $context = new OrdinaryDoorsRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;
        $actions = $context->collection()->actions;
        $this->assertInstanceOf(OrdinaryDoorsRtActions::class, $actions);

        return $actions;
    }

    private function fleet(): HilosProbeFleetStatuses
    {
        $states = Hilos::$rt?->getStateCollection(HilosProbeFleetStatus::RT_COLLECTION);
        $this->assertInstanceOf(HilosProbeFleetStatuses::class, $states);

        return $states;
    }

    private function notes(): HilosProbeNotes
    {
        $states = Hilos::$rt?->getStateCollection(HilosProbeNote::RT_COLLECTION);
        $this->assertInstanceOf(HilosProbeNotes::class, $states);

        return $states;
    }

    private function fleetItem(string $id): OrdinaryDoorsRtItem
    {
        $context = Hilos::$rt;
        $this->assertInstanceOf(OrdinaryDoorsRtContext::class, $context);
        $item = $context->collection()[$id];
        $this->assertInstanceOf(OrdinaryDoorsRtItem::class, $item);

        return $item;
    }

    /**
     * @param string $id Probe note key
     * @return OrdinaryDoorsRtItem Its view item with the probe doors
     */
    private function noteItem(string $id): OrdinaryDoorsRtItem
    {
        $context = Hilos::$rt;
        $this->assertInstanceOf(OrdinaryDoorsRtContext::class, $context);
        $item = $context->getRtCollection(HilosProbeNote::RT_COLLECTION)[$id];
        $this->assertInstanceOf(OrdinaryDoorsRtItem::class, $item);

        return $item;
    }

    /**
     * An admitted cell that never settles, at the head of the bound FIFO: every later committed
     * cell waits behind it, so a case can read them before the hand-off releases them.
     *
     * @return RtOrdinaryAdmission Unsettled head; dropping it hands its reservation back
     */
    private function holdHead(): RtOrdinaryAdmission
    {
        $blocker = RtOrdinaryAdmission::reserve(1);
        $blocker->seal([[]]);

        return $blocker;
    }

    /**
     * Local test consumer only: no wire frames or successful ACK are invented.
     *
     * @param RtOrdinaryQueue $queue Committed source prefix
     * @return list<RtMutation> Individually retained immutable facts
     */
    private function take(RtOrdinaryQueue $queue): array
    {
        $cursor = $queue->head();
        $this->assertNotNull($cursor);
        $facts = [];
        while (($page = $this->drive($cursor->advance())) !== null) {
            $facts[] = new RtMutation($queue->arena, $page);
        }
        $cursor->cancel();
        $queue->releaseHead();
        $queue->advance(new RtWorkBudget());

        return $facts;
    }

    /**
     * One compound under the caller's transaction: a patch, a wipe of every row and a create.
     *
     * @param OrdinaryDoorsRtActions $doors Mounted fleet doors
     */
    private function compound(OrdinaryDoorsRtActions $doors): void
    {
        $doors->patch('w1', [HilosProbeFleetStatus::jobsDone => 7]);
        $doors->wipe();
        $doors->put('w3', 3);
        $this->assertSame(['w3'], $this->fleet()->keys());
    }

    /**
     * @param list<RtMutation> $facts Taken facts
     * @return list<TableMutationType> Their operations in order
     */
    private function types(array $facts): array
    {
        return array_map(static fn (RtMutation $mutation) => $mutation->type(), $facts);
    }

    /**
     * @param RtOrdinaryQueue $queue Existing source owner
     */
    private function retire(RtOrdinaryQueue $queue): void
    {
        while ($queue->arena->retention()->pendingReleases > 0) {
            $queue->arena->advanceRetirement(new RtWorkBudget(37, 233));
            $queue->advance(new RtWorkBudget(5, 17));
        }
        $queue->advance(new RtWorkBudget());
    }

    /**
     * @param Generator $operation Real segmented operation
     * @return mixed Completed domain value
     */
    private function drive(Generator $operation): mixed
    {
        $driver = new RtWorkDriver($operation);
        while (!$driver->isFinished()) {
            $driver->advance(new RtWorkBudget(19, 127));
        }

        return $driver->result();
    }
}

/** Mounts the bound probe fleet rows behind doors these cases call directly. */
final class OrdinaryDoorsRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[HilosProbeFleetStatus::RT_COLLECTION] = HilosProbeFleetStatuses::init();
        $this->_stateCollections[HilosProbeNote::RT_COLLECTION] = HilosProbeNotes::init();
        $this->setRepresent(
            HilosProbeFleetStatus::RT_COLLECTION,
            OrdinaryDoorsRtCollection::class,
            OrdinaryDoorsRtActions::class,
            OrdinaryDoorsRtItemActions::class,
        );
        $this->setRepresent(
            HilosProbeNote::RT_COLLECTION,
            OrdinaryDoorsRtCollection::class,
            OrdinaryNotesRtActions::class,
            OrdinaryDoorsRtItemActions::class,
        );
    }

    public function collection(): OrdinaryDoorsRtCollection
    {
        $collection = $this->getRtCollection(HilosProbeFleetStatus::RT_COLLECTION);
        if (!$collection instanceof OrdinaryDoorsRtCollection) {
            throw new LogicException('The probe fleet view is not mounted');
        }

        return $collection;
    }
}

final class OrdinaryDoorsRtCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new OrdinaryDoorsRtItem($state);
    }
}

final class OrdinaryDoorsRtItem extends RtItem
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

final class OrdinaryDoorsRtActions extends RtActions
{
    public function put(string $id, int $jobsDone): void
    {
        $state = HilosProbeFleetStatus::create($id);
        $state->jobsDone = $jobsDone;
        $this->addStateToCollection($state);
    }

    /**
     * @param array<string, mixed> $diff Changed fields
     */
    public function patch(string $id, array $diff): void
    {
        $state = $this->getStateCollection()->get($id) ?? throw new LogicException('No probe row ' . $id);
        $this->applyDiffToState($state, $diff);
    }

    public function drop(string $id): void
    {
        $this->removeStateFromCollection($id);
    }

    public function wipe(): void
    {
        $this->clearAllStates();
    }

    public function notes(): OrdinaryNotesRtActions
    {
        $context = Hilos::$rt;
        if (!$context instanceof OrdinaryDoorsRtContext) {
            throw new LogicException('The probe views are not mounted');
        }
        $actions = $context->getRtCollection(HilosProbeNote::RT_COLLECTION)?->actions;
        if (!$actions instanceof OrdinaryNotesRtActions) {
            throw new LogicException('The probe note doors are not mounted');
        }

        return $actions;
    }
}

/** The text-carrying probe notes behind the same doors, in sets by node. */
final class OrdinaryNotesRtActions extends RtActions
{
    public function write(string $noteId, string $nodeId, string $text): void
    {
        $this->addStateToCollection(HilosProbeNote::create($noteId, $nodeId, $text));
    }

    /**
     * @param array<string, mixed> $diff Changed fields
     */
    public function patch(string $noteId, array $diff): void
    {
        $state = $this->getStateCollection()->get($noteId) ?? throw new LogicException('No probe note ' . $noteId);
        $this->applyDiffToState($state, $diff);
    }

    public function drop(string $noteId): void
    {
        $this->removeStateFromCollection($noteId);
    }
}

final class OrdinaryDoorsRtItemActions extends RtItemActions
{
    /**
     * @param array<string, mixed> $diff Changed fields
     */
    public function patchWithSync(array $diff): void
    {
        $this->applyDiffWithSync($diff);
    }

    public function drop(): void
    {
        $this->remove();
    }

    public function cleanUp(): void
    {
        $this->removeAsCleanup();
    }

    /**
     * @param array<string, mixed> $diff Terminal values
     */
    public function finish(array $diff): void
    {
        $this->applyDiffAsCleanup($diff);
    }
}

/** Mounts the framework's code send lines, whose replacement is a compound of two doors. */
final class CompoundRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[StateHilosCodeSendAttempt::RT_COLLECTION] = StateHilosCodeSendAttempts::init();
        $this->setRepresent(StateHilosCodeSendAttempt::RT_COLLECTION, HilosCodeSendAttempts::class, HilosCodeSendAttemptsActions::class);
    }
}
