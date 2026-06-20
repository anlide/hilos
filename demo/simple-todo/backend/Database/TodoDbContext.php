<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database;

use Demo\SimpleTodo\Database\Actions\Collection\EventUserRenamesActions;
use Demo\SimpleTodo\Database\Actions\Collection\UsersActions;
use Demo\SimpleTodo\Database\Actions\Item\UserActions;
use Demo\SimpleTodo\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\SimpleTodo\Database\Object\Collection\Users as ObjectUsers;
use Demo\SimpleTodo\Database\View\Collection\EventUserRenames;
use Demo\SimpleTodo\Database\View\Collection\Users;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * TodoDbContext - Database context for the simple-todo demo.
 *
 * Inherits the Hilos-level settings collection from HilosDbContext and adds the
 * durable user collection that backs session identity plus the standalone
 * user-rename audit collection.
 *
 * @property-read Users $users
 * @property-read EventUserRenames $eventUserRenames
 */
final class TodoDbContext extends HilosDbContext
{
    public const string users = 'users';
    public const string eventUserRenames = 'eventUserRenames';

    /**
     * Configures the database context with the user object collection and view representation.
     *
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     */
    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::eventUserRenames] = ObjectEventUserRenames::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::users, Users::class, UsersActions::class, UserActions::class);
        $this->setRepresent(self::eventUserRenames, EventUserRenames::class, EventUserRenamesActions::class);
    }
}
