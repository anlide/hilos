<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\UserPhotos as ObjectUserPhotos;
use Hilos\Database\View\Collection\UserPhotos as DbCollectionUserPhotos;
use Hilos\Database\View\Item\UserPhoto;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends DbActions<UserPhoto, ObjectUserPhotos>
 * @property-read DbCollectionUserPhotos $collection
 * @property-read ObjectUserPhotos $objectCollection
 */
class UserPhotosActions extends DbActions
{
    /**
     * Creates or replaces a person's photo, returning the former registry file id.
     *
     * @param int $userId Person whose photo is set
     * @param int $fileId New registry file id
     * @return ?int Former file id, or null when the person had no photo
     * @throws HilosException On database or truth-source failure
     */
    public function put(int $userId, int $fileId): ?int
    {
        $photo = $this->objectCollection[$userId];
        if ($photo === null) {
            $this->ensureCanCreateInSet((string)$userId);
            $objectClass = $this->objectCollection::OBJECT_CLASS;
            $photo = $objectClass::create();
            $photo->userId = $userId;
            $photo->fileId = $fileId;
            $photo->setAt = TimeHelper::getSqlDateTime();
            $photo->sync();
            $this->addObjectToCollection($photo);

            return null;
        }

        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);
        $previousFileId = $photo->fileId;
        $photo->fileId = $fileId;
        $photo->setAt = TimeHelper::getSqlDateTime();
        $photo->sync();

        return $previousFileId;
    }

    /**
     * Removes the photo while erasing a person or on their own request.
     *
     * @param int $userId Person whose photo is removed
     * @return ?int Registry file id to remove after the transaction commits
     * @throws HilosException On database or truth-source failure
     */
    public function deleteForUser(int $userId): ?int
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $photo = $this->objectCollection[$userId];
        if ($photo === null) {
            return null;
        }

        $fileId = $photo->fileId;
        $photo->delete();
        unset($this->objectCollection[$userId]);

        return $fileId;
    }
}
