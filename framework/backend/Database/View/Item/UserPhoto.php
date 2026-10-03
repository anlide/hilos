<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectUserPhoto>
 * @method __construct(ObjectUserPhoto $objectUserPhoto)
 * @property-read int $userId Person whose photo this is
 * @property-read int $fileId Registry file key
 * @property-read string $setAt When the photo was set
 * @property-read ?File $file Registry row, if still available
 */
class UserPhoto extends DbItem
{
    public const string file = 'file';

    /**
     * @param string $name Property name
     * @return mixed Property value
     * @throws PropertyNotFoundException When the property does not exist
     * @throws HilosException Whatever the inherited getter or the registry lookup raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUserPhoto::userId => $this->_object->userId,
            ObjectUserPhoto::fileId => $this->_object->fileId,
            ObjectUserPhoto::setAt => $this->_object->setAt,
            self::file => Hilos::$db->files[$this->_object->fileId],
            default => parent::__get($name),
        };
    }
}
