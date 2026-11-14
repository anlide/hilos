<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Closure;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Database;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\State\RtRowCodecNotSupportedException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowBinding;
use Hilos\Runtime\Row\RtRowDescriptor;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Row\RtRowRoot;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSnapshotBasis;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RtState;
use LogicException;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * One snapshot generation staged into a collection or standalone item of this process, hidden
 * until it is published whole.
 *
 * The rows come in portions of one cut ({@see self::append()}), then the source's later changes
 * in their order ({@see self::appendDelta()}). Each pass ({@see self::advance()}) restores and
 * validates rows within its allowance, then builds a candidate beside the live source: the rows
 * as they will stand, a detached selector branched from the committed one, and for every row
 * that keeps its identity the values its handle will read. Until publication every reader sees
 * the last whole version. Committed changes of the live source meanwhile are handed to the
 * generation; those within its scope are superseded by it, the others are carried into the
 * candidate. They are its history, and a history outgrowing its allowance cancels this generation
 * alone - writes and ordinary delivery go on, and a retry takes a new cut.
 *
 * The stale marks of the source are prepared beside the candidate as well, last: the live marks
 * less those of the rows the generation sweeps, prepared again when either changes.
 *
 * Publication ({@see self::publish()}) checks the generation is still current and switches the
 * roots in one step without visiting a row - the stale marks of the collection by one assignment
 * among them; the announcements, read straight off the generation's own maps of swept and arrived
 * rows, and the settling of the kept rows' handles follow in portions, applied-remote and with the
 * context the staging began in.
 *
 * Generations, candidates and their history are process-local copy machinery of one source, not
 * a replicated collection of Hilos::$rt (docs/agents/framework-first.md,
 * docs/agents/runtime/rt-context.md).
 * SCAFFOLD: no production caller until the initial-sync frames move onto staging (HIL-1817);
 * {@see RtSnapshot::replace()} and its twins stay the doors until then.
 */
final class RtSnapshotImport
{
    /** Logical bytes of appended portions this generation holds unprepared at most */
    public const int DEFAULT_RESERVE_BYTES = 262144;

    /** Logical bytes of later changes and live history this generation retains at most */
    public const int DEFAULT_HISTORY_BYTES = 4194304;

    private RtSnapshotStatus $status = RtSnapshotStatus::Building;

    private readonly RtSnapshotBasis $basis;

    /** @var ?WeakReference<RtStates<RtState>> Collection staged into, null for a standalone item */
    private ?WeakReference $collection = null;

    /** @var ?WeakReference<RtState> Standalone item staged into, null for a collection */
    private ?WeakReference $item = null;

    private ?int $epoch = null;

    private ?RtRowCodec $codec = null;

    private readonly ?string $acceptKey;

    private readonly ?string $requestId;

    private bool $inputFinished = false;

    /** @var list<array{string, RtRowValues, int}> Appended rows not prepared yet, with the reserve each frees */
    private array $input = [];

    private int $reserved = 0;

    /** @var list<RtMutation> Later changes not applied yet */
    private array $deltas = [];

    /** @var list<array{string, ?RtState, ?RtState, ?RtRowValues, int}> Committed live changes not carried yet */
    private array $history = [];

    private int $historyBytes = 0;

    /** @var array<string, array{?RtState, ?RtRowValues}> Restored row and its values per id, nulls for a deletion */
    private array $staged = [];

    private ?RtWorkDriver $step = null;

    /** @var ?array<string, RtState> Rows of the source at the staging point, null until the candidate is begun */
    private ?array $live = null;

    private bool $merged = false;

    /** @var array<string, RtState> Rows of the candidate, in their order */
    private array $candidate = [];

    private ?RtVersionStore $versions = null;

    private ?RtPreparedBatch $batch = null;

    private bool $versionChanged = false;

    private ?RtRowRoot $root = null;

    /** @var ?WeakMap<RtRowBinding, array{RtRowDescriptor, RtRowDescriptor}> Values and baselines of kept rows */
    private ?WeakMap $generation = null;

    /** Draft of a standalone item, null for a collection or nothing to publish */
    private ?RtState $draft = null;

