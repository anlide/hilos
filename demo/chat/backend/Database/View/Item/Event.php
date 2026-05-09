<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Collection\EventAttachments;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * Event - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectEvent>
 * @method __construct(ObjectEvent &$objectEvent)
 *
 * @property-read ?int $id
 * @property-read string $type
 * @property-read string $timestamp
 * @property-read ?string $message Published message text for message events
 * @property-read ?int $authorUserId Authoring user id for message events
 * @property-read ?int $authorBotId Authoring bot id for message events
 * @property-read ?int $targetUserId Subject user id for user lifecycle events
 * @property-read ?int $actorUserId Initiating user id for rename events
 * @property-read ?string $oldName Previous display name for rename events
 * @property-read ?string $newName New display name for rename events
 * @property-read ?EventMessage $eventMessage Message detail for message events
 * @property-read ?EventUserRegistration $eventUserRegistration Registration detail for registration events
 * @property-read ?EventUserRename $eventUserRename Rename detail for rename events
 * @property-read ?User $user Subject user for this event (null if no target user or user not found)
 * @property-read ?Bot $bot Authoring bot for this event (null if no bot author or bot not found)
 * @property-read EventAttachments $attachments Published files attached to this event
 */
final class Event extends DbItem
{
    public const string message = 'message';
    public const string authorUserId = 'authorUserId';
    public const string authorBotId = 'authorBotId';
    public const string targetUserId = 'targetUserId';
    public const string actorUserId = 'actorUserId';
    public const string oldName = 'oldName';
    public const string newName = 'newName';
    public const string attachments = 'attachments';

    /**
     * Property getter (read-only access). Supports lazy loading of related items.
     *
     * @param string $name Property name
     * @return mixed Property value, relation, or attachments collection
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If collection actions class is not defined for related item
     */
    public function __get(string $name): mixed
    {
        $eventId = $this->_object->id;

        return match ($name) {
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,
            DbChatContext::eventMessage => $eventId === null
                ? null
                : (Hilos::$db->eventMessages[$eventId] ?? null),
            DbChatContext::eventUserRegistration => $eventId === null
                ? null
                : (Hilos::$db->eventUserRegistrations[$eventId] ?? null),
            DbChatContext::eventUserRename => $eventId === null
                ? null
                : (Hilos::$db->eventUserRenames[$eventId] ?? null),
            self::message => $this->eventMessage?->message,
            self::authorUserId => $this->eventMessage?->authorUserId,
            self::authorBotId => $this->eventMessage?->authorBotId,
            self::targetUserId => $this->eventUserRegistration?->targetUserId
                ?? $this->eventUserRename?->targetUserId,
            self::actorUserId => $this->eventUserRename?->actorUserId,
            self::oldName => $this->eventUserRename?->oldName,
            self::newName => $this->eventUserRename?->newName,
            DbChatContext::user => match (true) {
                $this->authorUserId !== null => Hilos::$db->users[$this->authorUserId] ?? null,
                $this->targetUserId !== null => Hilos::$db->users[$this->targetUserId] ?? null,
                default => null,
            },
            DbChatContext::bot => $this->authorBotId === null
                ? null
                : (Hilos::$db->bots[$this->authorBotId] ?? null),
            self::attachments => $eventId === null
                ? EventAttachments::initEmpty()
                : Hilos::$db->eventAttachments->forEventId($eventId),
            default => parent::__get($name),
        };
    }

    /**
     * Converts the event to a caller-facing array with top-level attachments.
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend Prepare a frontend-safe payload
     * @return array<string, mixed> Event payload
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
        $result[self::message] = $this->message;
        $result[self::authorUserId] = $this->authorUserId;
        $result[self::authorBotId] = $this->authorBotId;
        $result[self::targetUserId] = $this->targetUserId;
        $result[self::actorUserId] = $this->actorUserId;
        $result[self::oldName] = $this->oldName;
        $result[self::newName] = $this->newName;
        $result[self::attachments] = $this->attachments->toArray(
            idAsIndex: false,
            toFrontend: $toFrontend,
        );

        return $result;
    }
}
