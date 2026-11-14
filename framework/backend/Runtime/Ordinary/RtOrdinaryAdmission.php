<?php

declare(strict_types=1);

namespace Hilos\Runtime\Ordinary;

use Closure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Exception\State\RtRowCodecNotSupportedException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtPersistentIndex;
use Hilos\Runtime\Snapshot\RtPreparedIdentity;
use Hilos\Runtime\Snapshot\RtPreparedRow;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use LogicException;
use WeakMap;

/**
 * One framework write door's admission on its source's ordinary FIFO.
 *
 * The whole compound is reserved before the first live write and sealed with its prepared
 * immutable operations ({@see RtMutation}: before/after, origin and previous values), so a refusal
 * - {@see RtWriteBackpressureException} - reaches the caller while nothing has changed. The live
 * write follows; at the SQL commit, or at once with no transaction open, the already reserved cell
 * becomes mandatory without a second admission, and a rollback hands the reservation back.
 *
 * The FIFOs are process-local copy machinery, not application state: one per writing agent of
 * this process and one for unattended writes, all in the process arena. They are not a Hilos::$rt
 * collection because they are the bookkeeping of RT replication itself; nothing reads them but
 * this door and the hand-off below.
 *
 * Mandatory cleanup - the removal of a row whose socket or owner is gone, and the terminal values
 * an ended run or a lifted freeze leaves a row with - takes the bounded service reserve of the same
 * FIFO instead ({@see self::removeAsCleanup()}, {@see self::updateAsCleanup()}). It never refuses for
 * want of room: a cleanup the reserve cannot take now is owed by the row's identity and retried as
 * the FIFO frees. The owed cleanups are no second queue: one per row, the last one owed winning,
 * and a row already gone or replaced under its key is owed nothing. Losing the process loses them,
 * as it loses the rows; they get no durable replay.
 *
 * A committed cell is handed on to the router queue that carries its operation to the stream
 * sender ({@see RtSyncStreamChannel}). Where a stream sender consumes this process's FIFOs
 * ({@see self::consumeBy()}), cells are handed on only while that sender has room, and the rest wait
 * in FIFO order until it frees ({@see self::handOnCommitted()}); writes past the FIFO's bound are
 * then refused at the door. The hand-on is not a transport acknowledgement.
 */
final class RtOrdinaryAdmission
{
    /** @var array<string, RtOrdinaryQueue> Source FIFO of each writing agent of this process */
    private static array $agentQueues = [];
    private static ?RtOrdinaryQueue $unattendedQueue = null;
    /** @var list<RtOrdinaryQueue> FIFOs a test bound, kept even while idle */
    private static array $boundQueues = [];
    /** @var ?WeakMap<RtOrdinaryQueue, array<string, array<string, RtOrdinaryCleanup>>> Removals each FIFO owes, by collection and row key */
    private static ?WeakMap $owedCleanups = null;
    /** Whether owed removals are being retried now, so a retried removal's own settle does not retry again */
    private static bool $retrying = false;
    /** Whether the next reservation is a terminal transition's, taken from the service reserve */
    private static bool $serviceLane = false;
    /** @var ?Closure(): bool Whether the stream sender consuming this process's FIFOs has room now; null hands on at once */
    private static ?Closure $consumer = null;
    /** Whether a FIFO kept committed cells for want of the consumer's room */
    private static bool $waitingForRoom = false;

    private bool $settled = false;

    /**
     * @param RtOrdinaryQueue $queue Source FIFO the cell belongs to
     * @param RtOrdinaryReservation $slot Reserved cell of the whole compound
     */
    private function __construct(private readonly RtOrdinaryQueue $queue, private readonly RtOrdinaryReservation $slot)
    {
    }

    /**
     * An admission abandoned before its live write returns its reservation.
     *
     * @throws InvalidArgumentException Never: the reservation retires on fresh allowances
     */
    public function __destruct()
    {
        if (!$this->settled) {
            $this->queue->cancel($this->slot);
        }
    }

