<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Hilos\Database\View\Item\UserRename;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserRenamesActions - write operations for the framework rename journal.
 *
 * A journal row is written once and never edited, so there are no item actions: the rows are
 * added one per rename and removed with the renamed person.
 *
 * @extends DbActions<UserRename, ObjectUserRenames>
 * @property-read DbCollectionUserRenames $collection
 * @property-read ObjectUserRenames $objectCollection
 */
class UserRenamesActions extends DbActions
{
    /**
     * Records one rename of a person.
     *
     * @param int $userId Renamed person
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @param string $oldName Name before the rename
     * @param string $newName Name after the rename
     * @return UserRename Created journal row
     * @throws HilosException On database or truth-source failure
     */
    public function add(int $userId, ?int $renamedByUserId, string $oldName, string $newName): UserRename
    {
        $this->ensureCanCreateInSet((string)$userId);

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $rename = $objectClass::create();
        $rename->userId = $userId;
        $rename->renamedByUserId = $renamedByUserId;
        $rename->oldName = $oldName;
        $rename->newName = $newName;
        $rename->renamedAt = TimeHelper::getSqlDateTime();
        $rename->sync();

        $this->addObjectToCollection($rename);

        return $this->createDbItemFromObject($rename);
    }

    /**
     * Deletes every rename row of a person - the account is being erased (HIL-302).
     *
     * Each row leaves through its object, so every reader hears the delete. The rows restrict
     * the delete of the person's row, so they go first. Rows where the person is only the
     * author are not touched: the database clears that reference when the person's row goes.
     *
     * @param int $userId Renamed person
     * @return int Number of journal rows deleted
     * @throws HilosException On database or truth-source failure
     */
    public function deleteByUser(int $userId): int
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $deleted = 0;
        foreach ($this->objectCollection->loadByColumn(ObjectUserRename::userId, $userId) as $id) {
            $this->objectCollection[$id]?->delete();
            unset($this->objectCollection[$id]);
            $deleted++;
        }

        return $deleted;
    }
}
