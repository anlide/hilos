<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use ArrayAccess;
use Countable;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Database;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Runtime\Exception\State\RtStatesCloneException;
use Hilos\Runtime\Exception\State\RtStatesUnserializeException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowBinding;
use Hilos\Runtime\Row\RtRowDescriptor;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Row\RtRowRoot;
use Hilos\Runtime\RtMembershipVersion;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Snapshot\RtPreparedIdentity;
use Hilos\Runtime\Snapshot\RtPreparedRow;
use Hilos\Runtime\Snapshot\RtSnapshotImport;
use Hilos\Runtime\Snapshot\RtVersionCut;
use Hilos\Runtime\Snapshot\RtVersionStore;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use IteratorAggregate;
use LogicException;
use OutOfBoundsException;
use WeakMap;
use WeakReference;

/**
 * Base class for runtime state collections.
 *
 * RtStates is the single source of truth for runtime state data (analogous to Objects for database).
 * It stores RtState instances and provides collection operations.
 * RtCollection instances are lightweight wrappers that reference RtStates.
 *
 * Unlike database Objects, RtStates does not load from or sync to persistent storage.
 * All data lives only in memory for the lifetime of the process.
 *
 * @template T of RtState
 * @implements ArrayAccess<string, T>
 * @implements IteratorAggregate<string, T>
 */
