<?php

declare(strict_types=1);

namespace Demo\Chat\Database;

use Demo\Chat\Database\Actions\Collection\BotsActions;
use Demo\Chat\Database\Actions\Collection\EventAttachmentsActions;
use Demo\Chat\Database\Actions\Collection\EventMessagesActions;
use Demo\Chat\Database\Actions\Collection\EventUserRegistrationsActions;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Database\Actions\Collection\ModeratorPromptPiecesActions;
use Demo\Chat\Database\Actions\Collection\UserRenamesActions;
use Demo\Chat\Database\Actions\Item\BotActions;
use Demo\Chat\Database\Actions\Item\ModeratorPromptPieceActions;
use Demo\Chat\Database\Actions\Item\UserRenameActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Collection\EventAttachments as ObjectEventAttachments;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\Object\Collection\EventUserRegistrations as ObjectEventUserRegistrations;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPieces;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Database\View\Collection\EventAttachments;
use Demo\Chat\Database\View\Collection\EventMessages;
use Demo\Chat\Database\View\Collection\EventUserRegistrations;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\ModeratorPromptPieces;
use Demo\Chat\Database\View\Collection\UserRenames;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * ChatDbContext - App-specific database context ($db layer).
 *
 * @extends HilosDbContext
 * @property-read Events $events
 * @property-read EventMessages $eventMessages
 * @property-read EventUserRegistrations $eventUserRegistrations
 * @property-read EventAttachments $eventAttachments
 * @property-read Bots $bots
 * @property-read ModeratorPromptPieces $moderatorPromptPieces
 * @property-read UserRenames $userRenames
 */
final class ChatDbContext extends HilosDbContext
{
    public const string events = 'events';
    public const string eventMessages = 'eventMessages';
    public const string eventUserRegistrations = 'eventUserRegistrations';
    public const string eventAttachments = 'eventAttachments';
    public const string bots = 'bots';
    public const string moderatorPromptPieces = 'moderatorPromptPieces';

    public const string event = 'event';
    public const string eventMessage = 'eventMessage';
    public const string eventUserRegistration = 'eventUserRegistration';
    public const string eventAttachment = 'eventAttachment';
    public const string bot = 'bot';
    public const string moderatorPromptPiece = 'moderatorPromptPiece';

    /**
     * Configures database context with object collections and view representations.
     *
     * @throws FrameworkExtensionException When a framework key is extended by a chain that does not extend it
     * @throws CollectionAlreadyMountedException When a key is represented twice
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     * @throws UnknownLazyStrategyException When a collection is mounted under a strategy initDB() does not know
     * @throws InvalidMountedCollectionException When a project collection key is empty or already mounted
     */
    public function configure(): void
    {
        parent::configure();

        $this->mountObjectCollection(ObjectEvents::class, Objects::LAZY_STRATEGY_NONE);
        $this->mountObjectCollection(ObjectEventMessages::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectEventUserRegistrations::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectEventAttachments::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectBots::class, Objects::LAZY_STRATEGY_NONE);
        $this->mountObjectCollection(ObjectModeratorPromptPieces::class, Objects::LAZY_STRATEGY_NONE);

        $this->setRepresent(self::events, Events::class, EventsActions::class);
        $this->setRepresent(self::eventMessages, EventMessages::class, EventMessagesActions::class);
        $this->setRepresent(self::eventUserRegistrations, EventUserRegistrations::class, EventUserRegistrationsActions::class);
        $this->setRepresent(self::eventAttachments, EventAttachments::class, EventAttachmentsActions::class);
        $this->setRepresent(self::bots, Bots::class, BotsActions::class, BotActions::class);
        $this->setRepresent(
            self::moderatorPromptPieces,
            ModeratorPromptPieces::class,
            ModeratorPromptPiecesActions::class,
            ModeratorPromptPieceActions::class,
        );
    }

    /**
     * Chat's rename journal carries the event of the room's feed that shows each rename (HIL-1196).
     *
     * @return array<string, FrameworkExtension> Framework keys served by project subclasses
     */
    protected function frameworkExtensions(): array
    {
        return [
            ...parent::frameworkExtensions(),
            self::userRenames => new FrameworkExtension(
                UserRenames::class,
                UserRenamesActions::class,
                UserRenameActions::class,
            ),
        ];
    }
}
