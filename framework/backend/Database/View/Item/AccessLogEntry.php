<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\AccessLogEntry as ObjectAccessLogEntry;
use Hilos\HilosException;

/**
 * AccessLogEntry Db item - read-facing wrapper around ObjectAccessLogEntry (HIL-1174).
 *
 * @extends DbItem<ObjectAccessLogEntry>
 * @property-read ?int $id
 * @property-read int $userId Person whose account was used
 * @property-read string $event What the use was (an AccessLogEvent value)
 * @property-read ?string $ipAddress Network address of the use, or null when the transport gave none
 * @property-read string $occurredAt Moment of the use (SQL datetime)
 */
class AccessLogEntry extends DbItem
{
    /**
     * @param string $name Property name (see class @property list)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If an item actions class is invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAccessLogEntry::id => $this->_object->id,
            ObjectAccessLogEntry::userId => $this->_object->userId,
            ObjectAccessLogEntry::event => $this->_object->event,
            ObjectAccessLogEntry::ipAddress => $this->_object->ipAddress,
            ObjectAccessLogEntry::occurredAt => $this->_object->occurredAt,
            default => parent::__get($name),
        };
    }
}
