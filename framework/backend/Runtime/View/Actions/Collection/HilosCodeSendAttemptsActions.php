<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosCodeSendAttempts as StateHilosCodeSendAttempts;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\View\Collection\HilosCodeSendAttempts;
use Hilos\Runtime\View\Item\HilosCodeSendAttempt;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Write API for the code send attempts, and the home of the stale-report rule (HIL-826).
 *
 * Three methods, and the middle one is the reason the class exists. {@see self::start()} and
 * {@see self::drop()} are the send being ordered and the wait being let go, both named by the
 * session they belong to. {@see self::advance()} is a transport reporting a step, and it is
 * named by the TICKET instead - because by the time a step arrives the line may already be
 * following a different send, and the transport has no way of knowing that.
 *
 * Keying the judgement on the ticket is what settles the resend race without comparing
 * moments: a report of an attempt the line has moved on from finds no row carrying its ticket
 * and changes nothing. The alternative - taking the newest report - would let a dying attempt
 * paint its refusal over the send that replaced it.
 *
 * @extends RtActions<HilosCodeSendAttempt, HilosCodeSendAttempts, StateHilosCodeSendAttempts>
 * @property-read StateHilosCodeSendAttempts $stateCollection
 */
final class HilosCodeSendAttemptsActions extends RtActions
{
    /**
     * Opens the line for one send, replacing whatever the session was watching before.
     *
     * A resend REPLACES rather than adds: the screen shows one line, about the code the person
     * is waiting for now. The row is born queued, which is the truth at this moment - the order
     * is placed and no transport has picked it up yet. Replacing is what the store does with a
     * second row under one id anyway, and it announces the new membership as a creation, so the
     * previous line needs no removal of its own.
     *
     * @param string $sessionTokenHash Hash of the session cookie token the line is addressed to
     * @param string $ticket Ticket of the send this line follows
     * @param string $channel Channel the code travels over - `email` or a code channel key
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function start(string $sessionTokenHash, string $ticket, string $channel): void
    {
        $this->ensureCanWrite();

        $this->addStateToCollection(StateHilosCodeSendAttempt::create(
            $sessionTokenHash,
            $ticket,
            $channel,
            TimeHelper::nowMs(),
        ));
    }

    /**
     * Moves the line one step, if the step belongs to the send the line is following.
     *
     * The detail is written on every accepted step rather than only on a refusal, because the
     * two emptinesses differ: a send that goes back to queued after a retryable refusal has to
     * LOSE the sentence it was carrying, or the screen would show yesterday's reason under
     * today's state.
     *
     * @param string $ticket Ticket the reporting transport was given
     * @param string $state One of the five states on {@see StateHilosCodeSendAttempt}
     * @param ?string $detail Provider's sentence, on a refusal and nowhere else
     * @return ?string Session token hash of the row that moved, or null when the ticket is stale
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function advance(string $ticket, string $state, ?string $detail): ?string
    {
        $this->ensureCanWrite();

        foreach ($this->stateCollection as $attempt) {
            if ($attempt->ticket !== $ticket) {
                continue;
            }

            $this->applyDiffToState($attempt, [
                StateHilosCodeSendAttempt::state => $state,
                StateHilosCodeSendAttempt::detail => $detail,
                StateHilosCodeSendAttempt::updatedAt => TimeHelper::nowMs(),
            ]);

            return $attempt->getId();
        }

        return null;
    }

    /**
     * Drops the lines that have outlived the codes they describe (HIL-826).
     *
     * The line's only reclamation, and it is measured in TIME rather than in live sockets -
     * which is the opposite of the rule the toast stack settles by, and deliberately so. A
     * toast lives while somebody may be looking at it, so a session with no tab loses it; the
     * line has to SURVIVE having no tab, because a reload is exactly the case it exists to
     * cover. The row outliving its browser for a few minutes is the price of that.
     *
     * What ends it instead is the code going stale: past the challenge's own lifetime there
     * is nothing left to enter, so a line still saying `sent` is describing a code nobody can
     * use. Every other end is somebody's decision and is written where the decision is - a
     * resend replaces the row, and letting a wait go drops it.
     *
     * @param int $maxAgeMs How long a line may go unwritten before it is reclaimed
     * @return int Number of lines dropped
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function forgetStale(int $maxAgeMs): int
    {
        $this->ensureCanWrite();

        $oldestKept = TimeHelper::nowMs() - $maxAgeMs;

        $dropped = 0;
        foreach ($this->stateCollection as $state) {
            if ($state->updatedAt >= $oldestKept) {
                continue;
            }

            $this->removeStateFromCollection($state->getId());
            $dropped++;
        }

        return $dropped;
    }

    /**
     * Takes the line away because the wait it belonged to is over.
     *
     * A session with no row is a no-op rather than a failure: the line is dropped wherever the
     * wait is dropped, and several of those places run for a session that never ordered a code.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @return bool Whether a line was there to take away
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function drop(string $sessionTokenHash): bool
    {
        $this->ensureCanWrite();

        if (!$this->stateCollection->has($sessionTokenHash)) {
            return false;
        }

        $this->removeStateFromCollection($sessionTokenHash);

        return true;
    }
}
