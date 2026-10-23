<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogChanges as EntityChangeLogChanges;
use Hilos\Database\Object\Item\ChangeLogChange as ObjectChangeLogChange;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogChange>
 * @method ObjectChangeLogChange|null current()
 * @method ObjectChangeLogChange|null first()
 * @method ObjectChangeLogChange|null last()
 * @method ObjectChangeLogChange|null get(int|string $key)
 * @method ObjectChangeLogChange|null offsetGet(mixed $offset)
 */
class ChangeLogChanges extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogChange::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogChanges::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogChanges;
    public const bool CACHE_ROWS = false;
}
