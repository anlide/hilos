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
     */
    public function markApplying(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateHilosProfileNameChange::PHASE_APPLYING;
        $this->state->refusal = null;
        $this->state->deadlineAt = null;
        $this->sync();
    }

    /**
     * Retains the refusal until the next admitted attempt or session cleanup.
     *
     * @param string $refusal Server refusal sentence
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     */
    public function refuse(string $refusal): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateHilosProfileNameChange::PHASE_REFUSED;
        $this->state->refusal = $refusal;
        $this->state->deadlineAt = null;
        $this->sync();
    }

    /**
     * Retains completion until the next admitted attempt or session cleanup.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     */
    public function complete(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateHilosProfileNameChange::PHASE_DONE;
        $this->state->refusal = null;
        $this->state->deadlineAt = null;
        $this->sync();
    }

    /**
     * Removes the attempt when its session ends or changes person.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the state collection is unavailable
     * @throws RtItemParentCollectionNullException When the item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the attempt
     * @throws SourceChangeSubscriberException When a membership subscriber fails
     */
    public function drop(): void
    {
        $this->remove();
    }
}