    /** @var array<string, RtRowValues> Rows that arrived, by id; after publication, those not announced yet */
    private array $arrived = [];

    /** @var array<string, RtRowValues> Rows swept, with the values they had, by id; after publication, those not announced yet */
    private array $swept = [];

    /** @var array<string, float> Stale marks of the source as they stand once published, by id */
    private array $stale = [];

    /** Revision of the source's marks {@see self::$stale} was prepared at, null when it is to be prepared */
    private ?int $staleRevision = null;

    private ?Closure $whenPublished = null;

    /**
     * @param string $collectionKey Collection or standalone item to stage into
     * @param RtSnapshotScope $scope Rows the generation replaces
     * @param int $cutRevision Source revision the portions were cut at; later changes follow it in order
     * @param int $reserveBytes Unprepared portion bytes this generation accepts at most
     * @param int $historyBytes Later-change and live-history bytes it retains before it cancels itself
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly RtSnapshotScope $scope,
        public readonly int $cutRevision,
        private readonly int $reserveBytes = self::DEFAULT_RESERVE_BYTES,
        private readonly int $historyLimit = self::DEFAULT_HISTORY_BYTES,
    ) {
        $this->basis = new RtSnapshotBasis();
        $this->acceptKey = ExecutionContext::currentAcceptKey();
        $this->requestId = ExecutionContext::currentRequestId();
        $collection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($collection !== null) {
            $this->collection = WeakReference::create($collection);
            $this->epoch = $collection->snapshotEpoch();

            return;
        }
        $item = Hilos::$rt?->getStateItem($collectionKey);
        if ($item === null) {
            $this->status = RtSnapshotStatus::Canceled;

            return;
        }
        $this->item = WeakReference::create($item);
    }

    /** An abandoned generation releases what it holds; one already published leaves its handles to settle on their own. */
    public function __destruct()
    {
        $this->cancel();
    }

    /**
     * Takes one portion of the cut in, within the generation's reserve. A closed generation ignores it.
     *
     * @param RtSnapshotPortion $portion Rows of the cut, in its order
     * @throws FrameworkLogicException When the input was finished or the reserve is still full
     */
    public function append(RtSnapshotPortion $portion): void
    {
        if ($this->isClosed()) {
            return;
        }
        if ($this->inputFinished) {
            throw new FrameworkLogicException('An RT snapshot generation takes no portion after its input finished');
        }
        if ($this->reserved > 0 && $this->reserved + $portion->bytes > $this->reserveBytes) {
            throw new FrameworkLogicException('An RT snapshot portion arrived beyond the reserve of its generation');
        }
        $this->reserved += $portion->bytes;
        $last = count($portion->ids) - 1;
        foreach ($portion->ids as $position => $id) {
            $this->input[] = [$id, $portion->values[$position]->retain(RtRowRole::Candidate), $position === $last ? $portion->bytes : 0];
        }
    }

    /** The cut is complete: nothing more of it will be appended. */
    public function finishInput(): void
    {
        $this->inputFinished = true;
    }

    /**
     * Takes one later change of the source in, after the ones before it. A ready generation is
     * building again until the change is applied; a closed one ignores it, and one that would
     * retain more history than it may is canceled.
     *
     * @param RtMutation $delta Committed change of the source after the cut
     * @throws LogicException When the change's lease has closed
     */
    public function appendDelta(RtMutation $delta): void
    {
        if ($this->isClosed() || $this->status === RtSnapshotStatus::Published) {
            return;
        }
        if ($this->retainHistory(RtRowPage::OVERHEAD_BYTES + $delta->values()->rootIn($delta->arena)->logicalBytes)) {
            $this->deltas[] = $delta;
            $this->status = RtSnapshotStatus::Building;
        }
    }

