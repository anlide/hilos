<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Transaction\TransactionLevel;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtMutation;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use LogicException;
use WeakReference;

/**
 * @internal Prepares index roots and ordered changes before transaction installation.
 * Every path, collision prefix and change lives in arena pages, including on cancel.
 * A mounted collection prepares its committed row changes here, through its selector.
 */
final class RtPreparedBatch
{
    /** @var ?WeakReference<TransactionLevel> Scope identity without retaining a completed level's native undo journal */
    public readonly ?WeakReference $level;

    private RtVersionDescriptor $prepared;
    private bool $busy = false;
    private bool $closed = false;
    private bool $changed = false;

    /**
     * @internal The store has already admitted this base/lineage.
     *
     * @param RtVersionStore $store Target store
     * @param RtVersionDescriptor $base Independently held candidate base
     * @param RtVersionClaim $claim Exclusive preparation lineage
     * @param bool $continuePrefix Whether this base belongs to the same open transaction
     * @param ?TransactionLevel $level Exact connection level that admitted preparation
     */
    public function __construct(
        public readonly RtVersionStore $store,
        public readonly RtVersionDescriptor $base,
        public readonly RtVersionClaim $claim,
        private readonly bool $continuePrefix,
        ?TransactionLevel $level,
    ) {
        $this->level = $level === null ? null : WeakReference::create($level);
        $this->prepared = $base;
        $claim->retain();
    }

    /** Cancels unfinished generations without walking their graph. */
    public function __destruct()
    {
        $this->cancel();
    }

    /**
     * @param RtPreparedIdentity $identity Segmented target key
     * @return Generator<int, RtWork, int, ?RtPreparedRow> Current candidate record, including earlier compound writes
     * @throws InvalidFormatException When the identity is malformed
     * @throws LogicException When preparation is closed, busy or uses another arena
     */
    public function find(RtPreparedIdentity $identity): Generator
    {
        if ($this->closed || $this->busy) {
            throw new LogicException('The RT version batch is closed or preparing a change');
        }
        $cut = new RtVersionCut($this->prepared->retain(RtRowRole::Transient));

        return yield from $cut->find($identity);
    }

