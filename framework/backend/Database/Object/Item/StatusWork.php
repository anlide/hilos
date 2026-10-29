<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusWork as EntityStatusWork;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;

/**
 * @extends Object_<EntityStatusWork>
 * @property ?int $id Scalar storage field
 * @property string $public_id Scalar storage field
 * @property string $title Scalar storage field
 * @property ?string $description Scalar storage field
 * @property int $starts_at Scalar storage field
 * @property int $ends_at Scalar storage field
 * @property int $created_at Scalar storage field
 * @property int $updated_at Scalar storage field
 * @property ?int $canceled_at Scalar storage field
 * @property ?int $consumed_at Scalar storage field
 * @property ?int $expired_at Scalar storage field
 * @property int $version Scalar storage field
 * @property ?int $last_mail_queued_at Scalar storage field
 */
class StatusWork extends Object_
{
    public const string ENTITY_CLASS = EntityStatusWork::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusWorks::class;

    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string title = 'title';
    public const string description = 'description';
    public const string starts_at = 'starts_at';
    public const string ends_at = 'ends_at';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';
    public const string canceled_at = 'canceled_at';
    public const string consumed_at = 'consumed_at';
    public const string expired_at = 'expired_at';
    public const string version = 'version';
    public const string last_mail_queued_at = 'last_mail_queued_at';

    /**
     * @param string $property Scalar storage field
     * @return mixed Stored value or inherited property
     * @throws DatabaseException When the property is unknown
     * @throws PropertyNotAccessibleException When the inherited property is inaccessible
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::title => $this->entity->title,
            self::description => $this->entity->description,
            self::starts_at => $this->entity->starts_at,
            self::ends_at => $this->entity->ends_at,
            self::created_at => $this->entity->created_at,
            self::updated_at => $this->entity->updated_at,
            self::canceled_at => $this->entity->canceled_at,
            self::consumed_at => $this->entity->consumed_at,
            self::expired_at => $this->entity->expired_at,
            self::version => $this->entity->version,
            self::last_mail_queued_at => $this->entity->last_mail_queued_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Scalar storage field
     * @param mixed $value Value with the declared field type
     * @throws DatabaseException When the property is not writable
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::public_id => $this->entity->public_id = $value,
            self::title => $this->entity->title = $value,
            self::description => $this->entity->description = $value,
            self::starts_at => $this->entity->starts_at = $value,
            self::ends_at => $this->entity->ends_at = $value,
            self::created_at => $this->entity->created_at = $value,
            self::updated_at => $this->entity->updated_at = $value,
            self::canceled_at => $this->entity->canceled_at = $value,
            self::consumed_at => $this->entity->consumed_at = $value,
            self::expired_at => $this->entity->expired_at = $value,
            self::version => $this->entity->version = $value,
            self::last_mail_queued_at => $this->entity->last_mail_queued_at = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::public_id => $this->entity->public_id,
            self::title => $this->entity->title,
            self::description => $this->entity->description,
            self::starts_at => $this->entity->starts_at,
            self::ends_at => $this->entity->ends_at,
            self::created_at => $this->entity->created_at,
            self::updated_at => $this->entity->updated_at,
            self::canceled_at => $this->entity->canceled_at,
            self::consumed_at => $this->entity->consumed_at,
            self::expired_at => $this->entity->expired_at,
            self::version => $this->entity->version,
            self::last_mail_queued_at => $this->entity->last_mail_queued_at,
        ];
    }

    /**
     * @param string $title Public title
     * @param ?string $description Optional public description
     * @param int $startsAt Inclusive start in UTC epoch milliseconds
     * @param int $endsAt Exclusive end in UTC epoch milliseconds
     * @param int $at Write moment in UTC epoch milliseconds
     * @throws ValidationException When public text or interval is invalid
     */
    public static function validateContent(string $title, ?string $description, int $startsAt, int $endsAt, int $at): void
    {
        if (!mb_check_encoding($title, 'UTF-8') || trim($title) === '' || mb_strlen($title, 'UTF-8') > 200) {
            throw new ValidationException('Enter a work title of 1 to 200 characters.');
        }
        if ($description !== null && (!mb_check_encoding($description, 'UTF-8') || mb_strlen($description, 'UTF-8') > 2000)) {
            throw new ValidationException('Enter a work description of at most 2000 characters.');
        }
        if ($at < 0 || $startsAt < 0 || $endsAt <= $startsAt) {
            throw new ValidationException('The work end must be after its start.');
        }
    }
}
