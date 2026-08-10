<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\NotificationPreference as ObjectNotificationPreference;

/**
 * NotificationPreference Db item - read-facing wrapper around ObjectNotificationPreference.
 *
 * Surfaces the per-user channel opt-out fields (HIL-485). A present item is a
 * muted channel; the profile section derives its per-channel toggle state from the
 * recipient's muted set rather than from a row per channel.
 *
 * @extends DbItem<ObjectNotificationPreference>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $channel
 * @property-read bool $enabled
 * @property-read string $createdAt
 * @property-read string $updatedAt
 */
final class NotificationPreference extends DbItem
{
    /**
     * Magic getter for notification preference properties.
     *
     * @param string $name Property name (id, userId, channel, enabled, createdAt, updatedAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectNotificationPreference::id => $this->_object->id,
            ObjectNotificationPreference::userId => $this->_object->userId,
            ObjectNotificationPreference::channel => $this->_object->channel,
            ObjectNotificationPreference::enabled => $this->_object->enabled,
            ObjectNotificationPreference::createdAt => $this->_object->createdAt,
            ObjectNotificationPreference::updatedAt => $this->_object->updatedAt,
            default => parent::__get($name),
        };
    }
}
