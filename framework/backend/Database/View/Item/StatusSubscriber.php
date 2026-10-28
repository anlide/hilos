<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Closure;
use Hilos\Database\Actions\Item\StatusSubscriberActions;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusSubscriber>
 * @method __construct(ObjectStatusSubscriber $object)
 * @property-read ?int $id Stored id
 * @property-read string $email Stored email
 * @property-read int $subscribedAt Stored subscribed_at
 * @property-read bool $confirmed Stored confirmed
 * @property-read ?int $confirmedAt Stored confirmed_at
 * @property-read ?int $confirmationExpiresAt Stored confirmation_expires_at
 * @property-read ?string $browserTimeZone Stored browser_time_zone
 * @property-read StatusSubscriberActions $actions Subscription mutations
 */
class StatusSubscriber extends DbItem
{
    public const string id = 'id';
    public const string email = 'email';
    public const string subscribedAt = 'subscribedAt';
    public const string confirmed = 'confirmed';
    public const string confirmedAt = 'confirmedAt';
    public const string confirmationExpiresAt = 'confirmationExpiresAt';
    public const string browserTimeZone = 'browserTimeZone';

    /**
     * @param string $name Scalar View field
     * @return mixed Non-secret field or inherited property
     * @throws HilosException When the field cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::email => $this->_object->email,
            self::subscribedAt => $this->_object->subscribed_at,
            self::confirmed => $this->_object->confirmed,
            self::confirmedAt => $this->_object->confirmed_at,
            self::confirmationExpiresAt => $this->_object->confirmation_expires_at,
            self::browserTimeZone => $this->_object->browser_time_zone,
            default => parent::__get($name),
        };
    }

    /**
     * @param Closure(string, string, ?string): void $deliver Owner's mail operation, receiving address, unsubscribe token and zone
     * @throws HilosException When the owner or mail operation refuses delivery
     */
    public function deliverMail(Closure $deliver): void
    {
        $this->_object->deliverMail($deliver);
    }

    /**
     * @param bool $withId Include the primary id
     * @param bool $idAsIndex Reserved base flag
     * @param bool $withBridges Reserved base flag
     * @param bool $withCalculation Reserved base flag
     * @param bool $toFrontend Always include the primary id
     * @return array<string, mixed> Non-secret scalar View fields
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $fields = [
            self::id => $this->_object->id,
            self::email => $this->_object->email,
            self::subscribedAt => $this->_object->subscribed_at,
            self::confirmed => $this->_object->confirmed,
            self::confirmedAt => $this->_object->confirmed_at,
            self::confirmationExpiresAt => $this->_object->confirmation_expires_at,
            self::browserTimeZone => $this->_object->browser_time_zone,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