    /**
     * @internal A committed change of the live source while this generation stages into it, handed
     * over by the source itself; carried into the candidate unless the generation supersedes it.
     *
     * @param string $id Row id
     * @param ?RtState $previous Row before the change
     * @param ?RtState $next Row after it, null for a removal
     * @param ?RtRowValues $values Committed values after it
     * @throws LogicException When the values' lease has closed
     */
    public function noteLive(string $id, ?RtState $previous, ?RtState $next, ?RtRowValues $values): void
    {
        if ($this->isClosed() || $this->status === RtSnapshotStatus::Published) {
            return;
        }
        $bytes = RtRowPage::OVERHEAD_BYTES + ($values?->rootIn(RtRowArena::process())->logicalBytes ?? 0);
        if ($this->retainHistory($bytes)) {
            $this->history[] = [$id, $previous, $next, $values?->retain(RtRowRole::Ordinary), $bytes];
            $this->status = RtSnapshotStatus::Building;
        }
    }

    /**
     * @internal The caller's own bookkeeping of this generation, run inside publication once the
     * roots are switched and before anybody hears of it - the origin of the rows that arrived and
     * of those swept, for one. It is handed the generation's own maps of them, prepared during the
     * passes, not a copy or a list built at publication: publication visits no row, and a caller
     * that walks or keeps them does so on its own account. Not run for a generation that is never
     * published.
     *
     * @param Closure(array<string, RtRowValues>, array<string, RtRowValues>): void $settle Takes the rows that
     *     arrived and those swept, each by id with its values; the maps are announced from afterwards, so
     *     it lets go of them before it returns
     */
    public function whenPublished(Closure $settle): void
    {
        $this->whenPublished = $settle;
    }

    /**
     * Prepares within the allowance: rows, later changes, the candidate and the history first,
     * the announcements and the settling of kept rows after publication.
     *
     * @param RtWorkBudget $budget Allowance of this pass
     * @return RtSnapshotStatus Where the generation stands after this pass
     * @throws LogicException When a page lease or the selector broke under the generation
     * @throws InvalidArgumentException When the allowance refuses a request
     * @throws HilosException Whatever a subscriber raises on a published announcement
     */
    public function advance(RtWorkBudget $budget): RtSnapshotStatus
    {
        if ($this->isClosed()) {
            return $this->status;
        }
        if ($this->status !== RtSnapshotStatus::Published && !$this->isCurrent()) {
            $this->close(RtSnapshotStatus::Canceled);

            return $this->status;
        }
        try {
            while (true) {
                if ($this->step === null) {
                    $unit = $this->nextUnit();
                    if ($unit === null) {
                        break;
                    }
                    $this->step = new RtWorkDriver($unit);
                }
                $this->step->advance($budget);
                if (!$this->step->isFinished()) {
                    return $this->status;
                }
                $this->step = null;
            }
        } catch (InvalidFormatException | RtRowCodecNotSupportedException $refused) {
            $this->fail(RtSnapshotStatus::Refused);

            return $this->status;
        } catch (HilosException | LogicException $broken) {
            $this->fail(RtSnapshotStatus::Canceled);
            throw $broken;
        } catch (Throwable $unexpected) {
            $this->fail(RtSnapshotStatus::Canceled);
            throw new LogicException('The RT snapshot generation failed: ' . $unexpected->getMessage(), 0, $unexpected);
        }
        if ($this->status === RtSnapshotStatus::Building && $this->isComplete()) {
            $this->status = RtSnapshotStatus::Ready;
        }

        return $this->status;
    }

