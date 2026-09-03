<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosSessionToastStacks as StateHilosSessionToastStacks;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Collection\HilosSessionToastStacks;
use Hilos\Runtime\View\Item\HilosSessionToastStack;

/**
 * Write API for the session toast stacks, and the home of the removal rule (HIL-768).
 *
 * Four of the five methods are a tab or a sender speaking - a card is raised, closed, reported
 * burned down, or the stack is being read here. The fifth, {@see self::settle()}, is the only
 * one that DECIDES, and it is deliberately separate from all four: a card goes away when a
 * countdown somewhere has finished AND nobody is reading, and neither half of that is known to
 * whichever tab just spoke. Keeping the judgement in one method is what stops the three
 * entrances from each growing their own slightly different version of it.
 *
 * Every method that returns a bool answers ONE question - did the list of cards the session is
 * shown change? - because that is exactly what the caller does with it: the stack travels to
 * the browsers as a whole list, and a frame that says what the last one said is a frame nobody
 * needed. A report of a burned-down countdown and a cursor arriving therefore answer false
 * even though they wrote the row: they change who has answered, not what is on screen.
 *
 * @extends RtActions<HilosSessionToastStack, HilosSessionToastStacks, StateHilosSessionToastStacks>
 * @property-read StateHilosSessionToastStacks $stateCollection
 */
