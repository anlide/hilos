<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\FileVariantActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\FileVariant as ObjectFileVariant;
use Hilos\HilosException;

/**
 * Read-facing row of one rendered registry image (HIL-141).
 *
 * @extends DbItem<ObjectFileVariant>
 * @method __construct(ObjectFileVariant $object)
 * @property-read FileVariantActions $actions
 * @property-read ?int $id
 * @property-read int $fileId
 * @property-read string $variant
 * @property-read string $signature
 * @property-read string $storedName
 * @property-read string $mimeType
 * @property-read int $size
 * @property-read string $createdAt
 */
final class FileVariant extends DbItem
{
    /**
     * @param string $name Scalar property or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When the inherited getter refuses the member
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectFileVariant::id => $this->_object->id,
            ObjectFileVariant::fileId => $this->_object->fileId,
            ObjectFileVariant::variant => $this->_object->variant,
            ObjectFileVariant::signature => $this->_object->signature,
            ObjectFileVariant::storedName => $this->_object->storedName,
            ObjectFileVariant::mimeType => $this->_object->mimeType,
            ObjectFileVariant::size => $this->_object->size,
            ObjectFileVariant::createdAt => $this->_object->createdAt,
            default => parent::__get($name),
        };
    }
}