    /**
     * Switches the source to this generation in one step, visiting no row: rows, selector, the
     * values of kept rows, the view cache and the stale marks prepared beside them, then the
     * caller's own bookkeeping. A
     * generation no longer current - its runtime, mount or whole-source incarnation changed - is
     * canceled instead, and one that took a change since it was ready is building again; the next
     * {@see self::advance()} tells which.
     *
     * @throws FrameworkLogicException When the generation is not ready, or a transaction is open
     * @throws LogicException When a lease or the previous generation's settling broke
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    public function publish(): void
    {
        if ($this->status !== RtSnapshotStatus::Ready) {
            throw new FrameworkLogicException('Only a ready RT snapshot generation can be published');
        }
        if (Database::runtimeTransactionLevel() !== null) {
            throw new FrameworkLogicException('An RT snapshot generation is published outside any transaction');
        }
        if (!$this->isCurrent()) {
            $this->close(RtSnapshotStatus::Canceled);

            return;
        }
        if (!$this->isComplete()) {
            $this->status = RtSnapshotStatus::Building;

            return;
        }
        $collection = $this->collection?->get();
        if ($collection !== null) {
            if ($this->versionChanged) {
                $this->versions->apply($this->batch);
            }
            $this->batch?->cancel();
            $collection->closeStaging($this);
            $collection->installStaged($this->candidate, $this->versions, $this->generation, $this->scope->isWhole());
            Hilos::$rt?->getRtCollection($this->collectionKey)?->clearCache();
            RtStaleness::install($this->collectionKey, $this->stale);
        } else {
            $this->publishItem();
        }
        $this->status = RtSnapshotStatus::Published;
        reset($this->swept);
        reset($this->arrived);
        if ($this->whenPublished !== null) {
            ($this->whenPublished)($this->arrived, $this->swept);
        }
        $this->releaseCandidate();
    }

    /** Ends an unpublished generation at once; what it held retires with its last reference. */
    public function cancel(): void
    {
        if (!$this->isClosed() && $this->status !== RtSnapshotStatus::Published) {
            $this->close(RtSnapshotStatus::Canceled);
        }
    }

    /**
     * @return ?Generator<int, RtWork, int, void> Next bounded unit of work, null when this pass has none
     * @throws LogicException When the selector's lease has closed
     * @throws RtRowCodecNotSupportedException When the source's rows have not migrated
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    private function nextUnit(): ?Generator
    {
        $collection = $this->collection?->get();
        if ($this->status === RtSnapshotStatus::Published) {
            $announced = $this->announceNext($this->swept, false) ?? $this->announceNext($this->arrived, true);
            if ($announced !== null) {
                return $announced;
            }
            if ($collection !== null && !$collection->isSettled()) {
                return self::settleOne($collection);
            }

            return null;
        }
        if ($this->input !== []) {
            return $this->prepareInput(...array_shift($this->input));
        }
        if (!$this->inputFinished) {
            return null;
        }
        if ($this->item !== null) {
            if ($this->deltas !== []) {
                return $this->prepareDelta(array_shift($this->deltas));
            }

            return $this->draft === null && !$this->merged ? $this->prepareItem() : null;
        }
        if (!$this->merged) {
            if ($this->deltas !== []) {
                return $this->prepareDelta(array_shift($this->deltas));
            }

            return $this->merge($collection);
        }
        if ($this->deltas !== []) {
            return $this->prepareDelta(array_shift($this->deltas));
        }
        if ($this->history !== []) {
            return $this->carry(...array_shift($this->history));
        }
        if (!$collection->isSettled()) {
            return self::settleOne($collection);
        }
        if ($this->staleRevision !== RtStaleness::revision($this->collectionKey)) {
            return $this->prepareStale();
        }

        return null;
    }

    /**
     * Takes the next row off one of the generation's maps, in the map's own order, and announces
     * it; the map lets go of it as it does.
     *
     * @param array<string, RtRowValues> $rows Swept or arrived rows not announced yet, positioned at the next
     * @param bool $created Whether they arrived rather than were swept
     * @return ?Generator<int, RtWork, int, void> Its announcement, null when the map is done
     */
    private function announceNext(array &$rows, bool $created): ?Generator
    {
        $id = key($rows);
        if ($id === null) {
            return null;
        }
        $values = current($rows);
        next($rows);
        unset($rows[$id]);

        return $this->announce($created, (string)$id, $values);
    }

    /**
     * Prepares the source's stale marks as they will stand once published: the live marks of the
     * collection, less those of the rows the generation sweeps - a row that no longer exists cannot
     * be out of date. Marks or sweeps changing after it make it due again.
     *
     * @return Generator<int, RtWork, int, void> One live mark per step, one step at least
     */
    private function prepareStale(): Generator
    {
        yield new RtWork();
        $this->staleRevision = RtStaleness::revision($this->collectionKey);
        $this->stale = [];
        foreach (RtStaleness::staleRows($this->collectionKey) as $id => $since) {
            yield new RtWork();
            if (!isset($this->swept[$id])) {
                $this->stale[$id] = $since;
            }
        }
    }

