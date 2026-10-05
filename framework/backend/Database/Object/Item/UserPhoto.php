<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserPhoto as EntityUserPhoto;
use Hilos\Database\Object\Collection\UserPhotos as ObjectUserPhotos;

/**
 * @extends Object_<EntityUserPhoto>
 * @property int $userId
 * @property int $fileId
 * @property string $setAt
 */
class UserPhoto extends Object_
{
    public const string ENTITY_CLASS = EntityUserPhoto::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserPhotos::class;

    public const string userId = 'userId';
    public const string fileId = 'fileId';
    public const string setAt = 'setAt';

    /**
     * @param string $property Property name
     * @return mixed Property value or inherited getter result
     * @throws DatabaseException When entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            self::fileId => $this->entity->file_id,
            self::setAt => $this->entity->set_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException When entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::fileId => $this->entity->file_id = (int)$value,
            self::setAt => $this->entity->set_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar fields of this row */
    public function toArray(): array
    {
        return [
            self::userId => $this->entity->user_id,
            self::fileId => $this->entity->file_id,
            self::setAt => $this->entity->set_at,
        ];
    }
}
