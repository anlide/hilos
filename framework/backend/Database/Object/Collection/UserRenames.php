<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Object\Objects;

/**
 * UserRenames - Object collection for the framework rename journal.
 *
 * @extends Objects<ObjectUserRename>
 * @method ObjectUserRename|null current()
 * @method ObjectUserRename|null first()
 * @method ObjectUserRename|null last()
 * @method ObjectUserRename|null get(int|string $key)
 * @method ObjectUserRename|null offsetGet(mixed $offset)
 */
class UserRenames extends Objects
{
    public const string OBJECT_CLASS = ObjectUserRename::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserRenames::class;
    public const string COLLECTION_KEY = HilosDbContext::userRenames;
}
