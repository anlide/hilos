<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectUserPhoto>
 * @method __construct(ObjectUserPhoto $objectUserPhoto)
 * @property-read int $userId Person whose photo this is
 * @property-read int $fileId Registry file key
 * @property-read string $setAt When the photo was set
 * @property-read ?File $file Registry row, if still available
 * @property-read string $photo Public address of the displayed variant
 */
class UserPhoto extends DbItem
{
    public const string file = 'file';
    public const string photo = 'photo';

    /**
     * @param string $name Property name
     * @return mixed Property value
     * @throws PropertyNotFoundException When the property does not exist
     * @throws InvalidArgumentException When the avatar variant is not declared
     * @throws HilosException Whatever the inherited getter or the registry lookup raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUserPhoto::userId => $this->_object->userId,
            ObjectUserPhoto::fileId => $this->_object->fileId,
            ObjectUserPhoto::setAt => $this->_object->setAt,
            self::file => Hilos::$db->files[$this->_object->fileId] ?? null,
            self::photo => HilosFiles::downloadPath($this->_object->fileId, ProfilePhotoUploadTarget::VARIANT),
            default => parent::__get($name),
        };
    }
}
