<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Collection\AccountOperations as DbCollectionAccountOperations;
use Hilos\HilosException;
use Hilos\Auth\AccountOperation\AccountOperationCircle;

/**
 * @extends DbActions<AccountOperation, ObjectAccountOperations>
 * @property-read DbCollectionAccountOperations $collection
 * @property-read ObjectAccountOperations $objectCollection
 */
class AccountOperationsActions extends DbActions
{
    /**
     * @param int $requestId Original deletion request id
     * @param int $rootUserId Immutable root account id
     * @param AccountOperationCircle $circle Checked fixed account order
     * @return AccountOperation Created row or an exact prior record
     * @throws HilosException When ownership, storage, validation or wrapping refuses the record
     */
    public function recordErasure(int $requestId, int $rootUserId, AccountOperationCircle $circle): AccountOperation
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->recordErasure($requestId, $rootUserId, $circle));
    }

    /**
     * @param int $survivorUserId Immutable survivor account id
     * @param int $loserUserId Immutable loser account id
     * @param AccountMergeChoice $choice Immutable fates and supplied protection flags
     * @return AccountOperation New operation or its exact open predecessor
     * @throws HilosException When ownership, storage, validation or wrapping refuses the record
     */
    public function recordMerge(int $survivorUserId, int $loserUserId, AccountMergeChoice $choice): AccountOperation
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->recordMerge($survivorUserId, $loserUserId, $choice));
    }
}
