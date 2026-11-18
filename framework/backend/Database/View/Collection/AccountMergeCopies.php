<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\AccountMergeCopiesActions;
use Hilos\Database\Object\Collection\AccountMergeCopies as ObjectAccountMergeCopies;
use Hilos\Database\View\Item\AccountMergeCopy;
use Hilos\HilosException;

/**
 * Array offsets are integer assignment ids; use isset or ?? null for optional copies.
 *
 * @extends DbCollection<AccountMergeCopy, ObjectAccountMergeCopies>
 * @property-read AccountMergeCopiesActions $actions
 * @method ObjectAccountMergeCopies|null getObjectCollection()
 * @method AccountMergeCopy|null first()
 * @method AccountMergeCopy|null last()
 * @method AccountMergeCopy offsetGet(mixed $offset)
 */
class AccountMergeCopies extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountMergeCopy::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountMergeCopies::class;

    /**
     * @param int $assignmentId Canonical give assignment whose private copy is checked
     * @return bool Whether current stored metadata still contains this copy
     * @throws HilosException When the mounted metadata query fails
     */
    public function hasStored(int $assignmentId): bool
    {
        return $this->objectCollection->hasStored($assignmentId);
    }
}
