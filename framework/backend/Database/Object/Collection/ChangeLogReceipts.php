<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogReceipts as EntityChangeLogReceipts;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogReceipt>
 * @method ObjectChangeLogReceipt|null current()
 * @method ObjectChangeLogReceipt|null first()
 * @method ObjectChangeLogReceipt|null last()
 * @method ObjectChangeLogReceipt|null get(int|string $key)
 * @method ObjectChangeLogReceipt|null offsetGet(mixed $offset)
 */
class ChangeLogReceipts extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogReceipt::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogReceipts::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogReceipts;
    public const bool CACHE_ROWS = false;
}
