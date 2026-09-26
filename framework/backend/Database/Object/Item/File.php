<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\File as EntityFile;
use Hilos\Database\Object\Item\Object_;
use Hilos\Files\FileVisibility;

/**
 * File object - wraps a File entity of the files registry (HIL-336).
 *
 * `visibility` is carried here as the raw column value; the view item reads it as a
 * {@see FileVisibility}.
 *
 * @extends Object_<EntityFile>
 *
 * @property-read ?int $id
 * @property string $storedName
 * @property string $filename
 * @property string $mimeType
 * @property int $size
 * @property int $ownerUserId
 * @property string $visibility
 * @property bool $bound
 * @property string $createdAt
 */
final class File extends Object_
{
    public const string ENTITY_CLASS = EntityFile::class;
    public const string id = 'id';
    public const string storedName = 'storedName';
    public const string filename = 'filename';
    public const string mimeType = 'mimeType';
    public const string size = 'size';
    public const string ownerUserId = 'ownerUserId';
    public const string visibility = 'visibility';
    public const string bound = 'bound';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::files)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::files;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, storedName, filename, mimeType, size, ownerUserId, visibility, bound, createdAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known File field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::storedName => $this->entity->stored_name,
            self::filename => $this->entity->filename,
            self::mimeType => $this->entity->mime_type,
            self::size => $this->entity->size,
            self::ownerUserId => $this->entity->owner_user_id,
            self::visibility => $this->entity->visibility,
            self::bound => $this->entity->bound,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (storedName, filename, mimeType, size, ownerUserId, visibility, bound, createdAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a File
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::storedName => $this->entity->stored_name = (string)$value,
            self::filename => $this->entity->filename = (string)$value,
            self::mimeType => $this->entity->mime_type = (string)$value,
            self::size => $this->entity->size = (int)$value,
            self::ownerUserId => $this->entity->owner_user_id = (int)$value,
            self::visibility => $this->entity->visibility = (string)$value,
            self::bound => $this->entity->bound = (bool)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the file to an associative array.
     *
     * @return array<string, mixed> File data (id, storedName, filename, mimeType, size, ownerUserId, visibility, bound, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::storedName => $this->entity->stored_name,
            self::filename => $this->entity->filename,
            self::mimeType => $this->entity->mime_type,
            self::size => $this->entity->size,
            self::ownerUserId => $this->entity->owner_user_id,
            self::visibility => $this->entity->visibility,
            self::bound => $this->entity->bound,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
