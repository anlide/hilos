<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\UserPhoto as EntityUserPhoto;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityUserPhoto>
 * @implements IteratorAggregate<int|string, EntityUserPhoto>
 * @implements ArrayAccess<int|string, EntityUserPhoto>
 */
class UserPhotos extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserPhoto::class;
}
