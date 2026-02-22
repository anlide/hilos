<?php

namespace Demo\Chat\Database;

use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Database\Actions\Collection\UsersActions;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPieces;
use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\ModeratorPromptPieces;
use Demo\Chat\Database\View\Collection\Users;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Object\Objects;

/**
 * DbChatContext - App-specific database context ($db layer).
 *
 * @property-read Users $users
 * @property-read Events $events
 * @property-read Bots $bots
 * @property-read ModeratorPromptPieces $moderatorPromptPieces
 */
class DbChatContext extends DbContext
{
    public const string users = 'users';
    public const string events = 'events';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';

    public const string user = 'user';
    public const string event = 'event';
    public const string bot = 'bot';
    public const string moderatorPromptPiece = 'moderatorPromptPiece';

    /**
     * @throws ObjectCollectionNotFoundException
     * @throws DatabaseException
     */
    public function configure(): void
    {
        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::events] = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->_objectCollections[self::bots] = ObjectBots::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->_objectCollections[self::moderatorPromptPieces] = ObjectModeratorPromptPieces::initDB(Objects::LAZY_STRATEGY_NONE);

        $this->setRepresent(self::users, Users::class, UsersActions::class, UserActions::class);
        $this->setRepresent(self::events, Events::class, EventsActions::class);
        $this->setRepresent(self::bots, Bots::class);
        $this->setRepresent(self::moderatorPromptPieces, ModeratorPromptPieces::class);
    }
}