    /**
     * @param string $id Row id of the cut
     * @param RtRowValues $values Its values
     * @param int $reserve Reserve the row frees once prepared
     * @return Generator<int, RtWork, int, void> Restore and validation of the row
     * @throws InvalidFormatException When the row's validator refuses it
     * @throws RtRowCodecNotSupportedException When the source's rows have not migrated
     */
    private function prepareInput(string $id, RtRowValues $values, int $reserve): Generator
    {
        $restored = yield from $this->restore($values);
        $this->reserved -= $reserve;
        if ($this->scope->admits($id, self::setKeyOf($restored))) {
            $this->stage($id, $restored, $values);
        }
    }

    /**
     * @param RtMutation $delta Later change of the source
     * @return Generator<int, RtWork, int, void> Its row restored, then staged or carried into the candidate
     * @throws InvalidFormatException When the row's validator refuses it or its identity is malformed
     * @throws RtRowCodecNotSupportedException When the source's rows have not migrated
     * @throws LogicException When a lease has closed
     */
    private function prepareDelta(RtMutation $delta): Generator
    {
        $this->historyBytes -= RtRowPage::OVERHEAD_BYTES + $delta->values()->rootIn($delta->arena)->logicalBytes;
        $record = $delta->after() ?? $delta->before();
        $id = yield from self::text($record->identity()->id());
        $restored = null;
        $values = null;
        if ($delta->type() !== TableMutationType::Delete) {
            $values = $record->values()->retain(RtRowRole::Candidate);
            $restored = yield from $this->restore($values);
            $setKey = self::setKeyOf($restored);
        } else {
            yield new RtWork();
            $setKey = $this->setKeyIn($record->values());
        }
        if (!$this->scope->admits($id, $setKey)) {
            return;
        }
        if ($this->item !== null) {
            $this->draft = null;
            $this->merged = false;
        }
        if (!$this->merged) {
            $this->stage($id, $restored, $values);

            return;
        }
        yield from $this->place($id, $restored, $values);
    }

    /**
     * @param string $id Row id
     * @param ?RtState $restored Restored row, null for a deletion
     * @param ?RtRowValues $values Its values
     */
    private function stage(string $id, ?RtState $restored, ?RtRowValues $values): void
    {
        if (($this->staged[$id][0] ?? null) === null) {
            // A row created again after its removal goes to the end, as it does at the source.
            unset($this->staged[$id]);
        }
        $this->staged[$id] = [$restored, $values];
    }

    /**
     * Begins the candidate at one committed point and walks the source's rows of that point once:
     * rows outside the scope stay, rows inside it are kept, replaced or swept, then the rows that
     * are new follow.
     *
     * @param RtStates<RtState> $collection Source staged into
     * @return Generator<int, RtWork, int, void> Candidate preparation, one row per step at least
     * @throws FrameworkLogicException When a transaction is open
     * @throws LogicException When the selector's lease has closed
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    private function merge(RtStates $collection): Generator
    {
        if (Database::runtimeTransactionLevel() !== null) {
            throw new FrameworkLogicException('An RT snapshot generation is prepared outside any transaction');
        }
        [$this->live, $this->versions, $this->root] = $collection->openStaging($this);
        $this->batch = $this->versions->begin();
        $this->generation = new WeakMap();
        foreach ($this->live as $id => $row) {
            yield new RtWork();
            $id = (string)$id;
            $this->candidate[$id] = $row;
            if (!$this->scope->admits($id, self::setKeyOf($row))) {
                continue;
            }
            if (array_key_exists($id, $this->staged)) {
                [$restored, $values] = $this->staged[$id];
                unset($this->staged[$id]);
                yield from $this->place($id, $restored, $values);
            } else {
                yield from $this->place($id, null, null);
            }
        }
        foreach ($this->staged as $id => [$restored, $values]) {
            yield new RtWork();
            yield from $this->place((string)$id, $restored, $values);
        }
        $this->staged = [];
        $this->merged = true;
    }

    /**
     * Puts one row of the generation into the candidate: a row standing there since the staging
     * point keeps its identity and reads the new values through its handle once published;
     * anything else stands as the restored row itself; a deletion sweeps the id.
     *
     * @param string $id Row id within the scope
     * @param ?RtState $restored Restored and validated row, null to sweep the id
     * @param ?RtRowValues $values Its values
     * @return Generator<int, RtWork, int, void> Candidate and selector change
     * @throws InvalidFormatException When a stored identity is malformed
     * @throws LogicException When a lease has closed or the selector refuses the change
     */
    private function place(string $id, ?RtState $restored, ?RtRowValues $values): Generator
    {
        $collection = $this->collection?->get();
        $current = $this->candidate[$id] ?? null;
        unset($this->arrived[$id]);
        $this->staleRevision = null;
        if ($restored === null) {
            if ($current !== null) {
                unset($this->candidate[$id]);
                $previous = yield from $this->changeVersion($id, null, null, false);
                if ($previous !== null && $current === ($this->live[$id] ?? null)) {
                    $this->swept[$id] = $previous;
                }
            }

            return;
        }
        $kept = $current !== null && $current === ($this->live[$id] ?? null) && $current::class === $restored::class;
        if ($kept) {
            $current->stageSnapshotValues($restored, $this->root, $this->generation);
        } else {
            // A row standing as itself reads its resident values through the root too, until settled.
            $restored->stageSnapshotValues($restored, $this->root, $this->generation);
            $collection?->holdStaged($restored);
            $this->candidate[$id] = $restored;
        }
        unset($this->swept[$id]);
        $this->arrived[$id] = $values;
        yield from $this->changeVersion($id, self::setKeyOf($restored), $values, $kept);
    }

