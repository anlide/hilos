<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Files\FileVisibility;
use Hilos\HilosException;

/**
 * File Db item - read-facing wrapper around ObjectFile (HIL-336).
 *
 * `visibility` is read as a {@see FileVisibility}: the column is written only from a case of
 * it, so a value outside the enum would be a corrupted table rather than a row to serve.
 *
 * @extends DbItem<ObjectFile>
 * @property-read ?int $id
 * @property-read string $storedName
 * @property-read string $filename
 * @property-read string $mimeType
 * @property-read int $size
 * @property-read int $ownerUserId
 * @property-read FileVisibility $visibility
 * @property-read bool $bound
 * @property-read string $createdAt
 */
final class File extends DbItem
{
    /**
     * Magic getter for file properties.
     *
     * @param string $name Property name (id, storedName, filename, mimeType, size, ownerUserId, visibility, bound, createdAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectFile::id => $this->_object->id,
            ObjectFile::storedName => $this->_object->storedName,
            ObjectFile::filename => $this->_object->filename,
            ObjectFile::mimeType => $this->_object->mimeType,
            ObjectFile::size => $this->_object->size,
            ObjectFile::ownerUserId => $this->_object->ownerUserId,
            ObjectFile::visibility => FileVisibility::from($this->_object->visibility),
            ObjectFile::bound => $this->_object->bound,
            ObjectFile::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
