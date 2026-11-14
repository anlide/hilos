<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Database\Database;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal One process-local committed selector on the existing Runtime arena.
 * Roots/cursors are copy machinery, not replicated application rows (rt-context).
 * Each mounted runtime collection writes its committed rows here; the admission of those writes
 * on their source's ordinary FIFO is {@see RtOrdinaryAdmission}'s alone.
 */
final class RtVersionStore
{
    private RtVersionDescriptor $committed;
    private ?RtVersionClaim $claim = null;

    /** A candidate selector beside the live one: its preparations ignore the caller's transaction */
    private bool $detached = false;

    /** Readers holding the changes prefix ({@see RtVersionCut::changes()}); none, and no prefix is built */
    private int $changeHolders = 0;

    /**
     * @param RtRowArena $arena Existing value/descriptor storage owner
     */
    public function __construct(public readonly RtRowArena $arena)
    {
        $this->committed = new RtVersionDescriptor(
            $arena,
            new RtRowValues($arena, $arena->allocate(RtRowKind::Version, 0), RtRowRole::Resident),
        );
    }

    /**
     * A detached candidate selector starting from this one's committed version, in constant work:
     * a snapshot generation prepares its version there across passes, under its own lineage, while
     * this store goes on taking committed writes. Its preparations install at once, whatever
     * transaction the caller has open, until it goes live ({@see self::goLive()}).
     *
     * @return self Candidate selector sharing this arena and revision line
     * @throws LogicException When the committed lease has closed
     */
    public function branch(): self
    {
        $branch = new self($this->arena);
        $branch->committed = $this->committed->retain(RtRowRole::Candidate);
        $branch->detached = true;

        return $branch;
    }

    /** @internal A published candidate becomes the live selector: later writes follow transactions again. */
    public function goLive(): void
    {
        $this->detached = false;
        $this->committed->root->reclassify(RtRowRole::Resident);
    }

    /**
     * Holds the changes prefix: while a reader - the owner delivering this selector's changes -
     * holds it, every committed batch records its changes in order ({@see RtVersionCut::changes()}).
     * With no holder no batch records any, so a write builds no change record and a committed
     * version keeps no records of the rows it replaced: the next install leaves none behind.
     */
    public function retainChanges(): void
    {
        ++$this->changeHolders;
    }

    /** Lets go of one hold taken with {@see self::retainChanges()}. */
    public function releaseChanges(): void
    {
        $this->changeHolders = max(0, $this->changeHolders - 1);
    }

    /** @return bool Whether a reader holds the changes prefix, so batches record their changes */
    public function keepsChanges(): bool
    {
        return $this->changeHolders > 0;
    }

    /**
     * @return RtVersionCut One independently pinned committed root and revision
     * @throws LogicException When the internal committed lease has closed
     */
    public function cut(): RtVersionCut
    {
        return new RtVersionCut($this->committed->retain(RtRowRole::Snapshot));
    }

    /**
     * Local transactional reads follow the prepared selector; committed cuts stay frozen.
     *
     * @param ?int $index Explicit connection, or named/ambient scope
     * @return RtVersionCut Current local transactional version
     * @throws LogicException When the selected root lease closed
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    public function currentCut(?int $index = null): RtVersionCut
    {
        $version = Database::runtimeTransactionLevel($index)?->runtimeParticipant($this)?->version ?? $this->committed;

        return new RtVersionCut($version->retain(RtRowRole::Snapshot));
    }

    /**
     * Begin and apply stay within one handler; a DB transaction never spans RT passes.
     * Independent scopes can use independent stores. Simultaneous writes of the same
     * store from unrelated levels refuse before publication rather than losing a commit.
     *
     * @param ?int $index Explicit connection, or the current named/ambient scope
     * @return RtPreparedBatch Unpublished preparation generation
     * @throws LogicException When another generation/connection owns this store's base
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    public function begin(?int $index = null): RtPreparedBatch
    {
        $level = $this->detached ? null : Database::runtimeTransactionLevel($index);
        $participant = $level?->runtimeParticipant($this);
        if ($this->claim !== null && ($participant === null || $participant->claim !== $this->claim)) {
            throw new LogicException('Another RT preparation lineage holds this store');
        }
        $this->claim ??= new RtVersionClaim($this);

        return new RtPreparedBatch(
            $this,
            ($participant?->version ?? $this->committed)->retain(RtRowRole::Candidate),
            $this->claim,
            $participant !== null,
            $level,
        );
    }

    /**
     * All checks and the installation lease are prepared before SQL can commit.
     * An open transaction retains the prepared descriptor; a bare write installs now.
     *
     * @param RtPreparedBatch $batch Completed generation from this store
     * @param ?int $index Explicit connection, or the current named/ambient scope
     * @throws LogicException When the preparation base/scope changed or is incomplete
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     */
    public function apply(RtPreparedBatch $batch, ?int $index = null): void
    {
        $level = $this->detached ? null : Database::runtimeTransactionLevel($index);
        $participant = $level?->runtimeParticipant($this);
        $expected = $participant?->version ?? $this->committed;
        if ($batch->store !== $this || $batch->level?->get() !== $level || ($batch->level !== null && $level === null)
            || $batch->claim !== $this->claim
            || $batch->base->root->rootIn($this->arena) !== $expected->root->rootIn($this->arena)) {
            throw new LogicException('The prepared RT version base or transaction scope has changed');
        }
        $prepared = new RtVersionTransaction($this, $batch->installation(), $batch->claim);
        if ($level === null) {
            $prepared->install();
        } else {
            $level->prepareRuntime($prepared);
        }
        $batch->cancel();
    }

    /**
     * @internal The prepared participant alone owns this non-validating pointer switch.
     *
     * @param RtVersionDescriptor $version Complete resident descriptor and revision prefix
     */
    public function installPrepared(RtVersionDescriptor $version): void
    {
        $this->committed = $version;
    }

    /**
     * @internal A lineage closes only its own admission, never another retained cut.
     *
     * @param RtVersionClaim $claim Last holder's lineage
     */
    public function releaseClaim(RtVersionClaim $claim): void
    {
        if ($this->claim === $claim) {
            $this->claim = null;
        }
    }
}