    /**
     * Reserves the entire compound on the current source's FIFO, before anything is written.
     *
     * @param int $operations Entire compound count
     * @return self Unsealed admission
     * @throws RtWriteBackpressureException When the source FIFO has no room; nothing was written
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of the FIFO unexpectedly closed
     */
    public static function reserve(int $operations): self
    {
        $serviceLane = self::$serviceLane;
        self::$serviceLane = false;
        try {
            $queue = self::sourceQueue();

            return new self($queue, $serviceLane ? $queue->reserveCleanup($operations) : $queue->reserve($operations));
        } catch (LogicException $broken) {
            throw self::brokenInvariant($broken);
        }
    }

    /**
     * Seals the prepared operations; their actual retention is admitted here, still before the
     * live write. A refusal hands the reservation back.
     *
     * @param list<RtMutation|RtRowValues|array<string, mixed>> $operations Prepared operations in the order they are
     *        written; the native row or diff of an unbound row is segmented here
     * @throws RtWriteBackpressureException When their retention exceeds the source's version reserve
     * @throws InvalidFormatException When a native row holds a value a runtime row cannot carry
     * @throws FrameworkLogicException When the reservation no longer prepares or an operation lease closed
     * @throws InvalidArgumentException Never: a refused reservation retires on fresh allowances
     */
    public function seal(array $operations): void
    {
        try {
            $arena = $this->queue->arena;
            $index = new RtPersistentIndex($arena);
            $prefix = null;
            foreach ($operations as $position => $operation) {
                $root = match (true) {
                    $operation instanceof RtMutation => $operation->descriptor,
                    $operation instanceof RtRowValues => $operation,
                    default => RtRowValues::carried($operation),
                };
                $prefix = RtRowValues::completed($index->replace(
                    $prefix?->rootIn($arena),
                    RtPersistentIndex::ordinal($position + 1),
                    $root->rootIn($arena),
                ));
            }
            $this->queue->seal($this->slot, $prefix);
        } catch (RtWriteBackpressureException | InvalidFormatException $refusal) {
            $this->settled = true;
            $this->queue->cancel($this->slot);
            throw $refusal;
        } catch (LogicException $broken) {
            $this->settled = true;
            $this->queue->cancel($this->slot);
            throw self::brokenInvariant($broken);
        }
    }

    /**
     * The live write was made: the cell becomes mandatory at the commit of the application
     * transaction it was made under, and a rollback hands the reservation back. With no
     * application transaction open the write stands at once, and so does the cell. A test's
     * rollback root is not an application transaction: holding every cell of a test until its
     * end would count the whole test as one uncommitted write.
     *
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of the FIFO unexpectedly closed
     */
    public function settle(): void
    {
        $this->settled = true;
        $queue = $this->queue;
        $slot = $this->slot;
        if (self::standsAtOnce()) {
            $queue->commit($slot);
            try {
                $handedOn = self::handOn($queue);
            } catch (LogicException $broken) {
                throw self::brokenInvariant($broken);
            }
            if ($handedOn) {
                self::retryOwed($queue);
            }

            return;
        }
        Database::onRollback(static function () use ($queue, $slot): void {
            $queue->cancel($slot);
        });
        Database::afterCommit(static function () use ($queue, $slot): void {
            $queue->commit($slot);
            if (self::handOn($queue)) {
                self::retryOwed($queue);
            }
        });
    }

    /**
     * Removes a row as mandatory cleanup, from the bounded service reserve of the current source's
     * FIFO: the right was judged by the caller, and the removal is prepared and admitted before the
     * key leaves the store, as an ordinary removal is.
     *
     * Nothing here refuses for want of room. A removal the service reserve cannot take now is owed
     * by the row's identity and retried each time the FIFO hands committed cells on, against the
     * collection as it then stands: a row already removed, or replaced under its key, is not
     * removed again. A removal already owed for the same row is owed once.
     *
     * @param RtStates $states Collection the row stands in
     * @param string $collection Registered collection key
     * @param RtState $row Row to remove
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of the FIFO unexpectedly closed
     */
    public static function removeAsCleanup(RtStates $states, string $collection, RtState $row): void
    {
        $cleanup = new RtOrdinaryCleanup($states, $collection, $row);
        try {
            try {
                self::cleanUp($cleanup);
                self::forgetOwed(self::sourceQueue(), $collection, $row->getId());
            } catch (RtWriteBackpressureException) {
                $queue = self::sourceQueue();
                self::$owedCleanups ??= new WeakMap();
                $owed = self::$owedCleanups[$queue] ?? [];
                $owed[$collection][$row->getId()] = $cleanup;
                self::$owedCleanups[$queue] = $owed;
            }
        } catch (LogicException $broken) {
            throw self::brokenInvariant($broken);
        }
    }

