<?php

namespace Demo\Chat\Database;

use Demo\Chat\Database\Entity\Bot as EntityBot;
use Demo\Chat\Database\Entity\Event as EntityEvent;
use Demo\Chat\Database\Entity\Moderator as EntityModerator;
use Demo\Chat\Database\Entity\User as EntityUser;
use Demo\Chat\Hilos\Database\DbActions\EventsActions;
use Demo\Chat\Hilos\Database\DbActions\UsersActions;
use Demo\Chat\Hilos\Database\DbCollection\Bots;
use Demo\Chat\Hilos\Database\DbCollection\Events;
use Demo\Chat\Hilos\Database\DbCollection\Moderators;
use Demo\Chat\Hilos\Database\DbCollection\Users;
use Demo\Chat\Database\ObjectCollection\Bots as ObjectBots;
use Demo\Chat\Database\ObjectCollection\Events as ObjectEvents;
use Demo\Chat\Database\ObjectCollection\Moderators as ObjectModerators;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Demo\Chat\Runtime\ChatRuntime;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Hilos\Database\Hilos as BaseHilos;
use Hilos\Hilos\Runtime\RtContext;
use Hilos\Hilos\Table\TableHub;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\ObjectCollectionNotFoundException;
use Hilos\Exception\Hilos\Runtime\Rt\StateCollectionNotFoundException;
/**
 * DbChat - App-specific database context.
 *
 * @extends BaseHilos<ChatRuntime>
 *
 * @property-read Users $users
 * @property-read Events $events
 * @property-read Bots $bots
 * @property-read Moderators $moderators
 */
class DbChat extends BaseHilos
{
    public const string users = 'users';
    public const string events = 'events';
    public const string bots = 'bots';
    public const string moderators = 'moderators';

    /**
     * @throws DatabaseException
     * @throws ObjectCollectionNotFoundException
     */
    public static function init(): void
    {
        parent::init();
    }

    /**
     * @throws ObjectCollectionNotFoundException
     */
    protected static function configureCollections(): void
    {
        self::$db->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        self::$db->_objectCollections[self::events] = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);
        self::$db->_objectCollections[self::bots] = ObjectBots::initDB(Objects::LAZY_STRATEGY_KEY);
        self::$db->_objectCollections[self::moderators] = ObjectModerators::initDB(Objects::LAZY_STRATEGY_KEY);

        self::$db->setRepresent(self::users, Users::class, UsersActions::class);
        self::$db->setRepresent(self::events, Events::class, EventsActions::class);
        self::$db->setRepresent(self::bots, Bots::class);
        self::$db->setRepresent(self::moderators, Moderators::class);
    }

    /**
     * @return array<string, string>
     */
    protected static function getEntityMapping(): array
    {
        return [
            self::users => EntityUser::class,
            self::events => EntityEvent::class,
            self::bots => EntityBot::class,
            self::moderators => EntityModerator::class,
        ];
    }

    /**
     * @return ?RtContext
     * @throws StateCollectionNotFoundException
     */
    protected static function createRuntime(): ?RtContext
    {
        return ChatRuntime::init();
    }

    protected static function createTable(): ?TableHub
    {
        $table = new TableHub();
        $table->register(self::users, new EntityTableDataSource(self::$db->users));
        return $table;
    }
}
