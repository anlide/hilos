<?php

namespace Demo\SimpleTodo\Database\Object\Collection;

use Demo\SimpleTodo\Database\Entity\Collection\EventUserRenames as EntityEventUserRenames;
use Demo\SimpleTodo\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\SimpleTodo\Database\TodoDbContext;
use Hilos\Database\Object\Objects;

/**
 * EventUserRenames - Object collection for user-rename audit rows.
 *
 * @extends Objects<ObjectEventUserRename>
 * @method ObjectEventUserRename|null current()
 * @method ObjectEventUserRename|null first()
 * @method ObjectEventUserRename|null last()
 * @method ObjectEventUserRename|null get(int|string $key)
 * @method ObjectEventUserRename|null offsetGet(mixed $offset)
 */
final class EventUserRenames extends Objects
{
    public const string OBJECT_CLASS = ObjectEventUserRename::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEventUserRenames::class;
    public const string COLLECTION_KEY = TodoDbContext::eventUserRenames;
}
