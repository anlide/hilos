<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Item\AuthAttempt as ViewAuthAttempt;

/**
 * Write operations for the window counter of one throttle key (HIL-420).
 *
 * Only the throttle agent reaches these: it is the collection's single truth source, and
 * every write here goes out as an RT sync signal so the guard in every other worker sees
 * the same counter and the same block. A worker that mutated its own replica instead would
 * be counting one connection's attempts against a number nobody else can see - and
 * connections of one IP land on different workers, which is the whole reason the counter
 * is shared state rather than agent-local.
 *
 * @extends RtActions<ViewAuthAttempt, StateAuthAttempt>
 * @property-read StateAuthAttempt $state
 */
final class AuthAttemptActions extends RtActions
{
    /**
     * Counts one attempt against this key, opening a fresh window when the last one elapsed.
     *
     * The fixed-window rule: attempts landing inside the window that opened at
     * {@see StateAuthAttempt::$windowStartedAt} accumulate, and the first attempt after it
     * elapsed starts a new window at 1. The escalation level is deliberately NOT reset here -
     * it cools on its own much slower timer, so a client cannot walk its ladder back down by
     * waiting out one window.
     *
     * @param float $now Current unix seconds
     * @param float $windowSeconds Length of the fixed counting window
     * @return int Attempts counted in the window this attempt belongs to
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function countAttempt(float $now, float $windowSeconds): int
    {
        $this->ensureCanWrite();

        $windowStartedAt = $this->state->windowStartedAt;
        $inWindow = $windowStartedAt > 0.0 && $now - $windowStartedAt < $windowSeconds;
        $count = $inWindow ? $this->state->count + 1 : 1;

        $this->applyDiffWithSync([
            StateAuthAttempt::count => $count,
            StateAuthAttempt::windowStartedAt => $inWindow ? $windowStartedAt : $now,
        ]);

        return $count;
    }

    /**
     * Records a consummated block: the ladder step reached and the moment it lifts.
     *
     * Used both when the agent escalates a key that just breached its window and when it
     * reloads the durable blocks on start, because those are the same fact arriving from
     * two directions. A null moment retains the level without blocking - the shape a key
     * takes once its block has been served but its ladder has not yet cooled.
     *
     * The moment of the decision is stamped as the window start, which is also what the
     * sweep measures a level's cooling from. Without it a block replayed on start would
     * carry a window start of zero - idle since the epoch - and the row would be retired the
     * first time the sweep ran after the block lifted, taking the ladder with it. Restarting
     * the daemon would then be a way of handing a repeat offender level one again.
     *
     * @param int $level Ladder step reached
     * @param ?float $blockedUntil Unix seconds the block lifts, or null to retain the level only
     * @param float $decidedAt Unix seconds the block was decided or replayed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function block(int $level, ?float $blockedUntil, float $decidedAt): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateAuthAttempt::level => $level,
            StateAuthAttempt::blockedUntil => $blockedUntil,
            StateAuthAttempt::windowStartedAt => $decidedAt,
        ]);
    }

    /**
     * Clears the counter, the ladder and the block for this key.
     *
     * A successful authentication on a `session` key: the person proved who they are, so
     * nothing they did while proving it is held against them. An `ip` key is never cleared
     * this way - one legitimate sign-in from behind a NAT must not lift the pressure the
     * other clients on that address built up.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function reset(): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateAuthAttempt::count => 0,
            StateAuthAttempt::windowStartedAt => 0.0,
            StateAuthAttempt::level => 0,
            StateAuthAttempt::blockedUntil => null,
        ]);
    }

    /**
     * Drops the counter row entirely and broadcasts the removal.
     *
     * The sweep's half: a key whose window closed, whose ladder cooled and whose block has
     * been served carries nothing worth syncing, and keeping it would grow the collection by
     * one row per address ever seen.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function forget(): void
    {
        $this->remove();
    }
}