final class HilosSessionToastStacksActions extends RtActions
{
    /**
     * Puts one card on a session's stack, or counts a repeat of the one already there.
     *
     * The xN count is kept HERE rather than in the browser, which is where the same count is
     * kept for a toast of one's own action. A tab that merged two cards of its own knows both
     * are its own; a tab that merged two of the session's would hold one key for a row the
     * server thinks is two, and closing it would take away one card and leave the other to
     * come back on the next frame.
     *
     * A repeat clears the burned-down reports of the card it lands on, because every tab
     * restarts its countdown when the count changes: reports made against the previous
     * showing would otherwise expire a card the moment it reappeared.
     *
     * @param string $sessionTokenHash Hash of the session cookie token the card is addressed to
     * @param string $key Freshly minted name for the card, used when it is not a repeat
     * @param string $message Sentence the person reads
     * @param SessionToastSeverity $severity Which of the four kinds the card is
     * @param string $source Who is speaking, drawn above the sentence
     * @param string $destination Where clicking the card takes the person
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function raise(
        string $sessionTokenHash,
        string $key,
        string $message,
        SessionToastSeverity $severity,
        string $source,
        string $destination,
    ): void {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($sessionTokenHash);
        $toasts = $state?->toasts ?? [];
        foreach ($toasts as $index => $toast) {
            if (
                $toast[StateHilosSessionToastStack::TOAST_MESSAGE] !== $message
                || $toast[StateHilosSessionToastStack::TOAST_SEVERITY] !== $severity->value
                || $toast[StateHilosSessionToastStack::TOAST_SOURCE] !== $source
                || $toast[StateHilosSessionToastStack::TOAST_DESTINATION] !== $destination
            ) {
                continue;
            }

            $toasts[$index][StateHilosSessionToastStack::TOAST_REPEATS]
                = $toast[StateHilosSessionToastStack::TOAST_REPEATS] + 1;
            $toasts[$index][StateHilosSessionToastStack::TOAST_EXPIRED_BY] = [];
            $this->applyDiffToState($state, [StateHilosSessionToastStack::toasts => $toasts]);

            return;
        }

        $toasts[] = [
            StateHilosSessionToastStack::TOAST_KEY => $key,
            StateHilosSessionToastStack::TOAST_MESSAGE => $message,
            StateHilosSessionToastStack::TOAST_SEVERITY => $severity->value,
            StateHilosSessionToastStack::TOAST_SOURCE => $source,
            StateHilosSessionToastStack::TOAST_DESTINATION => $destination,
            StateHilosSessionToastStack::TOAST_REPEATS => 1,
            StateHilosSessionToastStack::TOAST_EXPIRED_BY => [],
        ];

        if ($state === null) {
            $this->addStateToCollection(StateHilosSessionToastStack::create($sessionTokenHash, $toasts));

            return;
        }

        $this->applyDiffToState($state, [StateHilosSessionToastStack::toasts => $toasts]);
    }

    /**
     * Takes one card away because a person closed it.
     *
     * The half of the removal rule that needs no weighing: closing is an answer, one person is
     * behind one session, and an answer given in any tab is given in all of them. A key nobody
     * has heard of is a no-op rather than a failure - a tab whose frame crossed the removal on
     * the wire is closing something it can still see.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $key Name of the card being closed
     * @return bool Whether the session's list of cards changed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function dismiss(string $sessionTokenHash, string $key): bool
    {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($sessionTokenHash);
        if ($state === null) {
            return false;
        }

        $kept = [];
        foreach ($state->toasts as $toast) {
            if ($toast[StateHilosSessionToastStack::TOAST_KEY] !== $key) {
                $kept[] = $toast;
            }
        }
        if (count($kept) === count($state->toasts)) {
            return false;
        }

        $this->writeRemaining($state, $kept);

        return true;
    }

    /**
     * Writes down that one tab's countdown for one card has burned down.
     *
     * It removes nothing by itself: the card may be under a cursor in the next window, and the
     * whole point of the leaf is that nothing vanishes from under a reading cursor. The
     * decision is {@see self::settle()}, which the caller runs straight after.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $key Name of the card whose countdown finished
     * @param string $acceptKey Accept key of the tab reporting it
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function markExpired(string $sessionTokenHash, string $key, string $acceptKey): void
    {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($sessionTokenHash);
        if ($state === null) {
            return;
        }

        $toasts = $state->toasts;
        foreach ($toasts as $index => $toast) {
            if ($toast[StateHilosSessionToastStack::TOAST_KEY] !== $key) {
                continue;
            }
            if (in_array($acceptKey, $toast[StateHilosSessionToastStack::TOAST_EXPIRED_BY], true)) {
                return;
            }

            $toasts[$index][StateHilosSessionToastStack::TOAST_EXPIRED_BY][] = $acceptKey;
            $this->applyDiffToState($state, [StateHilosSessionToastStack::toasts => $toasts]);

            return;
        }
    }

    /**
     * Writes down whether one tab is reading the stack right now.
     *
     * Reading is a cursor over the stack or the keyboard focus inside it, and nothing else - a
     * hidden tab freezes its countdown but does not hold the stack, because a background tab of
     * the admin panel would otherwise make every toast immortal in the window being used.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $acceptKey Accept key of the tab that started or stopped reading
     * @param bool $reading Whether that tab is reading the stack now
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function setReading(string $sessionTokenHash, string $acceptKey, bool $reading): void
    {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($sessionTokenHash);
        if ($state === null) {
            return;
        }

        $readingBy = $state->readingBy;
        $held = in_array($acceptKey, $readingBy, true);
        if ($held === $reading) {
            return;
        }

        if ($reading) {
            $readingBy[] = $acceptKey;
        } else {
            $readingBy = array_values(array_filter(
                $readingBy,
                static fn(string $holder): bool => $holder !== $acceptKey,
            ));
        }

        $this->applyDiffToState($state, [StateHilosSessionToastStack::readingBy => $readingBy]);
    }

    /**
     * Runs the removal rule over one session's stack.
     *
     * A card goes away when a LIVE socket has reported its countdown finished and no LIVE
     * socket is reading. Both halves are intersected with the sockets that are actually there,
     * which is why nothing has to be cleaned up after a tab that closed: its report stops
     * counting for the living, and its hold stops vetoing, in the same breath.
     *
     * A session with no live socket at all loses its row outright. A toast lives only while
     * somebody may be looking at it, and there is nobody left to look - the next tab to open
     * is a person arriving after the fact, not the person who was told.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param list<string> $liveAcceptKeys Accept keys of the session's live connections
     * @return bool Whether the session's list of cards changed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    public function settle(string $sessionTokenHash, array $liveAcceptKeys): bool
    {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($sessionTokenHash);
        if ($state === null) {
            return false;
        }

        if ($liveAcceptKeys === []) {
            $this->removeStateFromCollection($sessionTokenHash);

            return true;
        }

        if (array_intersect($state->readingBy, $liveAcceptKeys) !== []) {
            return false;
        }

        $kept = [];
        foreach ($state->toasts as $toast) {
            $expiredByLive = array_intersect(
                $toast[StateHilosSessionToastStack::TOAST_EXPIRED_BY],
                $liveAcceptKeys,
            );
            if ($expiredByLive === []) {
                $kept[] = $toast;
            }
        }
        if (count($kept) === count($state->toasts)) {
            return false;
        }

        $this->writeRemaining($state, $kept);

        return true;
    }

    /**
     * Stores what is left of a stack, or takes the row away when nothing is.
     *
     * The one place an empty list is turned into an absent row, so the collection is the size
     * of the toasts on screen rather than of the sessions that have ever been shown one.
     *
     * @param StateHilosSessionToastStack $state Row being written
     * @param list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int, expiredBy: list<string>}> $kept Cards that survived
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the row's read of the written fields raises
     */
    private function writeRemaining(StateHilosSessionToastStack $state, array $kept): void
    {
        if ($kept === []) {
            $this->removeStateFromCollection($state->getId());

            return;
        }

        $this->applyDiffToState($state, [StateHilosSessionToastStack::toasts => $kept]);
    }
}
