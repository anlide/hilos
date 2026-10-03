<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\UserPhotos as EntityUserPhotos;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectUserPhoto>
 * @method ObjectUserPhoto|null current()
 * @method ObjectUserPhoto|null first()
 * @method ObjectUserPhoto|null last()
 * @method ObjectUserPhoto|null get(int|string $key)
 * @method ObjectUserPhoto|null offsetGet(mixed $offset)
 */
class UserPhotos extends Objects
{
    public const string OBJECT_CLASS = ObjectUserPhoto::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserPhotos::class;
    public const string COLLECTION_KEY = HilosDbContext::userPhotos;
}
