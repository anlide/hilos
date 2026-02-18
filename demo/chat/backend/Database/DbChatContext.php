<?php

namespace Demo\Chat\Database;

use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Collection\Moderators as ObjectModerators;
use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Hilos\Database\Actions\EventsActions;
use Demo\Chat\Hilos\Database\Actions\UsersActions;
use Demo\Chat\Hilos\Database\Collection\Bots;
use Demo\Chat\Hilos\Database\Collection\Events;
use Demo\Chat\Hilos\Database\Collection\Moderators;
use Demo\Chat\Hilos\Database\Collection\Users;
use Hilos\Database\DatabaseException;
use Hilos\Database\Hilos\DbContext;
use Hilos\Database\Object\Objects;
use Hilos\Hilos\Database\Exception\ObjectCollectionNotFoundException;

/**
 * DbChatContext - App-specific database context ($db layer).
 *
 * @property-read Users $users
 * @property-read Events $events
 * @property-read Bots $bots
 * @property-read Moderators $moderators
 */
class DbChatContext extends DbContext
{
    public const string users = 'users';
    public const string events = 'events';
    public const string bots = 'bots';
    public const string moderators = 'moderators';

    public const string user = 'user';
    public const string event = 'event';
    public const string bot = 'bot';
    public const string moderator = 'moderator';

    /**
     * @throws ObjectCollectionNotFoundException
     * @throws DatabaseException
     */
    public function configure(): void
    {
        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::events] = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->_objectCollections[self::bots] = ObjectBots::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::moderators] = ObjectModerators::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::users, Users::class, UsersActions::class);
        $this->setRepresent(self::events, Events::class, EventsActions::class);
        $this->setRepresent(self::bots, Bots::class);
        $this->setRepresent(self::moderators, Moderators::class);
    }
}
