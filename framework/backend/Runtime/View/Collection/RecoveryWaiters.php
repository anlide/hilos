<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\RecoveryWaiters as StateRecoveryWaiters;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RecoveryWaitersActions;
use Hilos\Runtime\View\Item\RecoveryWaiter;

/**
 * Read-only wrapper around the sessions parked on a password-recovery code step (HIL-416).
 *
 * Framework-owned on both halves; a project registers it and the agent owning the
 * sign-in surface writes it. Its two reads are the two axes of the flow: the address
 * a finished reset settles ({@see forIdentifier()}) and the session a proven code
 * grants ({@see forSessionToken()}).
 *
 * @extends RtCollection<RecoveryWaiter, RecoveryWaitersActions>
 * @property-read RecoveryWaitersActions $actions Actions for write operations
 */
final class RecoveryWaiters extends RtCollection
{
    /**
     * Every session parked on one identifier.
     *
     * The whole recipient list of a converge broadcast: whoever asked to reset this
     * address is waiting on the same one code, and they all move together when
     * somebody saves a new password with it.
     *
     * @param string $identifier Normalized identifier (lowercased email)
     * @return list<RecoveryWaiter> Waiting sessions (empty when nobody waits)
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
     * Every parked connection of one session.
     *
     * What session-binding is made of: the tabs of the session that proved the code
     * are exactly the rows this answers with, so the grant reaches all of them and
     * stops there. It is also how the saving step finds the address it was never
     * sent - the person on the password screen types a password and nothing else.
     *
     * @param string $sessionToken Session token whose parked connections are wanted
     * @return list<RecoveryWaiter> Parked connections of the session (empty when it parked none)
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function forSessionToken(string $sessionToken): array
    {
        $waiters = [];
        foreach ($this->getStateCollection()->findAllBySessionToken($sessionToken) as $state) {
            $waiter = $this->offsetGet($state->acceptKey);
            if ($waiter !== null) {
                $waiters[] = $waiter;
            }
        }

        return $waiters;
    }

    /**
     * @return StateRecoveryWaiters Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateRecoveryWaiters
    {
        /** @var StateRecoveryWaiters */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateRecoveryWaiter instance
     * @return RecoveryWaiter View item for this waiter
     */
    protected function createRtItem(RtState &$state): RecoveryWaiter
    {
        /** @var StateRecoveryWaiter $state */
        return new RecoveryWaiter($state);
    }

    /**
     * @param mixed $offset Waiting connection accept key
     * @return ?RecoveryWaiter Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?RecoveryWaiter
    {
        /** @var ?RecoveryWaiter $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return RecoveryWaitersActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): RecoveryWaitersActions
    {
        /** @var RecoveryWaitersActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    public function __get(string $name): RecoveryWaitersActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
