<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\RegistrationWaiters as StateRegistrationWaiters;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RegistrationWaitersActions;
use Hilos\Runtime\View\Item\RegistrationWaiter;

/**
 * Read-only wrapper around the sessions parked on a registration code step (HIL-415).
 *
 * Framework-owned on both halves; a project registers it and the agent owning the
 * sign-in surface writes it. Its one read is the converge broadcast's addressing
 * question ({@see forIdentifier()}).
 *
 * @extends RtCollection<RegistrationWaiter, RegistrationWaitersActions>
 * @property-read RegistrationWaitersActions $actions Actions for write operations
 */
final class RegistrationWaiters extends RtCollection
{
    /**
     * Every session parked on one identifier.
     *
     * The whole recipient list of a converge broadcast: whoever submitted this
     * address is waiting on the same one code, and they all move together when it
     * is confirmed or when the hold behind it expires.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<RegistrationWaiter> Waiting sessions (empty when nobody waits)
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function forIdentifier(string $identifier): array
    {
        $waiters = [];
        foreach ($this->getStateCollection()->findAllByIdentifier($identifier) as $state) {
            $waiter = $this->offsetGet($state->acceptKey);
            if ($waiter !== null) {
                $waiters[] = $waiter;
            }
        }

        return $waiters;
    }

    /**
     * @return StateRegistrationWaiters Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateRegistrationWaiters
    {
        /** @var StateRegistrationWaiters */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateRegistrationWaiter instance
     * @return RegistrationWaiter View item for this waiter
     */
    protected function createRtItem(RtState &$state): RegistrationWaiter
    {
        /** @var StateRegistrationWaiter $state */
        return new RegistrationWaiter($state);
    }

    /**
     * @param mixed $offset Waiting connection accept key
     * @return ?RegistrationWaiter Item or null
     */
    public function offsetGet(mixed $offset): ?RegistrationWaiter
    {
        /** @var ?RegistrationWaiter $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return RegistrationWaitersActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): RegistrationWaitersActions
    {
        /** @var RegistrationWaitersActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    public function __get(string $name): RegistrationWaitersActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