    /**
     * Makes a terminal transition of a row - the values an ended run, a lifted freeze or a stopped
     * owner leaves it with - as mandatory cleanup: the row's own write door runs once more on the
     * bounded service reserve of the current source's FIFO instead of its ordinary room, so a full
     * FIFO does not leave a run "going" for ever. The right is the door's own to judge.
     *
     * Nothing here refuses for want of room. A transition the service reserve cannot take now is
     * owed by the row's identity and retried as the FIFO frees, through the same door; the last one
     * owed for a row wins, and a later ordinary write of the row supersedes it
     * ({@see self::supersedeOwed()}). A row no longer held under its key is owed nothing.
     *
     * @param string $collection Registered collection or item key of the row
     * @param RtState $row Row the transition writes
     * @param Closure(): void $transition The row's write door with the terminal values, reserving once
     * @throws RtTruthSourceWriteNotAllowedException When the door finds the caller is not the truth source
     * @throws HilosException Whatever the door's read of the values or the preparation of its operation raises
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of the FIFO unexpectedly closed
     */
    public static function updateAsCleanup(string $collection, RtState $row, Closure $transition): void
    {
        $cleanup = new RtOrdinaryCleanup(null, $collection, $row, $transition);
        try {
            try {
                self::cleanUp($cleanup);
                self::forgetOwed(self::sourceQueue(), $collection, $row->getId());
            } catch (RtWriteBackpressureException) {
                $queue = self::sourceQueue();
                self::$owedCleanups ??= new WeakMap();
                $owed = self::$owedCleanups[$queue] ?? [];
                $owed[$collection][$row->getId()] = $cleanup;
                self::$owedCleanups[$queue] = $owed;
            }
        } catch (LogicException $broken) {
            throw self::brokenInvariant($broken);
        }
    }

    /**
     * An ordinary write of a row stands: a terminal transition still owed for it is older than
     * that write and is forgotten - the last value wins. An owed removal is not touched.
     *
     * @param string $collection Registered collection or item key of the row
     * @param string $id Row key
     */
    public static function supersedeOwed(string $collection, string $id): void
    {
        $agentId = ExecutionContext::currentAgentId();
        $queue = $agentId === null ? self::$unattendedQueue : self::$agentQueues[$agentId] ?? null;
        if ($queue !== null && (self::$owedCleanups[$queue][$collection][$id] ?? null)?->transition !== null) {
            self::forgetOwed($queue, $collection, $id);
        }
    }

    /**
     * Whether the FIFO of an agent of this process still owes a cleanup: one its service reserve could
     * not take, retried as the FIFO frees ({@see self::retryOwed()}). A worker holds the report of
     * that agent's stop behind it ({@see WorkerManager::notifyAgentStopped()}): the master reads the
     * report as "the old instance is gone", and a debt made after it would land over the new one's row.
     *
     * @param string $agentId Agent whose FIFO is asked about
     * @return bool True while a removal or a terminal transition of it is owed
     */
    public static function owes(string $agentId): bool
    {
        $queue = self::$agentQueues[$agentId] ?? null;

        return $queue !== null && isset(self::$owedCleanups[$queue]);
    }

    /**
     * @internal Removals the current source still owes, for tests.
     *
     * @return int Owed removals, one per row
     */
    public static function owedRemovals(): int
    {
        $agentId = ExecutionContext::currentAgentId();
        $queue = $agentId === null ? self::$unattendedQueue : self::$agentQueues[$agentId] ?? null;
        if ($queue === null || !isset(self::$owedCleanups[$queue])) {
            return 0;
        }

        return array_sum(array_map(count(...), self::$owedCleanups[$queue]));
    }