    /**
     * Carries one committed live change into the candidate, unless the generation supersedes it.
     *
     * @param string $id Row id
     * @param ?RtState $previous Row before the change
     * @param ?RtState $next Row after it, null for a removal
     * @param ?RtRowValues $values Committed values after it
     * @param int $bytes History it retained
     * @return Generator<int, RtWork, int, void> Candidate and selector change
     * @throws InvalidFormatException When a stored identity is malformed
     * @throws LogicException When a lease has closed or the selector refuses the change
     */
    private function carry(string $id, ?RtState $previous, ?RtState $next, ?RtRowValues $values, int $bytes): Generator
    {
        yield new RtWork();
        $this->historyBytes -= $bytes;
        if ($this->scope->admits($id, self::setKeyOf($next ?? $previous))) {
            return;
        }
        if ($next === null) {
            unset($this->candidate[$id]);
        } else {
            $this->candidate[$id] = $next;
        }
        yield from $this->changeVersion($id, $next === null ? null : self::setKeyOf($next), $values, $previous !== null && $previous === $next);
    }

    /**
     * Writes one row change into the candidate selector, as the source writes its own.
     *
     * @param string $id Row id
     * @param ?string $setKey Set the row stands in after the change
     * @param ?RtRowValues $values Values after the change, null for a removal
     * @param bool $kept Whether the same row object stays under the id
     * @return Generator<int, RtWork, int, ?RtRowValues> Values the id held in the candidate before, null for none
     * @throws InvalidFormatException When a stored identity is malformed
     * @throws LogicException When a lease has closed or the selector refuses the change
     */
    private function changeVersion(string $id, ?string $setKey, ?RtRowValues $values, bool $kept): Generator
    {
        $arena = $this->versions->arena;
        $set = $values === null || $setKey === null ? null : yield from RtRowWriter::encode($setKey, $arena);
        $identity = yield from RtPreparedIdentity::prepare(yield from RtRowWriter::encode($id, $arena), $set);
        $existing = yield from $this->batch->find($identity);
        $before = $existing?->values()->retain(RtRowRole::Ordinary);
        if ($existing !== null && !$kept) {
            yield from $this->batch->change(TableMutationType::Delete, $existing);
            $this->versionChanged = true;
            $existing = null;
        }
        if ($values !== null) {
            $incarnation = $existing?->incarnation() ?? $this->collection?->get()?->nextIncarnation() ?? 0;
            $record = yield from RtPreparedRow::prepare($identity, $values, $values, $incarnation);
            yield from $this->batch->change($existing === null ? TableMutationType::Create : TableMutationType::Update, $record);
            $this->versionChanged = true;
        }

        return $before;
    }

