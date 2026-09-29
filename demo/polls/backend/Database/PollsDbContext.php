<?php

declare(strict_types=1);

namespace Demo\Polls\Database;

use Demo\Polls\Database\Actions\Collection\GuestsActions;
use Demo\Polls\Database\Object\Collection\Guests as ObjectGuests;
use Demo\Polls\Database\View\Collection\Guests;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * PollsDbContext - Database context for the polls demo.
 *
 * Inherits the framework collections, including people and their rename journal, and adds
 * guest names for sessions without an account.
 *
 * @property-read Guests $guests
 */
final class PollsDbContext extends HilosDbContext
{
    public const string guests = 'guests';

    /**
     * Configures the database context with the guest object collection and its view
     * representation.
     *
     * @throws FrameworkExtensionException When a framework key is extended by a chain that does not extend it
     * @throws CollectionAlreadyMountedException When a key is represented twice
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     * @throws UnknownLazyStrategyException When a collection is mounted under a strategy initDB() does not know
     */
    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::guests] = ObjectGuests::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::guests, Guests::class, GuestsActions::class);
    }
}
