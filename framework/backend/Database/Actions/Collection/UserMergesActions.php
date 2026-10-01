<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\UserMerges as ObjectUserMerges;
use Hilos\Database\View\Collection\UserMerges as DbCollectionUserMerges;
use Hilos\Database\View\Item\UserMerge;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserMergesActions - write operations for the framework merge table.
 *
 * A merge row is written once and never edited, so there are no item actions: a row is added
 * by the merge that folds the account and removed when the folded account itself is erased.
 *
 * @extends DbActions<UserMerge, ObjectUserMerges>
 * @property-read DbCollectionUserMerges $collection
 * @property-read ObjectUserMerges $objectCollection
 */
class UserMergesActions extends DbActions
{
    /**
     * Records that one account was folded into another, now.
     *
     * The row is keyed by the folded account, so a second merge of the same account is refused
     * by the database rather than recorded twice.
     *
     * @param int $userId Folded account
     * @param int $survivorUserId Account it was folded into
     * @return UserMerge Created merge row
     * @throws HilosException On database or truth-source failure
     */
    public function add(int $userId, int $survivorUserId): UserMerge
    {
        $this->ensureCanCreateInSet((string)$userId);

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $merge = $objectClass::create();
        $merge->userId = $userId;
        $merge->survivorUserId = $survivorUserId;
        $merge->mergedAt = TimeHelper::getSqlDateTime();
        $merge->sync();

        $this->addObjectToCollection($merge);

        return $this->createDbItemFromObject($merge);
    }

    /**
     * Deletes the merge row of a folded account - the account is being erased (HIL-302).
     *
     * The row leaves through its object, so every reader hears the delete. It restricts the
     * delete of the person's row, so it goes first. Rows where the person is the survivor are
     * not touched here: erasure removes the folded accounts before their survivor (HIL-1200).
     * An account never folded has no row, which is not an error.
     *
     * @param int $userId Folded account being erased
     * @throws HilosException On database or truth-source failure
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $merge = $this->objectCollection[$userId];
        if ($merge === null) {
            return;
        }

        $merge->delete();
        unset($this->objectCollection[$userId]);
    }
}
