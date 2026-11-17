<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Database\Object\Collection\AccountMergeFences as ObjectAccountMergeFences;
use Hilos\Database\View\Collection\AccountMergeFences as DbCollectionAccountMergeFences;
use Hilos\Database\View\Item\AccountMergeFence;
use Hilos\HilosException;

/**
 * @extends DbActions<AccountMergeFence, ObjectAccountMergeFences>
 * @property-read DbCollectionAccountMergeFences $collection
 * @property-read ObjectAccountMergeFences $objectCollection
 */
class AccountMergeFencesActions extends DbActions
{
    /**
     * @param AccountMergeTransfer $transfer Active source transfer capability
     * @param string $collectionKey Captured business collection
     * @param int $rowId Captured row
     * @return AccountMergeFence New target-owned fence at revision one
     * @throws HilosException When the transaction, metadata source Add, capture or insert refuses birth
     */
    public function birthForMerge(AccountMergeTransfer $transfer, string $collectionKey, int $rowId): AccountMergeFence
    {
        return $this->createDbItemFromObject($this->objectCollection->birthForMerge($transfer, $collectionKey, $rowId));
    }
}
