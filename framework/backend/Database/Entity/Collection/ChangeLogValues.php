<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogValue as EntityChangeLogValue;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogValue>
 * @implements IteratorAggregate<int|string, EntityChangeLogValue>
 * @implements ArrayAccess<int|string, EntityChangeLogValue>
 */
class ChangeLogValues extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogValue::class;
}
