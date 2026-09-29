<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Object\Collection\UserRenames as FrameworkUserRenames;

/**
 * UserRenames - Object collection of chat's rename journal, re-pointed at chat's Object and Entity
 * collection. The collection key stays the framework's: the chain is mounted under it.
 *
 * @method ObjectUserRename|null current()
 * @method ObjectUserRename|null first()
 * @method ObjectUserRename|null last()
 * @method ObjectUserRename|null get(int|string $key)
 * @method ObjectUserRename|null offsetGet(mixed $offset)
 */
final class UserRenames extends FrameworkUserRenames
{
    public const string OBJECT_CLASS = ObjectUserRename::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserRenames::class;
}
