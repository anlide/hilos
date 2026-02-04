<?php

namespace Demo\WebSocketTest\Database;

use Demo\WebSocketTest\Database\Entity\Bot as EntityBot;
use Demo\WebSocketTest\Database\Entity\Event as EntityEvent;
use Demo\WebSocketTest\Database\Entity\Moderator as EntityModerator;
use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Demo\WebSocketTest\Database\IdeaActions\EventsActions;
use Demo\WebSocketTest\Database\IdeaActions\UsersActions;
use Demo\WebSocketTest\Database\IdeaCollection\Bots as IdeaBots;
use Demo\WebSocketTest\Database\IdeaCollection\Events as IdeaEvents;
use Demo\WebSocketTest\Database\IdeaCollection\Moderators as IdeaModerators;
use Demo\WebSocketTest\Database\IdeaCollection\Users as IdeaUsers;
use Demo\WebSocketTest\Database\ObjectCollection\Bots as ObjectBots;
use Demo\WebSocketTest\Database\ObjectCollection\Events as ObjectEvents;
use Demo\WebSocketTest\Database\ObjectCollection\Moderators as ObjectModerators;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Demo\WebSocketTest\Runtime\ChatRuntime;
use Hilos\Database\Idea\Idea as BaseIdea;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Other\IdeaObjectCollectionNotFoundException;
use Hilos\Runtime\Idea\IdeaRt;

/**
 * Idea - Application-specific data access point
 *
 * Extends framework Idea class to provide:
 *   - $db: Database collections (users, events, bots, moderators)
 *   - $rt: Runtime data (connections)
 *
 * @property-read IdeaUsers $users
 * @property-read IdeaEvents $events
 * @property-read IdeaBots $bots
 * @property-read IdeaModerators $moderators
 */
final class Idea extends BaseIdea
{
    public const string users = 'users';
    public const string events = 'events';
    public const string bots = 'bots';
    public const string moderators = 'moderators';

    /**
     * Initialize Idea with Object collections
     *
     * Overrides base class to create and configure Object collections for this application.
     * Object collections initialization is mandatory.
     * @throws DatabaseException
     * @throws IdeaObjectCollectionNotFoundException
     */
    public static function init(): void
    {
        // Create singleton instance if not exists
        parent::init();

        // Create Object collections with lazy loading strategies
        self::$db->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        self::$db->_objectCollections[self::events] = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);
        self::$db->_objectCollections[self::bots] = ObjectBots::initDB(Objects::LAZY_STRATEGY_KEY);
        self::$db->_objectCollections[self::moderators] = ObjectModerators::initDB(Objects::LAZY_STRATEGY_KEY);

        // Configure collections using parent's setRepresent method
        self::$db->setRepresent(self::users, IdeaUsers::class, UsersActions::class);
        self::$db->setRepresent(self::events, IdeaEvents::class, EventsActions::class);
        self::$db->setRepresent(self::bots, IdeaBots::class);
        self::$db->setRepresent(self::moderators, IdeaModerators::class);
    }

    /**
     * Get entity mapping for collections
     * Maps collection names to Entity class names
     *
     * @return array<string, string> Mapping of collection name => Entity class name
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
     * Create runtime instance
     *
     * @return ?IdeaRt ChatRuntime instance
     */
    protected static function createRuntime(): ?IdeaRt
    {
        return ChatRuntime::init();
    }
}