    /**
     * Prepares the immutable operation of one row write, in one bounded synchronous step: the
     * values are the row's own current pages, never a materialized row.
     *
     * A row without a binding has nothing but its native row to retain; that row, segmented, is
     * the operation it holds.
     *
     * @param TableMutationType $type C/U/D operation
     * @param string $collection Registered collection key
     * @param ?RtState $before Live row before the write, absent only for create
     * @param ?RtState $after Row after the write - the live row or its validated draft - absent only for delete
     * @return RtMutation|array<string, mixed> Prepared operation, or the native row of an unbound one for {@see self::seal()}
     * @throws InvalidFormatException When a row's stored pages are malformed
     * @throws FrameworkLogicException When the operation is inconsistent or a backing lease closed
     * @throws RtRowCodecNotSupportedException Never for a bound row; named because its frozen read declares it
     */
    public static function operation(TableMutationType $type, string $collection, ?RtState $before, ?RtState $after): RtMutation|array
    {
        $row = $after ?? $before ?? throw new FrameworkLogicException('An RT write door names the row it writes');
        if (!$row->hasRowBinding()) {
            return $row->toArray();
        }
        try {
            return self::mutation($type, $collection, $before, $after, $row);
        } catch (LogicException $broken) {
            throw self::brokenInvariant($broken);
        }
    }

    /**
     * @internal A test binds a FIFO of its own bounds to the current source.
     *
     * @param RtOrdinaryQueue $queue FIFO made under the current execution source
     */
    public static function bindSourceQueue(RtOrdinaryQueue $queue): void
    {
        self::$boundQueues[] = $queue;
        $agentId = ExecutionContext::currentAgentId();
        if ($agentId === null) {
            self::$unattendedQueue = $queue;
        } else {
            self::$agentQueues[$agentId] = $queue;
        }
    }

    /**
     * @internal The stream sender of this process consumes its FIFOs: a committed cell is handed on
     * only while the sender has room, and waits otherwise for {@see self::handOnCommitted()}.
     *
     * @param ?Closure(): bool $hasRoom Whether the sender takes more operations now; null hands on at once
     */
    public static function consumeBy(?Closure $hasRoom): void
    {
        self::$consumer = $hasRoom;
    }

    /**
     * @internal The consuming stream sender freed room: every FIFO of this process hands its
     * committed heads on, oldest first, while the room lasts, and retries the removals it owes.
     *
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of a FIFO unexpectedly closed
     */
    public static function handOnCommitted(): void
    {
        if (!self::$waitingForRoom) {
            return;
        }
        self::$waitingForRoom = false;
        $queues = self::$agentQueues;
        if (self::$unattendedQueue !== null) {
            $queues[] = self::$unattendedQueue;
        }
        foreach ($queues as $queue) {
            try {
                $handedOn = self::handOn($queue);
            } catch (LogicException $broken) {
                throw self::brokenInvariant($broken);
            }
            if ($handedOn) {
                self::retryOwed($queue);
            }
        }
    }

    /** @internal Forgets every source FIFO of this process; for tests between cases. */
    public static function reset(): void
    {
        self::$agentQueues = [];
        self::$unattendedQueue = null;
        self::$boundQueues = [];
        self::$owedCleanups = null;
        self::$retrying = false;
        self::$consumer = null;
        self::$waitingForRoom = false;
    }

    /**
     * Current source's FIFO, after handing on and retiring what it already holds.
     *
     * @return RtOrdinaryQueue FIFO of the executing agent, or of unattended writes
     * @throws InvalidArgumentException When a retirement allowance is invalid
     * @throws LogicException When a retained cell unexpectedly closed
     */
    private static function sourceQueue(): RtOrdinaryQueue
    {
        $agentId = ExecutionContext::currentAgentId();
        $held = $agentId === null ? self::$unattendedQueue : self::$agentQueues[$agentId] ?? null;
        if ($held !== null) {
            self::handOn($held);
        }

        return $agentId === null
            ? self::$unattendedQueue ??= new RtOrdinaryQueue(RtRowArena::process())
            : self::$agentQueues[$agentId] ??= new RtOrdinaryQueue(RtRowArena::process());
    }

