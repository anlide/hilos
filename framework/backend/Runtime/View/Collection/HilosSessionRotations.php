<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Constants\TimeConstants;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\HilosSessionRotations as StateHilosSessionRotations;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionRotationsActions;
use Hilos\Runtime\View\Item\HilosSessionRotation;
use Hilos\Utils\Logger;

/**
 * Read-only wrapper around the pending token rotations (HIL-582).
 *
 * Framework-owned on both halves, mounted for every project. Its one read is the one the
 * master performs on a handshake carrying a rotation ticket ({@see claimable()}); its
 * writes belong to the agent that owns the session seam.
 *
 * @extends RtCollection<HilosSessionRotation, HilosSessionRotationsActions>
 * @property-read HilosSessionRotationsActions $actions Actions for write operations
 */
final class HilosSessionRotations extends RtCollection
{
    /**
     * Returns the rotation a presented ticket may still be traded for.
     *
     * The master's whole question on the 101, asked in one call so the two halves of the
     * answer cannot come apart: a ticket nobody minted and a ticket whose moment has passed
     * are the same answer - null - and both mean "serve this handshake by the ordinary
     * cookie rule". Expiry is judged here rather than left to the caller because a row that
     * outlived its ticket must not be honoured anywhere, and there is only one clock.
     *
     * A row whose source node is unreachable is refused too, and this is the one reader in the
     * framework that refuses on the mark alone (HIL-711). The danger is not a ticket that went
     * missing — its absence has always meant "log in again" — but one that a frozen copy still
     * shows as unspent: the burn is announced by whichever master accepted the handshake, and in
     * a break the other node does not hear it. A ticket good for a second handshake is worse
     * than a login, so this decision fails closed while every other reader of a frozen row goes
     * on being served.
     *
     * @param string $ticket Ticket value presented on the handshake
     * @return ?HilosSessionRotation Live rotation, or null when the ticket is unknown, spent, or frozen
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function claimable(string $ticket): ?HilosSessionRotation
    {
        $state = $this->getStateCollection()->get($ticket);
        if ($state === null || !$state->isLiveAt(microtime(true) * TimeConstants::MS_PER_SECOND)) {
            return null;
        }

        $frozenSince = RtStaleness::staleSince(StateHilosSessionRotation::RT_COLLECTION, $ticket);
        if ($frozenSince !== null) {
            Logger::warning(
                "Session rotation ticket refused: its replica froze at {$frozenSince},"
                . ' the owner node is unreachable',
            );

            return null;
        }

        return $this->offsetGet($ticket);
    }

    /**
     * @return StateHilosSessionRotations Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosSessionRotations
    {
        /** @var StateHilosSessionRotations */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosSessionRotation instance
     * @return HilosSessionRotation View item for this rotation
     */
    protected function createRtItem(RtState $state): HilosSessionRotation
    {
        /** @var StateHilosSessionRotation $state */
        return new HilosSessionRotation($state);
    }

    /**
     * @param mixed $offset One-time rotation ticket
     * @return ?HilosSessionRotation Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosSessionRotation
    {
        /** @var ?HilosSessionRotation $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosSessionRotationsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosSessionRotationsActions
    {
        /** @var HilosSessionRotationsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosSessionRotationsActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosSessionRotationsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
