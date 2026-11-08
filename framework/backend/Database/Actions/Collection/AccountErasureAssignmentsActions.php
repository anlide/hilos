<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\AccountErasureAssignments as ObjectAccountErasureAssignments;
use Hilos\Database\View\Item\AccountErasureAssignment;
use Hilos\Database\View\Collection\AccountErasureAssignments as DbCollectionAccountErasureAssignments;
use Hilos\HilosException;
use Hilos\Auth\AccountErasure\AccountErasureOwnerAddress;
use Hilos\Auth\AccountErasure\AccountErasureAssignmentPayload;

/**
 * @extends DbActions<AccountErasureAssignment, ObjectAccountErasureAssignments>
 * @property-read DbCollectionAccountErasureAssignments $collection
 * @property-read ObjectAccountErasureAssignments $objectCollection
 */
class AccountErasureAssignmentsActions extends DbActions
{
    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @param AccountErasureOwnerAddress $owner Stable participant address
     * @param ?int $userId Fixed-circle account, or null for the whole circle
     * @param AccountErasureAssignmentPayload $payload Immutable JSON object
     * @return AccountErasureAssignment Created row or an exact prior record
     * @throws HilosException When ownership, storage, validation or wrapping refuses the record
     */
    public function record(
        int $operationId,
        string $stage,
        string $assignmentKey,
        AccountErasureOwnerAddress $owner,
        ?int $userId,
        AccountErasureAssignmentPayload $payload,
    ): AccountErasureAssignment {
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
