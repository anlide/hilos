<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Database\AccountMergeFenceSnapshot;
use Hilos\Database\Object\Item\AccountMergeFence as ObjectAccountMergeFence;
use Hilos\Database\View\Item\AccountMergeFence;
use Hilos\HilosException;

/**
 * @extends DbActions<AccountMergeFence, ObjectAccountMergeFence>
 * @property-read ObjectAccountMergeFence $object
 */
class AccountMergeFenceActions extends DbActions
{
    /**
     * @return AccountMergeFenceSnapshot Next revision with the same operation, assignment and owner
     * @throws HilosException When the transaction, current owner or optimistic comparison refuses the write
     */
    public function advanceForWrite(): AccountMergeFenceSnapshot
    {
        return $this->object->advanceForWrite();
    }

    /**
     * @param AccountMergeTransfer $transfer Active captured-row transfer capability
     * @return AccountMergeFenceSnapshot Next revision under the target owner
     * @throws HilosException When the transaction, capability, source grant or optimistic comparison refuses the write
     */
    public function giveForMerge(AccountMergeTransfer $transfer): AccountMergeFenceSnapshot
    {
        return $this->object->giveForMerge($transfer);
    }
}
