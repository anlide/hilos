<?php

namespace Demo\SimpleTodo\Database\Object\Collection;

use Demo\SimpleTodo\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Demo\SimpleTodo\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\SimpleTodo\Database\TodoDbContext;
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
    public const string COLLECTION_KEY = TodoDbContext::userRenames;
}