    /**
     * Hands every committed head on to the router queue already carrying its frame, while the
     * consuming stream sender has room, then retires what was released. A FIFO left with nothing at
     * all is forgotten, so a process does not keep one for every agent that ever wrote.
     *
     * A synchronous door of the process arena ({@see RtRowArena::openDoor()}): it retires what the
     * hand-off itself released, each step on one bounded allowance, and leaves the arena's backlog
     * to the RT pass. An uncommitted head stops the hand-off and keeps every later cell in FIFO order.
     *
     * @param RtOrdinaryQueue $queue Source FIFO
     * @return bool Whether the sender had room, so the committed heads were handed on
     * @throws InvalidArgumentException When a retirement allowance is invalid
     * @throws LogicException When a retained cell unexpectedly closed
     */
    private static function handOn(RtOrdinaryQueue $queue): bool
    {
        $arena = RtRowArena::process();
        $room = self::$consumer === null || (self::$consumer)();
        $arena->openDoor();
        try {
            do {
                $arena->advanceRetirement(new RtWorkBudget());
                $held = [$queue->pendingOperations(), $queue->retainedBytes(), $queue->isIdle()];
                $queue->advance(new RtWorkBudget());
                while ($room && ($head = $queue->head()) !== null) {
                    $head->cancel();
                    $queue->releaseHead();
                    $queue->advance(new RtWorkBudget());
                }
            } while ($arena->retention()->pendingReleases > 0
                || $held !== [$queue->pendingOperations(), $queue->retainedBytes(), $queue->isIdle()]);
        } finally {
            unset($head);
            $arena->closeDoor();
        }
        if (!$room && !$queue->isIdle()) {
            self::$waitingForRoom = true;
        }
        if (!$queue->isIdle() || in_array($queue, self::$boundQueues, true) || isset(self::$owedCleanups[$queue])) {
            return $room;
        }
        if (self::$unattendedQueue === $queue) {
            self::$unattendedQueue = null;
        }
        foreach (self::$agentQueues as $agentId => $held) {
            if ($held === $queue) {
                unset(self::$agentQueues[$agentId]);
            }
        }

        return $room;
    }

    /**
     * One attempt at an owed or fresh cleanup removal, wholly or not at all.
     *
     * @param RtOrdinaryCleanup $cleanup Removal to make
     * @throws RtWriteBackpressureException When the service reserve has no room for it now; nothing was written
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws HilosException Whatever preparing the row's immutable operation raises
     * @throws InvalidArgumentException When a retirement allowance of the hand-off is invalid
     * @throws FrameworkLogicException When a retained cell of the FIFO unexpectedly closed
     * @throws LogicException When a retained cell of the FIFO unexpectedly closed while it was taken
     */
    private static function cleanUp(RtOrdinaryCleanup $cleanup): void
    {
        if ($cleanup->transition !== null) {
            self::$serviceLane = true;
            try {
                ($cleanup->transition)();
            } finally {
                self::$serviceLane = false;
            }

            return;
        }
        $states = $cleanup->standingIn();
        $row = $cleanup->row();
        if ($states === null || $row === null) {
            return;
        }
        $queue = self::sourceQueue();
        $admission = new self($queue, $queue->reserveCleanup(1));
        $admission->seal([self::operation(TableMutationType::Delete, $cleanup->collection, $row, null)]);
        try {
            $states->remove($row->getId());
        } finally {
            $admission->settle();
        }
    }

    /**
     * Retries the removals a FIFO owes, oldest first, now that it has handed committed cells on.
     * One the service reserve - or, for a removal larger than that reserve, the large slot - still
     * cannot take stays owed, and the retry goes on with the next: the removals are of different
     * rows, and one that does not fit now holds no other back. A removal whose row is gone,
     * replaced, or no longer the source's to remove is owed nothing any more. A retried removal that fails otherwise is logged and dropped: the write
     * whose settle carried the retry has already stood, and must not answer for another row.
     *
     * @param RtOrdinaryQueue $queue FIFO that just handed cells on
     */
    private static function retryOwed(RtOrdinaryQueue $queue): void
    {
        if (self::$retrying || !isset(self::$owedCleanups[$queue])) {
            return;
        }
        self::$retrying = true;
        try {
            foreach (self::$owedCleanups[$queue] as $collection => $rows) {
                foreach ($rows as $id => $cleanup) {
                    try {
                        $row = $cleanup->row();
                        if ($cleanup->transition !== null) {
                            if ($cleanup->standsInRuntime()) {
                                self::cleanUp($cleanup);
                            }
                        } elseif ($cleanup->standingIn() !== null && $row !== null) {
                            RtTruthSourceRegistry::checkCanWriteState(
                                $collection, (string)$id, $row->touchedSetKeys(), TruthSourceOperation::Remove,
                            );
                            self::cleanUp($cleanup);
                        }
                    } catch (RtWriteBackpressureException) {
                        continue;
                    } catch (RtTruthSourceWriteNotAllowedException) {
                        // The source may no longer remove this row; nothing is owed by it any more.
                    } catch (HilosException | LogicException $failed) {
                        Logger::warning(sprintf(
                            'An owed RT cleanup removal of %s/%s failed and is dropped: %s',
                            $collection,
                            $id,
                            $failed->getMessage(),
                        ));
                    }
                    self::forgetOwed($queue, $collection, (string)$id);
                }
            }
        } finally {
            self::$retrying = false;
        }
    }

