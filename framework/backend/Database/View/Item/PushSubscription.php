<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;

/**
 * PushSubscription Db item - read-facing wrapper around ObjectPushSubscription.
 *
 * Surfaces one device's push subscription fields (HIL-199). The push delivery
 * channel reads the endpoint plus the p256dh / auth keys to send to the device.
 *
 * @extends DbItem<ObjectPushSubscription>
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $endpoint
 * @property-read string $p256dh
 * @property-read string $auth
 * @property-read ?string $userAgent
 * @property-read ?string $createdAt
 * @property-read ?string $lastSeenAt
 */
final class PushSubscription extends DbItem
{
    /**
     * Magic getter for push subscription properties.
     *
     * @param string $name Property name (id, userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectPushSubscription::id => $this->_object->id,
            ObjectPushSubscription::userId => $this->_object->userId,
            ObjectPushSubscription::endpoint => $this->_object->endpoint,
            ObjectPushSubscription::p256dh => $this->_object->p256dh,
            ObjectPushSubscription::auth => $this->_object->auth,
            ObjectPushSubscription::userAgent => $this->_object->userAgent,
            ObjectPushSubscription::createdAt => $this->_object->createdAt,
            ObjectPushSubscription::lastSeenAt => $this->_object->lastSeenAt,
            default => parent::__get($name),
        };
    }
}
