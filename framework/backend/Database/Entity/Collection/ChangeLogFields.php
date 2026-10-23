<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogField as EntityChangeLogField;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogField>
 * @implements IteratorAggregate<int|string, EntityChangeLogField>
 * @implements ArrayAccess<int|string, EntityChangeLogField>
 */
class ChangeLogFields extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogField::class;
}