    /**
     * @param RtOrdinaryQueue $queue FIFO that owed the removal
     * @param string $collection Registered collection key
     * @param string $id Row key
     */
    private static function forgetOwed(RtOrdinaryQueue $queue, string $collection, string $id): void
    {
        $owed = self::$owedCleanups[$queue] ?? [];
        unset($owed[$collection][$id]);
        if (($owed[$collection] ?? []) === []) {
            unset($owed[$collection]);
        }
        if ($owed === []) {
            unset(self::$owedCleanups[$queue]);
        } else {
            self::$owedCleanups[$queue] = $owed;
        }
    }

    /**
     * @param TableMutationType $type C/U/D operation
     * @param string $collection Registered collection key
     * @param ?RtState $before Live row before the write
     * @param ?RtState $after Row after the write
     * @param RtState $row The one of the two that names the row
     * @return RtMutation Prepared operation
     * @throws InvalidFormatException When a row's stored pages are malformed
     * @throws LogicException When the operation is inconsistent or a backing lease closed
     * @throws RtRowCodecNotSupportedException Never for a bound row
     */
    private static function mutation(TableMutationType $type, string $collection, ?RtState $before, ?RtState $after, RtState $row): RtMutation
    {
        $arena = RtRowArena::process();
        $identity = RtRowValues::completed(RtPreparedIdentity::prepare(
            RtRowValues::completed(RtRowWriter::encode($row->getId(), $arena)),
        ));
        $context = RtRowValues::carried([
            SourceChange::FIELD_SOURCE_KEY => $collection,
            SourceChange::FIELD_SOURCE_ID => $row->getId(),
            SourceChange::FIELD_ORIGIN => ExecutionContext::currentAcceptKey(),
            SourceChange::FIELD_ORIGIN_REQUEST_ID => ExecutionContext::currentRequestId(),
        ]);

        return RtRowValues::completed(RtMutation::prepare(
            $type,
            $before === null ? null : self::preparedRow($identity, $before),
            $after === null ? null : self::preparedRow($identity, $after),
            $context,
        ));
    }

    /**
     * @return bool Whether no application transaction holds the write: none is open, or only a test's rollback root
     */
    private static function standsAtOnce(): bool
    {
        try {
            $level = Database::runtimeTransactionLevel();
        } catch (TestRollbackRefusedException) {
            return false;
        }

        return $level === null || $level->testScope !== null;
    }

    /**
     * A closed lease or an inconsistent page met while a door prepares is a broken Runtime
     * invariant; it leaves the door as the framework's own logic failure, which every write
     * caller already answers for, and keeps its cause.
     *
     * @param LogicException $broken Native invariant failure
     * @return FrameworkLogicException Framework failure carrying it
     */
    private static function brokenInvariant(LogicException $broken): FrameworkLogicException
    {
        return new FrameworkLogicException($broken->getMessage(), previous: $broken);
    }

    /**
     * @param RtPreparedIdentity $identity Prepared row identity
     * @param RtState $row Bound row; its current values are its baseline in this operation
     * @return RtPreparedRow Record of the row as it stands
     * @throws InvalidFormatException When a stored page is malformed
     * @throws LogicException When a backing lease closed
     * @throws RtRowCodecNotSupportedException Never for a bound row
     */
    private static function preparedRow(RtPreparedIdentity $identity, RtState $row): RtPreparedRow
    {
        $values = $row->carriedRow();

        return RtRowValues::completed(RtPreparedRow::prepare(
            $identity,
            $values instanceof RtRowValues ? $values : RtRowValues::carried($values),
            $values instanceof RtRowValues ? $values : RtRowValues::carried($values),
            $row->freezeRow()->incarnation,
        ));
    }
}
