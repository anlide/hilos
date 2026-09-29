<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\UserMerges as EntityUserMerges;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\Object\Objects;

/**
 * UserMerges - Object collection for the framework merge table, keyed by the folded account.
 *
 * @extends Objects<ObjectUserMerge>
 * @method ObjectUserMerge|null current()
 * @method ObjectUserMerge|null first()
 * @method ObjectUserMerge|null last()
 * @method ObjectUserMerge|null get(int|string $key)
 * @method ObjectUserMerge|null offsetGet(mixed $offset)
 */
class UserMerges extends Objects
{
    public const string OBJECT_CLASS = ObjectUserMerge::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserMerges::class;
    public const string COLLECTION_KEY = HilosDbContext::userMerges;
}
