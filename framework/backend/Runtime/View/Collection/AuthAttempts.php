<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\AuthAttempts as StateAuthAttempts;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\AuthAttemptsActions;
use Hilos\Runtime\View\Item\AuthAttempt;

/**
 * Read-only wrapper around the throttle window counters, keyed by `scope|identity|action` (HIL-420).
 *
 * Framework-owned on both halves: the throttle agent is the only truth source, and every
 * other worker holds a replica it reads and never writes. Rows are addressed by the key
 * {@see StateAuthAttempt::keyFor()} composes, so the guard reaching for a key it has never
 * seen gets null rather than an exception - an unknown key simply has no history yet.
 *
 * @extends RtCollection<AuthAttempt, AuthAttemptsActions>
 * @property-read AuthAttemptsActions $actions Actions for write operations
 */
final class AuthAttempts extends RtCollection
{
    /**
     * @return StateAuthAttempts Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateAuthAttempts
    {
        /** @var StateAuthAttempts */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateAuthAttempt instance
     * @return AuthAttempt View item for this counter
     */
    protected function createRtItem(RtState &$state): AuthAttempt
    {
        /** @var StateAuthAttempt $state */
        return new AuthAttempt($state);
    }

    /**
     * @param mixed $offset Throttle key `scope|identity|action`
     * @return ?AuthAttempt Item or null when the key has no counter yet
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?AuthAttempt
    {
        /** @var ?AuthAttempt $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return AuthAttemptsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): AuthAttemptsActions
    {
        /** @var AuthAttemptsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): AuthAttemptsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}
