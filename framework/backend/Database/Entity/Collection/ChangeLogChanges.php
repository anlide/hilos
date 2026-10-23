<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogChange as EntityChangeLogChange;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogChange>
 * @implements IteratorAggregate<int|string, EntityChangeLogChange>
 * @implements ArrayAccess<int|string, EntityChangeLogChange>
 */
class ChangeLogChanges extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogChange::class;
}