    /**
     * @param TableMutationType $type Prepared C/U/D operation
     * @param RtPreparedRow $row Validated immutable row; delete uses its identity/incarnation
     * @return Generator<int, RtWork, int, void> Candidate root and change prefix preparation
     * @throws InvalidFormatException When a prepared identity is malformed
     * @throws LogicException When the batch closed, a target is stale, or an operation is invalid
     */
    public function change(TableMutationType $type, RtPreparedRow $row): Generator
    {
        yield new RtWork();
        if ($this->closed || $this->busy) {
            throw new LogicException('The RT version batch is closed or already preparing a change');
        }
        if (!in_array($type, [TableMutationType::Create, TableMutationType::Update, TableMutationType::Delete], true)) {
            throw new LogicException('The RT version batch accepts only create, update and delete');
        }
        $this->busy = true;
        try {
            $arena = $this->base->arena;
            $row->descriptor->assertArena($arena);
            $identity = $row->identity();
            $source = $this->prepared->root->retain(RtRowRole::Transient);
            $version = $source->rootIn($arena);
            $index = new RtPersistentIndex($arena);
            $bucket = yield from $index->find($version->first, $identity->hash());
            $entry = $bucket;
            $path = null;
            while ($entry !== null) {
                yield new RtWork();
                if (yield from $identity->matches($entry->first->first)) {
                    break;
                }
                $path = yield from RtVersionPages::make($arena, RtRowKind::Path, first: $entry, next: $path?->rootIn($arena));
                $entry = $entry->next;
            }
            if (($type === TableMutationType::Create) !== ($entry === null)) {
                throw new LogicException('The RT version target does not match its prepared operation');
            }
            if ($entry !== null && $entry->first->scalar !== $row->incarnation()) {
                throw new LogicException('The RT version target belongs to another row incarnation');
            }
            if ($this->prepared->revision === PHP_INT_MAX
                || ($type === TableMutationType::Create && $this->prepared->nextOrdinal === PHP_INT_MAX)) {
                throw new LogicException('The RT version revision or insertion ordinal is exhausted');
            }
            $ordinal = $entry?->scalar ?? $this->prepared->nextOrdinal;
            $before = $entry?->first;
            $after = $type === TableMutationType::Delete ? null : $row->descriptor->rootIn($arena);
            if ($type === TableMutationType::Create) {
                $membership = yield from RtVersionPages::make($arena, RtRowKind::Membership, $ordinal, $after, next: $bucket);
                $replacement = $membership;
                // A create prepends directly; its search prefix must not be copied again.
                $path = null;
            } else {
                $membership = $after === null ? null : yield from RtVersionPages::make(
                    $arena,
                    RtRowKind::Membership,
                    $ordinal,
                    $after,
                    next: $entry->next,
                );
                $replacement = $membership;
                if ($after === null && $entry->next !== null) {
                    $replacement = new RtRowValues($arena, $entry->next, RtRowRole::Transient);
                }
            }
            while ($path !== null) {
                yield new RtWork();
                $frame = $path->rootIn($arena);
                $replacement = yield from RtVersionPages::make(
                    $arena,
                    RtRowKind::Membership,
                    $frame->first->scalar,
                    $frame->first->first,
                    next: $replacement?->rootIn($arena),
                );
                $path = $frame->next === null ? null : new RtRowValues($arena, $frame->next, RtRowRole::Transient);
            }
            $keys = yield from $index->replace($version->first, $identity->hash(), $replacement?->rootIn($arena));
            // The ordered tree owns one record per insertion ordinal, never a collision tail.
            $orderedEntry = $after === null ? null : yield from RtVersionPages::make($arena, RtRowKind::Membership, $ordinal, $after);
            $order = yield from $index->replace($version->second, RtPersistentIndex::ordinal($ordinal), $orderedEntry?->rootIn($arena));
            $revision = $this->prepared->revision + 1;
            // The changes prefix is built only while somebody holds it ({@see RtVersionStore::retainChanges()}):
            // nobody else reads it, and its records would keep the replaced rows' values alive.
            $changes = null;
            if ($this->store->keepsChanges()) {
                $mutation = yield from RtMutation::prepare(
                    $type,
                    $before === null ? null : new RtPreparedRow($arena, new RtRowValues($arena, $before, RtRowRole::Transient)),
                    $after === null ? null : $row,
                    yield from RtRowWriter::encode([], $arena),
                );
                $change = yield from RtVersionPages::make($arena, RtRowKind::Change, $revision, $mutation->descriptor->rootIn($arena));
                $changes = yield from $index->replace(
                    $this->changed || $this->continuePrefix ? $version->next : null,
                    RtPersistentIndex::ordinal($revision),
                    $change->rootIn($arena),
                );
            }
            $root = yield from RtVersionPages::make(
                $arena,
                RtRowKind::Version,
                $revision,
                $keys?->rootIn($arena),
                $order?->rootIn($arena),
                $changes?->rootIn($arena),
            );
            yield new RtWork();
            if ($this->closed) {
                throw new LogicException('The RT version batch was canceled during preparation');
            }
            $this->prepared = new RtVersionDescriptor(
                $arena,
                $root->retain(RtRowRole::Candidate),
                $revision,
                $this->prepared->nextOrdinal + ($type === TableMutationType::Create ? 1 : 0),
                $this->prepared->count + match ($type) {
                    TableMutationType::Create => 1,
                    TableMutationType::Delete => -1,
                    default => 0,
                },
            );
            $this->changed = true;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Reserves the installation lease before the database can commit.
     *
     * @return RtVersionDescriptor Prepared installation lease, still charged as candidate
     * @throws LogicException When no complete changed prefix is available
     */
    public function installation(): RtVersionDescriptor
    {
        if ($this->closed || $this->busy || !$this->changed) {
            throw new LogicException('The RT version batch has no complete changed prefix');
        }

        return $this->prepared->retain(RtRowRole::Candidate);
    }

    /** Closes publication now; arena releases only this batch's own fixed root leases. */
    public function cancel(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->prepared->root->release();
            $this->base->root->release();
            $this->claim->release();
        }
    }
}
