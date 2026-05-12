<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Demo\Chat\Database\View\Collection\EventAttachments;
use Demo\Chat\Hilos;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\DbItem;

/**
 * EventMessage - Db item for message event details.
 *
 * @extends DbItem<ObjectEventMessage>
 * @method __construct(ObjectEventMessage &$objectEventMessage)
 *
 * @property-read int $eventId
 * @property-read ?int $authorUserId
 * @property-read ?int $authorBotId
 * @property-read string $message
 * @property-read ?Event $event Parent chat event
 * @property-read ?User $authorUser Authoring user (null for bot-authored or deleted-user messages)
 * @property-read ?Bot $authorBot Authoring bot (null for user-authored or deleted-bot messages)
 * @property-read EventAttachments $attachments Published files attached to this message event
 */
final class EventMessage extends DbItem
{
    public const string authorUser = 'authorUser';
    public const string authorBot = 'authorBot';
    public const string attachments = 'attachments';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value or related item/collection
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws DatabaseException If attachment collection loading fails
     * @throws CollectionNotManualException If attachments collection cannot accept filtered items
     * @throws ObjectGetIdStringNotImplementedException If an attachment id is not available
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEventMessage::eventId => $this->_object->eventId,
            ObjectEventMessage::authorUserId => $this->_object->authorUserId,
            ObjectEventMessage::authorBotId => $this->_object->authorBotId,
            ObjectEventMessage::message => $this->_object->message,
            ChatDbContext::event => Hilos::$db->events[$this->_object->eventId],
            self::authorUser => Hilos::$db->users[$this->_object->authorUserId],
            self::authorBot => Hilos::$db->bots[$this->_object->authorBotId],
            self::attachments => Hilos::$db->eventAttachments->forEventId($this->_object->eventId),
            default => parent::__get($name),
        };
    }
}
