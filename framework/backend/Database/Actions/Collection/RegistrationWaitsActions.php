<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\RegistrationWaits as ObjectRegistrationWaits;
use Hilos\Database\View\Collection\RegistrationWaits as DbCollectionRegistrationWaits;
use Hilos\Database\View\Item\RegistrationWait;

/**
 * RegistrationWaitsActions - write operations for the RegistrationWaits collection.
 *
 * The three moments of an unfinished registration as the SERVER sees it (HIL-486):
 * a code went out and a session is now waiting, that session is done waiting (its
 * code came back, or the person said it was the wrong address), or the address is
 * done being waited on (the registration completed, or its hold ran out and nobody
 * can confirm it any more).
 *
 * Every write is per session or per identifier and never per row id, because the
 * callers know a person and an address, not a row: the wait is memory about them.
 *
 * @extends DbActions<RegistrationWait, ObjectRegistrationWaits>
 * @property-read DbCollectionRegistrationWaits $collection
 * @property-read ObjectRegistrationWaits $objectCollection
 */
final class RegistrationWaitsActions extends DbActions
{
    /**
     * Remembers that a session is waiting on an identifier's code.
     *
     * @param string $sessionToken Session cookie token that is waiting
     * @param string $identifier Normalized identifier the session is waiting on
     * @throws EmptyValueException When the session token or the identifier is empty
     * @throws DatabaseException When the lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function hold(string $sessionToken, string $identifier): void
    {
        $this->objectCollection->hold($sessionToken, $identifier);
    }

    /**
     * Forgets what one session was waiting on.
     *
     * @param string $sessionToken Session cookie token
     * @throws DatabaseException When the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function releaseBySession(string $sessionToken): void
    {
        $this->objectCollection->releaseBySession($sessionToken);
    }

    /**
     * Forgets every session that was waiting on one identifier.
     *
     * @param string $identifier Normalized identifier
     * @throws DatabaseException When the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function releaseByIdentifier(string $identifier): void
    {
        $this->objectCollection->releaseByIdentifier($identifier);
    }
}
