<?php

declare(strict_types=1);

namespace Demo\Chat\Database;

use Demo\Chat\Database\Actions\Collection\BotsActions;
use Demo\Chat\Database\Actions\Collection\EventAttachmentsActions;
use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Actions\Collection\EventUserRegistrationsActions;
use Demo\Chat\Database\Actions\Collection\EventUserRenamesActions;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Database\Actions\Collection\ModeratorPromptPiecesActions;
use Demo\Chat\Database\Actions\Collection\UsersActions;
use Demo\Chat\Database\Actions\Item\BotActions;
use Demo\Chat\Database\Actions\Item\ModeratorPromptPieceActions;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Collection\EventAttachments as ObjectEventAttachments;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\Object\Collection\EventUserRegistrations as ObjectEventUserRegistrations;
use Demo\Chat\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPieces;
use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Database\View\Collection\EventAttachments;
use Demo\Chat\Database\View\Collection\EventMessages;
use Demo\Chat\Database\View\Collection\EventUserRegistrations;
use Demo\Chat\Database\View\Collection\EventUserRenames;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\ModeratorPromptPieces;
use Demo\Chat\Database\View\Collection\Users;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * ChatDbContext - App-specific database context ($db layer).
 *
 * @extends HilosDbContext
 * @property-read Users $users
 * @property-read Events $events
 * @property-read EventMessages $eventMessages
 * @property-read EventUserRegistrations $eventUserRegistrations
 * @property-read EventUserRenames $eventUserRenames
 * @property-read EventAttachments $eventAttachments
 * @property-read Bots $bots
 * @property-read ModeratorPromptPieces $moderatorPromptPieces
 */
final class ChatDbContext extends HilosDbContext
{
    public const string users = 'users';
    public const string events = 'events';
    public const string eventMessages = 'eventMessages';
    public const string eventUserRegistrations = 'eventUserRegistrations';
    public const string eventUserRenames = 'eventUserRenames';
    public const string eventAttachments = 'eventAttachments';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';

    public const string user = 'user';
    public const string event = 'event';
    public const string eventMessage = 'eventMessage';
    public const string eventUserRegistration = 'eventUserRegistration';
    public const string eventUserRename = 'eventUserRename';
    public const string eventAttachment = 'eventAttachment';
    public const string bot = 'bot';
    public const string moderatorPromptPiece = 'moderatorPromptPiece';

    /**
     * Configures database context with object collections and view representations.
     *
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     */
    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::events] = ObjectEvents::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->_objectCollections[self::eventMessages] = ObjectEventMessages::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::eventUserRegistrations] = ObjectEventUserRegistrations::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::eventUserRenames] = ObjectEventUserRenames::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::eventAttachments] = ObjectEventAttachments::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[self::bots] = ObjectBots::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->_objectCollections[self::moderatorPromptPieces] = ObjectModeratorPromptPieces::initDB(Objects::LAZY_STRATEGY_NONE);

        $this->setRepresent(self::users, Users::class, UsersActions::class, UserActions::class);
        $this->setRepresent(self::events, Events::class, EventsActions::class);
        $this->setRepresent(self::eventMessages, EventMessages::class, EventMessagesActions::class);
        $this->setRepresent(self::eventUserRegistrations, EventUserRegistrations::class, EventUserRegistrationsActions::class);
        $this->setRepresent(self::eventUserRenames, EventUserRenames::class, EventUserRenamesActions::class);
        $this->setRepresent(self::eventAttachments, EventAttachments::class, EventAttachmentsActions::class);
        $this->setRepresent(self::bots, Bots::class, BotsActions::class, BotActions::class);
        $this->setRepresent(self::moderatorPromptPieces, ModeratorPromptPieces::class, ModeratorPromptPiecesActions::class, ModeratorPromptPieceActions::class);
    }
}
