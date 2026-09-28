<?php

declare(strict_types=1);

namespace Demo\Tasks\Database;

use Demo\Tasks\Browser\TasksBrowserContext;
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

    /**
     * Names what this demo reads from any process at all: the user rows the admin gate is
     * decided against (HIL-750).
     *
     * {@see TasksBrowserContext::isAdmin()} answers the ADMIN level for every gated page, and it
     * runs in whatever worker serves that page - including a page that declares nothing of its
     * own, like the framework dashboard. So the read is behind no subscription and no agent, and
     * neither the topology nor a READS_DB can reach it.
     *
     * Its refusal would not even look like one: the gate reads defensively and turns any failure
     * into a denial, so an undeclared collection here shows up as a person who is an
     * administrator being told the admin surface is forbidden.
     *
     * @return list<string> Collection keys read process-wide
     */
    protected function processWideReadCollections(): array
    {
        return [...parent::processWideReadCollections(), self::users];
    }
}
