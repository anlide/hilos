<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\View\Item\HilosConnection;

/**
 * Write operations for a single connection (HIL-509).
 *
 * The two writes a live socket takes: it closes, and the user behind it changes.
 * Both are on the presence stage, because the session token is written when the
 * row is created and never after — so there is no session-stage twin of this
 * class. A project subclasses this and adds its own per-socket writes.
 *
 * @template TItem of HilosConnection
 * @extends RtActions<TItem, StateHilosConnection>
 * @property-read StateHilosConnection $state
 */
abstract class HilosConnectionActions extends RtActions
{
    /**
     * Removes this connection from the runtime collection on socket close.
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When this connection is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function unregister(): void
    {
        $this->remove();
    }

    /**
     * Re-points this connection to an authenticated user, or back to anonymous.
     *
     * The RT side of the session upgrade/downgrade seam: authenticating a session
     * binds its live connections to the logged-in user id, logout reverts them to
     * null.
     *
     * @param ?int $userId Authenticated user id, or null for anonymous
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     */
    public function bindUser(?int $userId): void
    {
        $this->ensureCanWrite();

        $this->state->userId = $userId;

        $this->sync();
    }
}
