<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Item\HilosProfileNameChange as ViewHilosProfileNameChange;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Writes the phase of one admitted attempt; the session holder checks correlation and transitions.
 *
 * @extends RtActions<ViewHilosProfileNameChange, StateHilosProfileNameChange>
 * @property-read StateHilosProfileNameChange $state Backing attempt
 */
final class HilosProfileNameChangeActions extends RtActions
{
    /**
     * Records that the existing user owner is applying the name.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function markApplying(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_APPLYING,
            StateHilosProfileNameChange::refusal => null,
            StateHilosProfileNameChange::deadlineAt => null,
        ]);
    }

    /**
     * Retains the refusal until the next admitted attempt or session cleanup.
     *
     * @param string $refusal Server refusal sentence
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function refuse(string $refusal): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_REFUSED,
            StateHilosProfileNameChange::refusal => $refusal,
            StateHilosProfileNameChange::deadlineAt => null,
        ]);
    }

    /**
     * Retains completion until the next admitted attempt or session cleanup.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function complete(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateHilosProfileNameChange::phase => StateHilosProfileNameChange::PHASE_DONE,
            StateHilosProfileNameChange::refusal => null,
            StateHilosProfileNameChange::deadlineAt => null,
        ]);
    }

    /**
     * Removes the attempt when its session ends or changes person.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the state collection is unavailable
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws RtItemParentCollectionNullException When the item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     * @throws SourceChangeSubscriberException When a membership subscriber fails
     */
    public function drop(): void
    {
        $this->remove();
    }
}
