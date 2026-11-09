<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\AccountOperationAssignments as ObjectAccountOperationAssignments;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Collection\AccountOperationAssignments as DbCollectionAccountOperationAssignments;
use Hilos\HilosException;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;

/**
 * @extends DbActions<AccountOperationAssignment, ObjectAccountOperationAssignments>
 * @property-read DbCollectionAccountOperationAssignments $collection
 * @property-read ObjectAccountOperationAssignments $objectCollection
 */
class AccountOperationAssignmentsActions extends DbActions
{
    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @param AccountOperationOwnerAddress $owner Stable participant address
     * @param ?int $userId Fixed-circle account, or null for the whole circle
     * @param AccountOperationAssignmentPayload $payload Immutable JSON object
     * @return AccountOperationAssignment Created row or an exact prior record
     * @throws HilosException When ownership, storage, validation or wrapping refuses the record
     */
    public function record(
        int $operationId,
        string $stage,
        string $assignmentKey,
        AccountOperationOwnerAddress $owner,
        ?int $userId,
        AccountOperationAssignmentPayload $payload,
    ): AccountOperationAssignment {
        $this->ensureCanCreateInSet((string)$operationId);

        return $this->createDbItemFromObject($this->objectCollection->record(
            $operationId,
            $stage,
            $assignmentKey,
            $owner,
            $userId,
            $payload,
        ));
    }
}
