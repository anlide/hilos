<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\CliCommands;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\AuthAttempts as StateAuthAttempts;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Actions\Item\AuthAttemptActions;
use Hilos\Runtime\View\Collection\AuthAttempts;
use Hilos\Runtime\View\Item\AuthAttempt as ViewAuthAttempt;

/**
 * Write API for the throttle window counters as a whole (HIL-420).
 *
 * Two writes belong to the collection rather than to a row: bringing a key's counter into
 * existence ({@see open()}), which is what the first attempt from an address does, and the
 * sweep that retires the rows nothing is waiting on ({@see sweep()}). Everything that
 * changes a counter already in existence is a write on that one row, through
 * {@see AuthAttemptActions}.
 *
 * @extends RtActions<ViewAuthAttempt, AuthAttempts, StateAuthAttempts>
 * @property-read StateAuthAttempts $stateCollection
 */
final class AuthAttemptsActions extends RtActions
{
    /**
     * Ensures a counter row exists for a throttle key, leaving an existing one untouched.
     *
     * Idempotent by construction: the caller asks for the key it is about to count against
     * and then works through the row's own actions, so a second attempt from the same
     * address must not reset what the first one recorded.
     *
     * @param string $scope Throttle scope, one of {@see ThrottleScope}
     * @param string $identity Client IP or sha256 of the session token
     * @param string $action Throttled action name
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function open(string $scope, string $identity, string $action): void
    {
        $this->ensureCanWrite();

        if ($this->stateCollection->get(StateAuthAttempt::keyFor($scope, $identity, $action)) !== null) {
            return;
        }

        $this->addStateToCollection(StateAuthAttempt::create($scope, $identity, $action));
    }

    /**
     * Retires every counter that has gone quiet, reporting how many rows went.
     *
     * A row is retired only when all three of its timers have run out: its window has
     * closed, its block has been served, and its ladder has cooled. Dropping it any earlier
     * would hand a client its ladder back for free, which is exactly the escalation the
     * levels exist to prevent.
     *
     * @param float $now Current unix seconds
     * @param float $windowSeconds Length of the fixed counting window
     * @param float $cooldownSeconds Idle time after which a ladder level cools back to zero
     * @return int Number of counters retired
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a retired row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function sweep(float $now, float $windowSeconds, float $cooldownSeconds): int
    {
        $this->ensureCanWrite();

        // Collected before removing: dropping a row mutates the collection being walked.
        $stale = [];
        foreach ($this->stateCollection as $state) {
            if ($this->isStale($state, $now, $windowSeconds, $cooldownSeconds)) {
                $stale[] = $state->getId();
            }
        }

        return $this->forgetAll($stale);
    }

    /**
     * Forgives one session everything, on every action, reporting how many rows went.
     *
     * What a successful authentication buys the person who made it: the attempts that led up
     * to it were the suspicious sequence, and it has just been settled, so nothing counted
     * against that session survives it - including a block it collected on some other action
     * while proving itself on this one. The address stays untouched by construction, since
     * only {@see ThrottleScope::SESSION} rows are looked at.
     *
     * @param string $identity Digest of the session token that authenticated
     * @return int Number of counters dropped
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a dropped row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function forgetSession(string $identity): int
    {
        $this->ensureCanWrite();

        // Collected before removing: dropping a row mutates the collection being walked.
        $ids = [];
        foreach ($this->stateCollection as $state) {
            if ($state->scope === ThrottleScope::SESSION && $state->identity === $identity) {
                $ids[] = $state->getId();
            }
        }

        return $this->forgetAll($ids);
    }

    /**
     * Drops every counter, whatever state it is in, reporting how many rows went.
     *
     * The runtime half of the test-only reset ({@see CliCommands::THROTTLE_TEST_RESET}): a
     * test that just drove a key into a block has to hand the next one a clean slate. It
     * ignores all three timers on purpose, which is exactly what separates it from
     * {@see sweep()} - forgetting a block that has NOT lifted is the whole point here and
     * the one thing the sweep must never do.
     *
     * @return int Number of counters dropped
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a dropped row is not attached to the collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function clear(): int
    {
        $this->ensureCanWrite();

        // Collected before removing: dropping a row mutates the collection being walked.
        $ids = [];
        foreach ($this->stateCollection as $state) {
            $ids[] = $state->getId();
        }

        return $this->forgetAll($ids);
    }

    /**
     * Drops the named counters and reports how many were still there to drop.
     *
     * @param list<string> $ids Ids of the counters to drop
     * @return int Number of counters dropped
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a dropped row is not attached to the collection
     */
    private function forgetAll(array $ids): int
    {
        $dropped = 0;
        foreach ($ids as $id) {
            $state = $this->stateCollection->get($id);
            if ($state !== null) {
                $this->createRtItemFromState($state)->actions->forget();
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * Tells whether a counter has nothing left to remember.
     *
     * @param StateAuthAttempt $state Counter to judge
     * @param float $now Current unix seconds
     * @param float $windowSeconds Length of the fixed counting window
     * @param float $cooldownSeconds Idle time after which a ladder level cools back to zero
     * @return bool True when the window closed, the block lifted and the level cooled
     */
    private function isStale(StateAuthAttempt $state, float $now, float $windowSeconds, float $cooldownSeconds): bool
    {
        if ($state->blockedUntil !== null && $state->blockedUntil > $now) {
            return false;
        }

        $idleFor = $now - $state->windowStartedAt;
        if ($state->level > 0 && $idleFor < $cooldownSeconds) {
            return false;
        }

        return $idleFor >= $windowSeconds;
    }
}
