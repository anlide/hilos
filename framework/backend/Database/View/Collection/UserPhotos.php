<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\UserPhotosActions;
use Hilos\Database\Object\Collection\UserPhotos as ObjectUserPhotos;
use Hilos\Database\View\Item\UserPhoto;

/**
 * Profile photos keyed by user id: `$photos[$userId]` is that person's row or null.
 *
 * @extends DbCollection<UserPhoto, ObjectUserPhotos>
 * @method ObjectUserPhotos|null getObjectCollection()
 * @method UserPhoto|null current()
 * @method UserPhoto|null first()
 * @method UserPhoto|null last()
 * @method UserPhoto|null offsetGet(mixed $offset)
 * @property-read UserPhotosActions $actions
 */
class UserPhotos extends DbCollection
{
    public const string DB_ITEM_CLASS = UserPhoto::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserPhotos::class;
}
