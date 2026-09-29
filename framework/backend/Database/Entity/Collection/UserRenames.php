<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\UserRename as EntityUserRename;
use IteratorAggregate;

/**
 * UserRenames - Entity collection for the framework rename journal.
 *
 * @extends EntityCollection<EntityUserRename>
 * @implements IteratorAggregate<int|string, EntityUserRename>
 * @implements ArrayAccess<int|string, EntityUserRename>
 */
class UserRenames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserRename::class;
}
