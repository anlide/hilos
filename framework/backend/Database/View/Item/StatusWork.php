<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\StatusWorkActions;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use Hilos\Database\Object\Item\StatusWork as ObjectStatusWork;
use Hilos\Database\View\Collection\StatusWorkComponents;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectStatusWork>
 * @method __construct(ObjectStatusWork $object)
 * @property-read ?int $id Stored id
 * @property-read string $publicId Stored public_id
 * @property-read string $title Stored title
 * @property-read ?string $description Stored description
 * @property-read int $startsAt Stored starts_at
 * @property-read int $endsAt Stored ends_at
 * @property-read int $createdAt Stored created_at
 * @property-read int $updatedAt Stored updated_at
 * @property-read ?int $canceledAt Stored canceled_at
 * @property-read ?int $consumedAt Stored consumed_at
 * @property-read ?int $expiredAt Stored expired_at
 * @property-read int $version Stored version
 * @property-read ?int $lastMailQueuedAt Stored last_mail_queued_at
 * @property-read StatusWorkComponents $components Selected component keys for this work
 * @property-read StatusWorkActions $actions Storage mutations
 */
class StatusWork extends DbItem
{
    public const string components = 'components';

    public const string id = 'id';
    public const string publicId = 'publicId';
    public const string title = 'title';
    public const string description = 'description';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';
    public const string canceledAt = 'canceledAt';
    public const string consumedAt = 'consumedAt';
    public const string expiredAt = 'expiredAt';
    public const string version = 'version';
    public const string lastMailQueuedAt = 'lastMailQueuedAt';

    /**
     * @param string $name View field or inherited property
     * @return mixed Scalar or inherited value
     * @throws HilosException When the inherited property cannot be read
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::id => $this->_object->id,
            self::publicId => $this->_object->public_id,
            self::title => $this->_object->title,
            self::description => $this->_object->description,
            self::startsAt => $this->_object->starts_at,
            self::endsAt => $this->_object->ends_at,
            self::createdAt => $this->_object->created_at,
            self::updatedAt => $this->_object->updated_at,
            self::canceledAt => $this->_object->canceled_at,
            self::consumedAt => $this->_object->consumed_at,
            self::expiredAt => $this->_object->expired_at,
            self::version => $this->_object->version,
            self::lastMailQueuedAt => $this->_object->last_mail_queued_at,
            self::components => Hilos::$db->statusWorkComponents->whereColumnIs(EntityStatusWorkComponent::work_id, $this->_object->id),
            default => parent::__get($name),
        };
    }

    /**
     * @param bool $withId Include the primary id
     * @param bool $idAsIndex Reserved base flag
     * @param bool $withBridges Reserved base flag; relations remain access-only
     * @param bool $withCalculation Reserved base flag
     * @param bool $toFrontend Include the primary id regardless of withId
     * @return array<string, mixed> Scalar camelCase fields
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
            self::publicId => $this->_object->public_id,
            self::title => $this->_object->title,
            self::description => $this->_object->description,
            self::startsAt => $this->_object->starts_at,
            self::endsAt => $this->_object->ends_at,
            self::createdAt => $this->_object->created_at,
            self::updatedAt => $this->_object->updated_at,
            self::canceledAt => $this->_object->canceled_at,
            self::consumedAt => $this->_object->consumed_at,
            self::expiredAt => $this->_object->expired_at,
            self::version => $this->_object->version,
            self::lastMailQueuedAt => $this->_object->last_mail_queued_at,
        ];
        if (!$withId && !$toFrontend) {
            unset($fields[self::id]);
        }
        return $fields;
    }
}
