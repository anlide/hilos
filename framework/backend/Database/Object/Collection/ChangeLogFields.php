<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogFields as EntityChangeLogFields;
use Hilos\Database\Object\Item\ChangeLogField as ObjectChangeLogField;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogField>
 * @method ObjectChangeLogField|null current()
 * @method ObjectChangeLogField|null first()
 * @method ObjectChangeLogField|null last()
 * @method ObjectChangeLogField|null get(int|string $key)
 * @method ObjectChangeLogField|null offsetGet(mixed $offset)
 */
class ChangeLogFields extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogField::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogFields::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogFields;
    public const bool CACHE_ROWS = false;
}
