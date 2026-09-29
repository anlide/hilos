<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\UserRenamesActions;
use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Chat\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Chat\Database\View\Item\UserRename;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\SqlSortDirection;
use Hilos\Database\View\Collection\UserRenames as FrameworkUserRenames;
use Hilos\HilosException;

/**
 * UserRenames - chat's rename journal, addressed by row id like the framework's; the feed reaches
 * a row by its event.
 *
 * @method ObjectUserRenames|null getObjectCollection()
 * @method UserRename|null current()
 * @method UserRename|null first()
 * @method UserRename|null last()
 * @method UserRename|null offsetGet(mixed $offset)
 * @property-read UserRenamesActions $actions Actions for write operations
 */
final class UserRenames extends FrameworkUserRenames
{
    public const string DB_ITEM_CLASS = UserRename::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserRenames::class;

    /**
     * The journal row a feed event shows.
     *
     * @param int $eventId Feed event id
     * @return ?UserRename Row linked to the event, or null when the event shows no rename
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When the column names no field of this entity, or an object type does not match
     * @throws DatabaseException When the lookup query fails
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function ofEvent(int $eventId): ?UserRename
    {
        return $this->whereColumnIs(ObjectUserRename::eventId, $eventId)->first();
    }

    /**
     * The feed events of every rename of a person (HIL-302).
     *
     * The erasure asks it before the person's rows go: the events are the room's, and only the
     * row still knows which of them told of the person.
     *
     * @param int $userId Renamed person
     * @return list<int> Event ids of the person's renames that the feed still shows, in id order
     * @throws HilosException On database failure
     */
    public function eventIdsByUser(int $userId): array
    {
        $renames = EntityUserRename::get(
            '`' . EntityUserRename::user_id . '` = ? AND `' . EntityUserRename::event_id . '` IS NOT NULL',
            [$userId],
            [EntityUserRename::event_id => SqlSortDirection::ASC],
        );
        $ids = [];
        foreach ($renames as $entity) {
            $ids[] = (int)$entity->event_id;
        }

        return $ids;
    }
}