abstract class RtStates implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var class-string<RtState> */
    public const string STATE_CLASS = '';

    /** @var array<string, T> state ID => RtState map */
    protected array $states = [];

    /** @var ?string Name this collection is mounted under, or null while it is not mounted */
    private ?string $collectionName = null;

    /**
     * Committed selector of this collection's rows: membership in insertion order and the immutable
     * values each row was last committed with. Process-local copy machinery for snapshot reads, not a
     * replicated collection (docs/agents/runtime/rt-context.md); created by the first write.
     */
    private ?RtVersionStore $versions = null;

    /** Row lifetime tokens of the selector, never reused, so a restored or recreated row is a new one. */
    private int $incarnations = 0;

    /** @var ?WeakMap<RtState, WeakReference<self>> Collection each committed row object stands in */
    private static ?WeakMap $holders = null;

    /** Full replacement invalidates every older read, even when the same row objects return. */
    private int $snapshotEpoch = 0;

    /** Root the row handles read a published snapshot generation through, created by the first one staged */
    private ?RtRowRoot $rowRoot = null;

    /** @var ?WeakMap<RtSnapshotImport, true> Snapshot generations being staged into this collection */
    private ?WeakMap $stagings = null;

    /**
     * Protected constructor - use static factory methods.
     */
    protected function __construct()
    {
    }

    /**
     * Initialize empty collection.
     *
     * @return static New RtStates instance
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Public clone - prevent cloning.
     *
     * @throws RtStatesCloneException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new RtStatesCloneException('RtStates cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization.
     *
     * @throws RtStatesUnserializeException Always, unserialization not allowed
     */
    public function __wakeup(): void
    {
        throw new RtStatesUnserializeException('RtStates cannot be unserialized');
    }

    /**
     * Debug info.
     *
     * @return array<string, array<string, mixed>> Id => state array map
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Tells this collection the name it is mounted under.
     *
     * Called by {@see RtContext::bindStateCollectionNames()} once the registry is complete. The
     * database side has carried its own collection key since always; on this side only the view
     * knew the name, and a store that cannot name itself cannot announce its own changes.
     *
     * @param string $name Name this collection is mounted under
     */
    public function setCollectionName(string $name): void
    {
        $this->collectionName = $name;
    }

    /**
     * Returns the name this collection is mounted under.
     *
     * @return ?string Mounted name, or null while this collection is not mounted
     */
    public function getCollectionName(): ?string
    {
        return $this->collectionName;
    }

    /**
     * Add state to collection and announce the new membership.
     *
     * A state already standing under that id is replaced in this process, and its local views
     * follow. The announcement still says created: a copy in another worker, on the master or
     * on another node that already holds the id ignores it ({@see RtSyncApplicator::applyCreated()})
     * and keeps the old row, onto which later diffs are applied. A replacement that must reach
     * those copies needs a removal followed by a creation through the base actions.
     * Under an open transaction what the id held is remembered first, for a rollback to put back.
     *
     * @param T $state State instance to add
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function add(RtState $state): void
    {
        $this->rememberId($state->getId());
        if (!isset($this->states[$state->getId()])) {
            RtMembershipVersion::advance($this, $state->getId());
        }
        $carried = $state->carriedRow();
        $this->writeVersion($state->getId(), $this->states[$state->getId()] ?? null, $state, $carried);
        $this->states[$state->getId()] = $state;
        if ($this->collectionName === null) {
            return;
        }
        SourceChangeBus::publish(SourceChange::rtCreated(
            $this->collectionName,
            $state->getId(),
            $carried,
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        ));
    }

    /**
     * Remove state from collection by ID and announce the lost membership.
     *
     * The row is read before the removal, so the announcement carries what the id held rather
     * than nothing; an id holding nothing is still announced, because the callers this replaces
     * broadcast that removal too and a receiver that has the row must lose it. Under an open
     * transaction what the id held is remembered first, for a rollback to put back.
     *
     * @param string $id State ID
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function remove(string $id): void
    {
        $previous = $this->states[$id] ?? null;
        $this->rememberId($id);
        if ($previous !== null) {
            RtMembershipVersion::advance($this, $id);
        }
        $this->writeVersion($id, $previous, null);
        unset($this->states[$id]);
        if ($this->collectionName === null) {
            return;
        }
        SourceChangeBus::publish(SourceChange::rtDeleted(
            $this->collectionName,
            $id,
            $previous?->carriedRow() ?? [],
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        ));
    }

    /**
     * Check if state exists in collection.
     *
     * @param ?string $id State ID, or null for a missing optional runtime key
     * @return bool True if state exists
     */
    public function has(?string $id): bool
    {
        if ($id === null) {
            return false;
        }
        return isset($this->states[$id]);
    }

    /**
     * Get state by ID.
     *
     * @param ?string $id State ID, or null for a missing optional runtime key
     * @return ?T State instance or null if not found
     */
    public function get(?string $id): ?RtState
    {
        if ($id === null) {
            return null;
        }
        return $this->states[$id] ?? null;
    }

    /**
     * Clear all states from collection.
     *
     * Announces nothing, unlike the point mutations. A whole-collection wipe already travels its
     * own road end to end - the caller broadcasts a delete per row and empties the view cache in
     * one go - and routing it through per-row announcements would change what goes over the wire.
     * Under an open transaction the whole map is remembered first, for a rollback to put back.
     * The snapshot selector loses the rows as one prefix, committed with the transaction.
     *
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function clear(): void
    {
        foreach ($this->openStagings() as $import) {
            $import->cancel();
        }
        $this->rememberAll();
        if ($this->states !== []) {
            RtMembershipVersion::advance($this);
        }
        if ($this->versions !== null && $this->states !== []) {
            $changes = [];
            foreach ($this->states as $id => $state) {
                $changes[] = [(string)$id, $state, null, null];
            }
            $this->writeVersions($changes);
        }
        $this->states = [];
        $this->snapshotEpoch++;
    }

    /**
     * Convert all states to array.
     *
     * @param bool $idAsIndex Use ID as array index
     * @return array<string|int, array<string, mixed>> Id => state array (if idAsIndex) or list of state arrays
     */
    public function toArray(bool $idAsIndex = true): array
    {
        $result = [];
        foreach ($this->states as $id => $state) {
            if ($idAsIndex) {
                $result[$id] = $state->toArray();
            } else {
                $result[] = $state->toArray();
            }
        }
        return $result;
    }

    /**
     * Get first state.
     *
     * @return ?T First state or null if empty
     */
    public function first(): ?RtState
    {
        $keys = array_keys($this->states);
        $firstKey = $keys[0] ?? null;
        return $firstKey !== null ? $this->states[$firstKey] : null;
    }

    /**
     * Get last state.
     *
     * @return ?T Last state or null if empty
     */
    public function last(): ?RtState
    {
        $keys = array_keys($this->states);
        $lastKey = end($keys);
        return $lastKey !== false ? $this->states[$lastKey] : null;
    }

    // ==================== ArrayAccess ====================

    /**
     * Check if state exists at offset.
     *
     * @param mixed $offset State ID, or null for a missing optional runtime key
     * @return bool True if state exists
     */
    public function offsetExists(mixed $offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->states[$offset]);
    }

    /**
     * Get state at offset.
     *
     * @param mixed $offset State ID, or null for a missing optional runtime key
     * @return ?T State instance or null if not found
     * @throws OutOfBoundsException When the concrete collection requires the key and no state is stored under it
     */
    public function offsetGet(mixed $offset): ?RtState
    {
        if ($offset === null) {
            return null;
        }
        return $this->states[$offset] ?? null;
    }

    /**
     * Set state at offset.
     *
     * Routed through {@see self::add()} so that membership has exactly one announcement point,
     * which also means the state is stored under its own id and the offset is not consulted.
     * Storing a row under a key it does not answer to was never usable anyway: every read path
     * here addresses a state by {@see RtState::getId()}.
     *
     * @param mixed $offset Ignored, the state is stored under its own ID
     * @param T $value RtState instance to set
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof RtState) {
            $this->add($value);
        }
    }

    /**
     * Remove state at offset.
     *
     * Routed through {@see self::remove()} for the same single announcement point.
     *
     * @param mixed $offset State ID to remove, or null for no-op
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }
        $this->remove((string)$offset);
    }

    // ==================== Countable ====================

    /**
     * Get number of states in collection.
     *
     * @return int Number of states
     */
    public function count(): int
    {
        return count($this->states);
    }

    // ==================== IteratorAggregate ====================

    /**
     * List the state IDs currently stored, in insertion order.
     *
     * This is the snapshot a walk is taken over, and it is public because the view layer walks
     * by the keys of its truth source rather than by keys of its own wrapper cache.
     *
     * Cast on the way out, because a state id that reads as a number is an integer once it is
     * an array key, and the view layer this feeds addresses its wrappers by string.
     *
     * @return list<string> State IDs
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->states));
    }

    /**
     * @internal Pins this collection's rows as one version: membership in insertion order with
     * each row's values as they were last committed, at one revision. Constant work: nothing is
     * walked, copied or serialized here. Only committed rows are in it: a snapshot is read for
     * another process, and the prefix of an open transaction - the caller's own included - is
     * not committed until its SQL commit, and never after its rollback.
     *
     * @return ?RtVersionCut Pinned version, or null while this collection was never written
     * @throws LogicException When the selector's lease has closed
     */
    public function versionCut(): ?RtVersionCut
    {
        return $this->versions?->cut();
    }

    /** @return int Whole-source incarnation, independent of individual row admissions */
    public function snapshotEpoch(): int
    {
        return $this->snapshotEpoch;
    }

    /**
     * @internal Opens a snapshot generation's view of this collection at one committed point: the
     * rows as they stand, a detached candidate selector branched from the committed one, and the
     * root the row handles read a published generation through. From now until the generation
     * closes, every committed row change is handed to it as well ({@see RtSnapshotImport::noteLive()}).
     * Constant work: the map is shared copy-on-write and the selector branched at its root. Taken
     * outside any transaction, so the map and the committed selector agree.
     *
     * @param RtSnapshotImport $import Generation being staged
     * @return array{array<string, T>, RtVersionStore, RtRowRoot} Rows, candidate selector and resolution root
     * @throws LogicException When the selector's lease has closed
     */
    public function openStaging(RtSnapshotImport $import): array
    {
        $this->stagings ??= new WeakMap();
        $this->stagings[$import] = true;
        $this->rowRoot ??= new RtRowRoot();

        return [$this->states, ($this->versions ??= new RtVersionStore(RtRowArena::process()))->branch(), $this->rowRoot];
    }

    /**
     * @internal Stops handing committed row changes to a generation that closed or published.
     *
     * @param RtSnapshotImport $import Generation that no longer stages
     */
    public function closeStaging(RtSnapshotImport $import): void
    {
        unset($this->stagings[$import]);
    }

    /**
     * @internal A new row lifetime token for a row a generation brings into this collection, as
     * {@see self::writeVersions()} takes one for a row a write brings in.
     *
     * @return int Unused lifetime token
     */
    public function nextIncarnation(): int
    {
        return ++$this->incarnations;
    }

    /**
     * @internal Marks a row a generation is about to publish as standing in this collection, so
     * its later baseline marks reach this selector; until the row is installed here they are
     * ignored ({@see self::noteCommittedRow()}).
     *
     * @param RtState $state Row of the generation
     */
    public function holdStaged(RtState $state): void
    {
        self::$holders ??= new WeakMap();
        self::$holders[$state] = WeakReference::create($this);
    }

    /**
     * @internal Switches this collection to a staged generation in constant work: its rows, its
     * selector and the values its kept rows read through their handles become current together,
     * and no row is visited. The membership cue goes first, as for any write; a whole replacement
     * also starts a new incarnation of the source, so every older read of it ends. Every other
     * generation still staging here was built on what this one replaces and is canceled; a retry
     * takes a new cut. The publishing generation closes its own staging first.
     *
     * @param array<string, T> $states Rows of the generation, in their order
     * @param RtVersionStore $versions Its selector, already holding every change of the generation
     * @param WeakMap<RtRowBinding, array{RtRowDescriptor, RtRowDescriptor}> $generation Values and baselines of kept rows
     * @param bool $whole Whether the generation replaces the whole source
     * @throws LogicException When the previous generation is not settled yet
     */
    public function installStaged(array $states, RtVersionStore $versions, WeakMap $generation, bool $whole): void
    {
        foreach ($this->openStagings() as $outdated) {
            $outdated->cancel();
        }
        RtMembershipVersion::advance($this);
        ($this->rowRoot ??= new RtRowRoot())->install($generation);
        $versions->goLive();
        $this->versions = $versions;
        $this->states = $states;
        if ($whole) {
            $this->snapshotEpoch++;
        }
    }

    /** @return bool Whether every row handle holds what the last published generation gave it */
    public function isSettled(): bool
    {
        return $this->rowRoot?->isSettled() ?? true;
    }

    /**
     * @internal Settles one row handle of the last published generation, in constant work.
     */
    public function settleOne(): void
    {
        $this->rowRoot?->settleOne();
    }

    /**
     * Walk the states over a snapshot of the keys taken when the walk starts.
     *
     * Each walk gets its own generator, so a nested foreach over the same collection does not
     * disturb the outer one. A key removed after the snapshot was taken is skipped rather than
     * answered as null, and a state added during the walk is not seen - the snapshot is already
     * taken.
     *
     * @return Generator<string, T> State ID => RtState
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $state = $this->states[$key] ?? null;
            if ($state === null) {
                continue;
            }
            yield $key => $state;
        }
    }

    /**
     * Remembers what one id holds, for a rollback to put it back: the state, or nothing.
     *
     * Outside a transaction nothing is remembered ({@see Database::onRollback()}). The step puts
     * the very instance back - so View wrappers holding it keep their state - or drops the id, and
     * tells the mirrors when the instance under the id changed by it.
     *
     * @param string $id State ID about to change
     */
    private function rememberId(string $id): void
    {
        $held = $this->states[$id] ?? null;
        Database::onRollback(function () use ($id, $held): void {
            $replaced = $this->states[$id] ?? null;
            if (($held === null) !== ($replaced === null)) {
                RtMembershipVersion::advance($this, $id);
            }
            if ($held === null) {
                unset($this->states[$id]);
            } else {
                $this->states[$id] = $held;
                self::$holders[$held] = WeakReference::create($this);
            }
            $this->announceRestored($id, $held, $replaced);
        });
    }

    /**
     * Remembers the whole map, for a rollback to put it back after a wipe.
     *
     * The map is copied lazily: its first change after this pays for one copy. The step tells the
     * mirrors about every id whose instance it changed.
     */
    private function rememberAll(): void
    {
        $map = $this->states;
        Database::onRollback(function () use ($map): void {
            $replaced = $this->states;
            $this->states = $map;
            RtMembershipVersion::advance($this);
            $this->snapshotEpoch++;
            foreach ($map as $state) {
                self::$holders[$state] = WeakReference::create($this);
            }
            foreach (array_keys($map + $replaced) as $id) {
                $this->announceRestored((string)$id, $map[$id] ?? null, $replaced[$id] ?? null);
            }
        });
    }

    /**
     * @internal Commits a row's new values into the selector of the collection it stands in: the
     * late half of {@see RtState::sync()} and of a baseline mark, once the row's values are what
     * it announced. A row standing in no collection, or replaced there since, is not written, and
     * nothing is built for it: the whole row is carried only once a holder is found.
     *
     * @param RtState $state Row whose announced values changed
     * @param array<string, mixed>|RtRowValues|null $carried Whole row as it now stands when the caller
     *        already holds it, null to carry it here
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public static function noteCommittedRow(RtState $state, array|RtRowValues|null $carried = null): void
    {
        $holder = isset(self::$holders[$state]) ? self::$holders[$state]->get() : null;
        if ($holder !== null && ($holder->states[$state->getId()] ?? null) === $state) {
            $holder->writeVersion($state->getId(), $state, $state, $carried);
        }
    }

    /**
     * Prepares one row change in the selector and applies it: at once outside a transaction, at
     * the SQL commit inside one, and never after a rollback, which closes the prepared prefix
     * itself - so the rollback steps of this class do not write here. A new row object takes a
     * new lifetime token and goes to the end of the insertion order; the same object keeps both.
     *
     * Runs to its end in one step and retires what it released itself, as the other synchronous
     * doors of the process arena do ({@see RtRowArena::openDoor()}); the backlog it finds stays for
     * the RT pass. A closing test scope refuses, and a write it refuses is the rolled-back prefix
     * the selector already forgets.
     *
     * @param string $id State ID
     * @param ?T $previous Row object standing under the id before this change
     * @param ?T $next Row object standing under it after, null for a removal
     * @param array<string, mixed>|RtRowValues|null $carried Whole new row when already exported
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    private function writeVersion(string $id, ?RtState $previous, ?RtState $next, array|RtRowValues|null $carried = null): void
    {
        if ($previous !== null || $next !== null) {
            $this->writeVersions([[$id, $previous, $next, $carried]]);
        }
    }

    /**
     * Prepares several row changes as one prefix of the selector and applies it, as
     * {@see self::writeVersion()} applies one.
     *
     * @param list<array{string, ?T, ?T, array<string, mixed>|RtRowValues|null}> $changes Id, row before, row after
     *        and the whole new row when already exported, per change
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    private function writeVersions(array $changes): void
    {
        $arena = RtRowArena::process();
        $noted = [];
        $arena->openDoor();
        try {
            $store = $this->versions ??= new RtVersionStore($arena);
            $batch = $store->begin();
            $changed = false;
            foreach ($changes as [$id, $previous, $next, $carried]) {
                if ($previous !== null && $previous !== $next && isset(self::$holders[$previous])
                    && self::$holders[$previous]->get() === $this) {
                    unset(self::$holders[$previous]);
                }
                if ($next !== null) {
                    self::$holders ??= new WeakMap();
                    self::$holders[$next] = WeakReference::create($this);
                }
                $values = $next === null ? null : RtRowValues::carried($carried ?? $next->carriedRow());
                $noted[] = [$id, $previous, $next, $values];
                $identity = RtRowValues::completed(RtPreparedIdentity::prepare(
                    RtRowValues::completed(RtRowWriter::encode($id, $arena, RtRowRole::Transient)),
                    $values === null ? null : self::setIdentity($next, $values, $arena),
                ));
                $existing = RtRowValues::completed($batch->find($identity));
                if ($existing !== null && $previous !== $next) {
                    RtRowValues::completed($batch->change(TableMutationType::Delete, $existing));
                    $existing = null;
                    $changed = true;
                }
                if ($values !== null) {
                    $record = RtRowValues::completed(
                        RtPreparedRow::prepare($identity, $values, $values, $existing?->incarnation() ?? ++$this->incarnations),
                    );
                    RtRowValues::completed($batch->change($existing === null ? TableMutationType::Create : TableMutationType::Update, $record));
                    $changed = true;
                }
            }
            if ($changed) {
                $store->apply($batch);
                $this->handToStagings($noted);
            }
        } catch (TestRollbackRefusedException) {
            // A closing test scope: the selector keeps what was committed, and this prefix is
            // the one the scope's rollback forgets.
        } catch (LogicException $broken) {
            throw new FrameworkLogicException('The RT snapshot selector refused a row change: ' . $broken->getMessage(), previous: $broken);
        } finally {
            unset($batch, $store, $values, $identity, $existing, $record);
            $arena->closeDoor();
        }
    }

    /**
     * Hands committed row changes to the generations staging into this collection, once they are
     * committed: at once with no transaction open, at the commit inside one, never after a rollback.
     *
     * @param list<array{string, ?T, ?T, ?RtRowValues}> $noted Id, row before, row after and its values, per change
     */
    private function handToStagings(array $noted): void
    {
        if ($this->openStagings() === []) {
            return;
        }
        Database::afterCommit(function () use ($noted): void {
            foreach ($this->openStagings() as $import) {
                foreach ($noted as [$id, $previous, $next, $values]) {
                    $import->noteLive($id, $previous, $next, $values);
                }
            }
        });
    }

    /** @return list<RtSnapshotImport> Generations staging into this collection now */
    private function openStagings(): array
    {
        $open = [];
        foreach ($this->stagings ?? [] as $import => $_) {
            $open[] = $import;
        }

        return $open;
    }

    /**
     * Segments the set a row stands in, read as {@see RtState::touchedSetKeys()} reads it.
     *
     * @param RtState $state Row being written
     * @param RtRowValues $values Its whole carried row
     * @param RtRowArena $arena Selector's storage owner
     * @return ?RtRowValues Set key text, null when the row class names no set field or the row is in nobody's set
     * @throws InvalidFormatException When the values are not a field map
     * @throws LogicException When a value lease closed
     */
    private static function setIdentity(RtState $state, RtRowValues $values, RtRowArena $arena): ?RtRowValues
    {
        if ($state::SET_VIA === '') {
            return null;
        }
        $value = $values->field($state::SET_VIA)?->toNative();

        return is_scalar($value) ? RtRowValues::completed(RtRowWriter::encode((string)$value, $arena, RtRowRole::Transient)) : null;
    }

    /**
     * Tells the mirrors a rollback changed what one id holds: the state put back, or the one taken away.
     *
     * The mirrors alone, the way they heard the write itself: a reaction and the other processes
     * never heard what the rollback takes back. A collection that is not mounted has no name to
     * tell it under, and tells nothing, as its writes do.
     *
     * @param string $id State ID the rollback changed
     * @param ?T $restored State the id holds again, null when the rollback dropped the id
     * @param ?T $replaced State the id held until the rollback, null when it held none
     * @throws SourceChangeSubscriberException Whatever a mirror raises
     */
    private function announceRestored(string $id, ?RtState $restored, ?RtState $replaced): void
    {
        if ($this->collectionName === null || $restored === $replaced) {
            return;
        }

        if ($restored !== null) {
            SourceChangeBus::publishToMirrors(SourceChange::rtCreated(
                $this->collectionName,
                $id,
                $restored->carriedRow(),
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ));
        } elseif ($replaced !== null) {
            SourceChangeBus::publishToMirrors(SourceChange::rtDeleted(
                $this->collectionName,
                $id,
                $replaced->carriedRow(),
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ));
        }
    }
}