    /**
     * @return Generator<int, RtWork, int, void> The standalone item's draft, patched with the one
     *     row the generation brought for it and validated whole; nothing to draft for no row
     * @throws InvalidFormatException When the row's validator refuses it
     * @throws RtRowCodecNotSupportedException When the item's row has not migrated
     */
    private function prepareItem(): Generator
    {
        yield new RtWork();
        $this->merged = true;
        $item = $this->item?->get();
        if ($item === null) {
            return;
        }
        [$restored, $values] = $this->staged[$item->getId()] ?? [null, null];
        if ($restored === null) {
            return;
        }
        $codec = $item->rowCodec();
        $draft = $item->draftRow();
        yield from $codec->patch($draft, $values->reader());
        $out = new RtRowWriter(RtRowArena::process(), RtRowRole::Transient);
        yield from $codec->export($draft, $out);
        $full = yield from $out->finish();
        yield from $codec->restore($full->reader());
        $this->draft = $draft;
        $this->arrived[$item->getId()] = $values;
    }

    /**
     * Publishes the standalone item's draft in one descriptor swap; no row, no change.
     *
     * @throws FrameworkLogicException When the draft is not one of the item's own
     * @throws LogicException When a lease has closed
     */
    private function publishItem(): void
    {
        $item = $this->item?->get();
        if ($item === null || $this->draft === null) {
            return;
        }
        if ($this->collectionKey === ProtectedModeRuntime::RT_ITEM) {
            Hilos::$ac?->invalidateProtectedMode();
            Hilos::$rt?->noteProtectedModeChanged();
        }
        try {
            $item->publishDraft($this->draft);
            $item->adoptSnapshotBaseline();
        } catch (InvalidFormatException $malformed) {
            throw new LogicException('A published RT item lost its upload identities: ' . $malformed->getMessage(), 0, $malformed);
        }
    }

    /**
     * @param bool $created Whether the row arrived rather than was swept
     * @param string $id Row id
     * @param RtRowValues $values Its values, or the values it had
     * @return Generator<int, RtWork, int, void> One announcement, applied-remote, in the staging's context
     * @throws HilosException Whatever a subscriber raises
     */
    private function announce(bool $created, string $id, RtRowValues $values): Generator
    {
        yield new RtWork();
        $change = $created
            ? SourceChange::rtCreated($this->collectionKey, $id, $values, $this->acceptKey, $this->requestId)
            : SourceChange::rtDeleted($this->collectionKey, $id, $values, $this->acceptKey, $this->requestId);
        SourceChangeBus::whileApplyingRemote(static function () use ($change): void {
            SourceChangeBus::publish($change);
        });
    }

    /**
     * @param RtStates<RtState> $collection Source whose kept handles settle
     * @return Generator<int, RtWork, int, void> One handle settled
     */
    private static function settleOne(RtStates $collection): Generator
    {
        yield new RtWork();
        $collection->settleOne();
    }

    /**
     * @param RtRowValues $values Whole row
     * @return Generator<int, RtWork, int, RtState> Restored, validated, unpublished row
     * @throws InvalidFormatException When the row's validator refuses it
     * @throws RtRowCodecNotSupportedException When the rows have not migrated
     */
    private function restore(RtRowValues $values): Generator
    {
        if ($this->codec === null) {
            $collection = $this->collection?->get();
            $item = $this->item?->get();
            $stateClass = $collection === null ? null : $collection::STATE_CLASS;
            if ($item !== null) {
                $this->codec = $item->rowCodec();
            } elseif ($stateClass !== null && is_subclass_of($stateClass, RtState::class)) {
                $this->codec = $stateClass::rowCodecOfClass();
            } else {
                throw new RtRowCodecNotSupportedException('The RT source of a snapshot generation has no row class to restore');
            }
        }

        return yield from $this->codec->restore($values->reader());
    }

    /**
     * @param int $bytes History about to be retained
     * @return bool Whether it fits; when it does not, this generation is canceled
     */
    private function retainHistory(int $bytes): bool
    {
        if ($this->historyBytes + $bytes > $this->historyLimit) {
            $this->close(RtSnapshotStatus::Canceled);

            return false;
        }
        $this->historyBytes += $bytes;

        return true;
    }

