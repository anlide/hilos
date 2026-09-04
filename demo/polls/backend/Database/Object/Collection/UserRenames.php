<?php

namespace Demo\Polls\Database\Object\Collection;

use Demo\Polls\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Demo\Polls\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Polls\Database\PollsDbContext;
use Hilos\Database\Object\Objects;

/**
 * UserRenames - Object collection for user-rename audit rows.
 *
 * @extends Objects<ObjectUserRename>
 * @method ObjectUserRename|null current()
 * @method ObjectUserRename|null first()
 * @method ObjectUserRename|null last()
 * @method ObjectUserRename|null get(int|string $key)
 * @method ObjectUserRename|null offsetGet(mixed $offset)
 */
final class UserRenames extends Objects
{
    public const string OBJECT_CLASS = ObjectUserRename::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserRenames::class;
    public const string COLLECTION_KEY = PollsDbContext::userRenames;
}
