<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Chat\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Chat\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\UserRenamesActions as FrameworkUserRenamesActions;
use Hilos\HilosException;

/**
 * UserRenamesActions - write operations for chat's rename journal: the framework's, and the one
 * that takes the journal off a feed being cleared (HIL-1196).
 *
 * @property-read DbCollectionUserRenames $collection
 * @property-read ObjectUserRenames $objectCollection
 */
final class UserRenamesActions extends FrameworkUserRenamesActions
{
    /**
     * Takes every rename off its feed event - the room's history is being cleared.
     *
     * The rows stay: the journal is the person's history, not the room's. Each link is cleared
     * through its object, so the readers of the journal hear it before the events go; the key's
     * ON DELETE SET NULL only backs this up in the database.
     *
     * @return int Number of renames taken off their event
     * @throws HilosException On database or truth-source failure
     */
    public function unlinkEvents(): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);

        $unlinked = 0;
        foreach (EntityUserRename::get('`' . EntityUserRename::event_id . '` IS NOT NULL') as $entity) {
            $id = (int)$entity->id;
            $rename = $this->objectCollection[$id] ?? ObjectUserRename::fromEntity($entity);
            $this->objectCollection[$id] = $rename;
            $rename->eventId = null;
            $rename->sync();
            $unlinked++;
        }

        return $unlinked;
    }
}
