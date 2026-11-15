<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\Database\View\Item\PasskeyCredential;
use Hilos\HilosException;

/**
 * Writes for one persisted passkey credential.
 *
 * @extends DbActions<PasskeyCredential, ObjectPasskeyCredential>
 * @property-read ObjectPasskeyCredential $object
 */
class PasskeyCredentialActions extends DbActions
{
    /**
     * Gives this selected credential after its captured identity has been saved at the survivor.
     *
     * @param AccountMergeTransfer $transfer Active give capability
     * @throws HilosException When the row, capability, anchor or scoped save refuses the transfer
     */
    public function transferForMerge(AccountMergeTransfer $transfer): void
    {
        $this->transferSelectedForMerge($transfer);
    }
}
