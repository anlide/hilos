<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Collection\EventUserRenames as EntityEventUserRenames;
use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Hilos\Database\Object\Objects;

/**
 * EventUserRenames - Object collection for rename event details.
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
    public const string COLLECTION_KEY = ChatDbContext::eventUserRenames;
}
