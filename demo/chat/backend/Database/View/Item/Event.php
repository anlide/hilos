<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Collection\EventAttachments;
use Demo\Chat\Hilos;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\DbItem;

/**
 * Event - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectEvent>
 * @method __construct(ObjectEvent $objectEvent)
 *
 * @property-read ?int $id
 * @property-read string $type
 * @property-read string $timestamp
 * @property-read ?EventMessage $eventMessage Message detail for message events
 * @property-read ?EventUserRegistration $eventUserRegistration Registration detail for registration events
 * @property-read ?EventUserRename $eventUserRename Rename detail for rename events
 * @property-read EventAttachments $attachments Published files attached to this event
 */
final class Event extends DbItem
{
    public const string attachments = 'attachments';

    /**
     * Property getter (read-only access). Supports lazy loading of related items.
     *
     * @param string $name Property name
     * @return mixed Property value, relation, or attachments collection
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws DatabaseException If attachment collection loading fails
     * @throws CollectionNotManualException If attachments collection cannot accept filtered items
     * @throws ObjectGetIdStringNotImplementedException If an attachment id is not available
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEvent::id => $this->_object->id,
            ObjectEvent::type => $this->_object->type,
            ObjectEvent::timestamp => $this->_object->timestamp,
            ChatDbContext::eventMessage => Hilos::$db->eventMessages[$this->_object->id],
            ChatDbContext::eventUserRegistration => Hilos::$db->eventUserRegistrations[$this->_object->id],
            ChatDbContext::eventUserRename => Hilos::$db->eventUserRenames[$this->_object->id],
            self::attachments => Hilos::$db->eventAttachments->forEventId($this->_object->id),
            default => parent::__get($name),
        };
    }

    /**
     * Converts the event to a backend or legacy entity array with direct bridge data.
     *
     * Browser page payloads use BrowserContext rows; the frontend mode remains
     * only for established generic entity callers.
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend Prepare a legacy frontend-safe entity payload
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

        if ($withBridges || $toFrontend) {
            $result[ChatDbContext::eventMessage] = $this->eventMessage?->toArray(
                toFrontend: $toFrontend,
            );
            $result[ChatDbContext::eventUserRegistration] = $this->eventUserRegistration?->toArray(
                toFrontend: $toFrontend,
            );
            $result[ChatDbContext::eventUserRename] = $this->eventUserRename?->toArray(
                toFrontend: $toFrontend,
            );
            $result[self::attachments] = $this->attachments->toArray(
                idAsIndex: false,
                toFrontend: $toFrontend,
            );
        }

        return $result;
    }
}
