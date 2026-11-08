<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\AccountErasureOperations as ObjectAccountErasureOperations;
use Hilos\Database\View\Item\AccountErasureOperation;
use Hilos\Database\View\Collection\AccountErasureOperations as DbCollectionAccountErasureOperations;
use Hilos\HilosException;
use Hilos\Auth\AccountErasure\AccountErasureCircle;

/**
 * @extends DbActions<AccountErasureOperation, ObjectAccountErasureOperations>
 * @property-read DbCollectionAccountErasureOperations $collection
 * @property-read ObjectAccountErasureOperations $objectCollection
 */
class AccountErasureOperationsActions extends DbActions
{
    /**
     * @param int $requestId Original deletion request id
     * @param int $rootUserId Immutable root account id
     * @param AccountErasureCircle $circle Checked fixed account order
     * @return AccountErasureOperation Created row or an exact prior record
     * @throws HilosException When ownership, storage, validation or wrapping refuses the record
     */
    public function record(int $requestId, int $rootUserId, AccountErasureCircle $circle): AccountErasureOperation
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->record($requestId, $rootUserId, $circle));
    }
}
