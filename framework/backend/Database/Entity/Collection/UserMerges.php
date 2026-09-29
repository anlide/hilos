<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use IteratorAggregate;

/**
 * UserMerges - Entity collection for the framework merge table.
 *
 * @extends EntityCollection<EntityUserMerge>
 * @implements IteratorAggregate<int|string, EntityUserMerge>
 * @implements ArrayAccess<int|string, EntityUserMerge>
 */
class UserMerges extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserMerge::class;
}
