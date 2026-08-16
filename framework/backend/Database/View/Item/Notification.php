<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\HilosException;

/**
 * Notification Db item - read-facing wrapper around ObjectNotification.
 *
 * Surfaces the recipient-visible fields for the notification-center page
 * (HIL-195). `data` is exposed as its decoded structure so the frontend reads it
 * without re-parsing JSON.
 *
 * @extends DbItem<ObjectNotification>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $type
 * @property-read string $severity
 * @property-read string $title
 * @property-read ?string $body
 * @property-read ?array<string, mixed> $data
 * @property-read ?string $readAt
 * @property-read string $createdAt
 */
final class Notification extends DbItem
{
    /**
     * Magic getter for notification properties.
     *
     * @param string $name Property name (id, userId, type, severity, title, body, data, readAt, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectNotification::id => $this->_object->id,
            ObjectNotification::userId => $this->_object->userId,
            ObjectNotification::type => $this->_object->type,
            ObjectNotification::severity => $this->_object->severity,
            ObjectNotification::title => $this->_object->title,
            ObjectNotification::body => $this->_object->body,
            ObjectNotification::data => $this->_object->decodedData(),
            ObjectNotification::readAt => $this->_object->readAt,
            ObjectNotification::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
