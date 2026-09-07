<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosCodeSendAttempts as StateHilosCodeSendAttempts;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosCodeSendAttemptsActions;
use Hilos\Runtime\View\Item\HilosCodeSendAttempt;

/**
 * Read-only wrapper around the code sends being watched (HIL-826).
 *
 * Framework-owned on both halves and mounted by the sign-in feature. It is read in one place
 * only, inside the agent that owns it: to put a session's line on the wire, on every step it
 * accepts and on every handshake. Its writes belong to the agent that owns the session seam;
 * the transports carrying the code report to it by frame and never touch the collection.
 *
 * @extends RtCollection<HilosCodeSendAttempt, HilosCodeSendAttemptsActions>
 * @property-read HilosCodeSendAttemptsActions $actions Actions for write operations
 */
final class HilosCodeSendAttempts extends RtCollection
{
    /**
     * @return StateHilosCodeSendAttempts Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosCodeSendAttempts
    {
        /** @var StateHilosCodeSendAttempts */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosCodeSendAttempt instance
     * @return HilosCodeSendAttempt View item for this attempt
     */
    protected function createRtItem(RtState $state): HilosCodeSendAttempt
    {
        /** @var StateHilosCodeSendAttempt $state */
        return new HilosCodeSendAttempt($state);
    }

    /**
     * @param mixed $offset Hash of a session cookie token
     * @return ?HilosCodeSendAttempt Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosCodeSendAttempt
    {
        /** @var ?HilosCodeSendAttempt $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosCodeSendAttemptsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosCodeSendAttemptsActions
    {
        /** @var HilosCodeSendAttemptsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosCodeSendAttemptsActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosCodeSendAttemptsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
