<?php

declare(strict_types=1);

namespace Demo\Tasks\Database;

use Demo\Tasks\Database\Actions\Collection\GuestsActions;
use Demo\Tasks\Database\Actions\Collection\UserRenamesActions;
use Demo\Tasks\Database\Object\Collection\Guests as ObjectGuests;
use Demo\Tasks\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Tasks\Database\View\Collection\Guests;
use Demo\Tasks\Database\View\Collection\UserRenames;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * TasksDbContext - Database context for the tasks demo.
 *
 * Inherits the framework collections, including people, and adds the standalone
 * user-rename audit collection and guest names for sessions without an account.
 *
 * @property-read UserRenames $userRenames
 * @property-read Guests $guests
 */
final class TasksDbContext extends HilosDbContext
{
    public const string userRenames = 'userRenames';
    public const string guests = 'guests';

    /**
     * Configures the database context with the user-rename and guest object
     * collections and their view representations.
     *
     * @throws FrameworkExtensionException When a framework key is extended by a chain that does not extend it
     * @throws CollectionAlreadyMountedException When a key is represented twice
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     * @throws UnknownLazyStrategyException When a collection is mounted under a strategy initDB() does not know
     */
    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::userRenames] = ObjectUserRenames::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::guests] = ObjectGuests::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::userRenames, UserRenames::class, UserRenamesActions::class);
        $this->setRepresent(self::guests, Guests::class, GuestsActions::class);
    }
}
