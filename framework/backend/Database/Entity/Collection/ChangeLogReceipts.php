<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogReceipt>
 * @implements IteratorAggregate<int|string, EntityChangeLogReceipt>
 * @implements ArrayAccess<int|string, EntityChangeLogReceipt>
 */
class ChangeLogReceipts extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogReceipt::class;
}
