<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\FileVariant as EntityFileVariant;

/**
 * The scalar row of a rendered registry image (HIL-141).
 *
 * @extends Object_<EntityFileVariant>
 * @property-read ?int $id
 * @property int $fileId
 * @property string $variant
 * @property string $signature
 * @property string $storedName
 * @property string $mimeType
 * @property int $size
 * @property string $createdAt
 */
class FileVariant extends Object_
{
    public const string ENTITY_CLASS = EntityFileVariant::class;
    public const string id = 'id';
    public const string fileId = 'fileId';
    public const string variant = 'variant';
    public const string signature = 'signature';
    public const string storedName = 'storedName';
    public const string mimeType = 'mimeType';
    public const string size = 'size';
    public const string createdAt = 'createdAt';

    /**
     * @return string The image-copy collection key
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::fileVariants;
    }

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::fileId => $this->entity->file_id,
            self::variant => $this->entity->variant,
            self::signature => $this->entity->signature,
            self::storedName => $this->entity->stored_name,
            self::mimeType => $this->entity->mime_type,
            self::size => $this->entity->size,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Writable scalar property name
     * @param mixed $value Value to persist
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::fileId => $this->entity->file_id = (int)$value,
            self::variant => $this->entity->variant = (string)$value,
            self::signature => $this->entity->signature = (string)$value,
            self::storedName => $this->entity->stored_name = (string)$value,
            self::mimeType => $this->entity->mime_type = (string)$value,
            self::size => $this->entity->size = (int)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @return array<string, mixed> Scalar fields of the stored copy
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::fileId => $this->entity->file_id,
            self::variant => $this->entity->variant,
            self::signature => $this->entity->signature,
            self::storedName => $this->entity->stored_name,
            self::mimeType => $this->entity->mime_type,
            self::size => $this->entity->size,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
