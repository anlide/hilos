<?php

declare(strict_types=1);

namespace Demo\Tasks\Database;

use Demo\Tasks\Browser\TasksBrowserContext;
use Demo\Tasks\Database\Actions\Collection\GuestsActions;
use Demo\Tasks\Database\Actions\Collection\UserRenamesActions;
use Demo\Tasks\Database\Actions\Collection\UsersActions;
use Demo\Tasks\Database\Actions\Item\UserActions;
use Demo\Tasks\Database\Object\Collection\Guests as ObjectGuests;
use Demo\Tasks\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Tasks\Database\Object\Collection\Users as ObjectUsers;
use Demo\Tasks\Database\View\Collection\Guests;
use Demo\Tasks\Database\View\Collection\UserRenames;
use Demo\Tasks\Database\View\Collection\Users;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * TasksDbContext - Database context for the tasks demo.
 *
 * Inherits the Hilos-level settings collection from HilosDbContext and adds the
 * durable user collection that backs account identity, the standalone user-rename
 * audit collection, and the guest names of sessions that have no account (HIL-610).
 *
 * @property-read Users $users
 * @property-read UserRenames $userRenames
 * @property-read Guests $guests
 */
final class TasksDbContext extends HilosDbContext
{
    public const string users = 'users';
    public const string userRenames = 'userRenames';
    public const string guests = 'guests';

    /**
     * Configures the database context with the user, user-rename and guest object
     * collections and their view representations.
     *
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     */
    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::userRenames] = ObjectUserRenames::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::guests] = ObjectGuests::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::users, Users::class, UsersActions::class, UserActions::class);
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
