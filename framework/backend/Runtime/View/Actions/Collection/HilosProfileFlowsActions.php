<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfileFlows as StateHilosProfileFlows;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\View\Collection\HilosProfileFlows;
use Hilos\Runtime\View\Item\HilosProfileFlow;

/**
 * Write API for the profile windows half-way through (HIL-1182).
 *
 * Every write is named by the session and the window, because that is all the writer knows: the
 * users library reports a step of the window a person submitted from, and the session holder that
 * owns the rows writes it here. A step REWRITES the window's row rather than adding to it - a
 * window is on one step at a time - and an ending takes it away.
 *
 * @extends RtActions<HilosProfileFlow, HilosProfileFlows, StateHilosProfileFlows>
 * @property-read StateHilosProfileFlows $stateCollection
 */
final class HilosProfileFlowsActions extends RtActions
{
    /**
     * Writes the step one window of one session has reached, creating its row or rewriting it.
     *
     * An existing row is patched rather than replaced, so the tabs following it are told what
     * moved and not that a new flow was born.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $operation Operation key of the window
     * @param int $userId The person the flow is for
     * @param string $step One of the STEP_* constants on {@see StateHilosProfileFlow}
     * @param string $address The account's address the proof stands on
     * @param ?string $target New address of an email change, on its last step alone
     * @param int $expiresAt Epoch milliseconds the code of the proof dies at
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function put(
        string $sessionTokenHash,
        string $operation,
        int $userId,
        string $step,
        string $address,
        ?string $target,
        int $expiresAt,
    ): void {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get(StateHilosProfileFlow::idFor($sessionTokenHash, $operation));
        if ($state === null) {
            $this->addStateToCollection(StateHilosProfileFlow::create(
                $sessionTokenHash,
                $operation,
                $userId,
                $step,
                $address,
                $target,
                $expiresAt,
            ));

            return;
        }

        $this->applyDiffToState($state, [
            StateHilosProfileFlow::userId => $userId,
            StateHilosProfileFlow::step => $step,
            StateHilosProfileFlow::address => $address,
            StateHilosProfileFlow::target => $target,
            StateHilosProfileFlow::expiresAt => $expiresAt,
        ]);
    }

    /**
     * Takes one window's flow away - finished, or discarded by the person.
     *
     * A window with no row is a no-op rather than a failure: a discard can race the step that
     * finished the flow in another tab, and the second of them finds nothing left to take.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $operation Operation key of the window
     * @return bool Whether a flow was there to take away
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function drop(string $sessionTokenHash, string $operation): bool
    {
        $this->ensureCanWrite();

        $id = StateHilosProfileFlow::idFor($sessionTokenHash, $operation);
        if (!$this->stateCollection->has($id)) {
            return false;
        }

        $this->removeStateFromCollection($id);

        return true;
    }

    /**
     * Takes every flow of one session away, because the session has changed person.
     *
     * A proof belongs to the person who gave it: signing out, signing in and a takeover starting
     * or ending all leave the session's windows with nothing they may continue.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @return bool Whether any flow was there to take away
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function forget(string $sessionTokenHash): bool
    {
        $this->ensureCanWrite();

        $ids = [];
        foreach ($this->stateCollection as $state) {
            if ($state->sessionTokenHash === $sessionTokenHash) {
                $ids[] = $state->getId();
            }
        }

        foreach ($ids as $id) {
            $this->removeStateFromCollection($id);
        }

        return $ids !== [];
    }

    /**
     * Drops the flows whose code has died (HIL-1182).
     *
     * The rows' only reclamation by time, and the time is not this collection's: a row dies with
     * the code its proof stands on, at the moment it copied from that code. Past it the next step
     * would be refused anyway, so a row still saying "proven" would be describing nothing.
     *
     * @param int $nowMs Epoch milliseconds to judge by
     * @return int Number of flows dropped
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function forgetExpired(int $nowMs): int
    {
        $this->ensureCanWrite();

        $ids = [];
        foreach ($this->stateCollection as $state) {
            if ($state->expiresAt <= $nowMs) {
                $ids[] = $state->getId();
            }
        }

        foreach ($ids as $id) {
            $this->removeStateFromCollection($id);
        }

        return count($ids);
    }
}
