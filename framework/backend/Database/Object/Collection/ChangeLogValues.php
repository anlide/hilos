<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogValues as EntityChangeLogValues;
use Hilos\Database\Object\Item\ChangeLogValue as ObjectChangeLogValue;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogValue>
 * @method ObjectChangeLogValue|null current()
 * @method ObjectChangeLogValue|null first()
 * @method ObjectChangeLogValue|null last()
 * @method ObjectChangeLogValue|null get(int|string $key)
 * @method ObjectChangeLogValue|null offsetGet(mixed $offset)
 */
class ChangeLogValues extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogValue::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogValues::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogValues;
    public const bool CACHE_ROWS = false;
}