    /** @return bool Whether everything up to publication is done and nothing is pending */
    private function isComplete(): bool
    {
        return $this->inputFinished && $this->input === [] && $this->deltas === [] && $this->history === [] && $this->step === null
            && ($this->item !== null ? $this->merged : $this->merged && ($this->collection?->get()?->isSettled() ?? false)
                && $this->staleRevision === RtStaleness::revision($this->collectionKey));
    }

    /** @return bool Whether the runtime, the mount and the whole-source incarnation staged into still answer */
    private function isCurrent(): bool
    {
        if (!$this->basis->isCurrent()) {
            return false;
        }
        $collection = $this->collection?->get();
        $source = Hilos::$rt?->getStateCollection($this->collectionKey) ?? Hilos::$rt?->getStateItem($this->collectionKey);

        return $source !== null && $source === ($collection ?? $this->item?->get())
            && ($collection === null || $collection->snapshotEpoch() === $this->epoch);
    }

    /**
     * A failure before publication closes the generation; one after it - a subscriber refusing an
     * announcement - costs that unit alone, and the published generation goes on with the rest.
     *
     * @param RtSnapshotStatus $status Canceled or Refused
     */
    private function fail(RtSnapshotStatus $status): void
    {
        if ($this->status === RtSnapshotStatus::Published) {
            $this->step = null;

            return;
        }
        $this->close($status);
    }

    /** @return bool Whether the generation was canceled or refused */
    private function isClosed(): bool
    {
        return $this->status === RtSnapshotStatus::Canceled || $this->status === RtSnapshotStatus::Refused;
    }

    /**
     * @param RtSnapshotStatus $status Canceled or Refused
     */
    private function close(RtSnapshotStatus $status): void
    {
        $this->status = $status;
        $this->step?->cancel();
        $this->step = null;
        $this->input = [];
        $this->reserved = 0;
        $this->deltas = [];
        $this->history = [];
        $this->historyBytes = 0;
        $this->staged = [];
        $this->arrived = [];
        $this->swept = [];
        $this->draft = null;
        $this->batch?->cancel();
        $this->collection?->get()?->closeStaging($this);
        $this->releaseCandidate();
    }

    /**
     * Lets go of the candidate's rows, selector, generation and stale marks; their leases retire with
     * their last reference. The maps of swept and arrived rows go as they are announced.
     */
    private function releaseCandidate(): void
    {
        $this->live = null;
        $this->candidate = [];
        $this->batch = null;
        $this->versions = null;
        $this->generation = null;
        $this->stale = [];
        $this->staleRevision = null;
        $this->draft = null;
    }

    /**
     * @param ?RtState $row Row to read
     * @return ?string Set the row stands in, as {@see RtState::touchedSetKeys()} reads it; null for none
     */
    private static function setKeyOf(?RtState $row): ?string
    {
        if ($row === null || $row::SET_VIA === '') {
            return null;
        }
        $value = $row->{$row::SET_VIA};

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param RtRowValues $values Whole row of the source
     * @return ?string Set the row stands in, read on the field its class names; null for none
     * @throws InvalidFormatException When the values are not a field map
     * @throws LogicException When the lease closed
     */
    private function setKeyIn(RtRowValues $values): ?string
    {
        $collection = $this->collection?->get();
        $stateClass = $collection === null ? null : $collection::STATE_CLASS;
        if ($stateClass === null || !is_subclass_of($stateClass, RtState::class) || $stateClass::SET_VIA === '') {
            return null;
        }
        $value = $values->field($stateClass::SET_VIA)?->toNative();

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param RtRowValues $text Segmented key
     * @return Generator<int, RtWork, int, string> The key, its bytes charged as they are read
     * @throws InvalidFormatException When the value is not text
     * @throws LogicException When the lease closed
     */
    private static function text(RtRowValues $text): Generator
    {
        $out = '';
        $cursor = $text->reader()->text();
        while (($part = yield from $cursor->advance()) !== null) {
            $left = strlen($part);
            while ($left > 0) {
                $left -= yield new RtWork($left);
            }
            $out .= $part;
        }

        return $out;
    }
}
