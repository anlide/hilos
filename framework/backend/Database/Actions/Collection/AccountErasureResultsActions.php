<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccountErasure\AccountErasureResultPayload;
use Hilos\Auth\AccountErasure\Exception\AccountErasureResultRepeatedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountErasureResults as ObjectAccountErasureResults;
use Hilos\Database\View\Collection\AccountErasureResults as DbCollectionAccountErasureResults;
use Hilos\Database\View\Item\AccountErasureAssignment;
use Hilos\Database\View\Item\AccountErasureResult;
use Hilos\HilosException;

/**
 * @extends DbActions<AccountErasureResult, ObjectAccountErasureResults>
 * @property-read DbCollectionAccountErasureResults $collection
 * @property-read ObjectAccountErasureResults $objectCollection
 */
class AccountErasureResultsActions extends DbActions
{
    /**
     * Joins the participant's transaction; even an equal repeat first passes its set guard.
     * A contended unique insert escapes to the transaction owner without being retried here.
     *
     * @param AccountErasureAssignment $assignment Coordinator's immutable work assignment
     * @param AccountErasureResultPayload $payload Participant's immutable preparation or result
     * @return AccountErasureResult Saved result, including an exact repeat
     * @throws HilosException When the transaction, create right, immutable payload or storage refuses the record
     */
    public function record(AccountErasureAssignment $assignment, AccountErasureResultPayload $payload): AccountErasureResult
    {
        return $this->recordResult($assignment, $payload, allowRepeat: true);
    }

    /**
     * An equal saved result must roll back the participant work that preceded this call.
     *
     * @internal Used by the participant step inside its managed transaction
     * @param AccountErasureAssignment $assignment Coordinator's immutable work assignment
     * @param AccountErasureResultPayload $payload Participant's immutable preparation or result
     * @return AccountErasureResult Newly recorded result
     * @throws AccountErasureResultRepeatedException When an equal saved result requires the work to roll back
     * @throws HilosException When the transaction, create right, immutable payload or storage refuses the record
     */
    public function recordNew(AccountErasureAssignment $assignment, AccountErasureResultPayload $payload): AccountErasureResult
    {
        return $this->recordResult($assignment, $payload, allowRepeat: false);
    }

    /**
     * @param AccountErasureAssignment $assignment Coordinator's immutable work assignment
     * @param AccountErasureResultPayload $payload Participant's immutable preparation or result
     * @param bool $allowRepeat Whether an equal saved result may return without rolling back work
     * @return AccountErasureResult New result or an allowed equal repeat
     * @throws HilosException When the transaction, rights, payload, repeat policy or storage refuses the record
     */
    private function recordResult(
        AccountErasureAssignment $assignment,
        AccountErasureResultPayload $payload,
        bool $allowRepeat,
    ): AccountErasureResult
    {
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('An account erasure result must be recorded inside its participant transaction');
        }
        $this->ensureCanCreateInSet($assignment->owner->agentId());
        $existing = $this->collection->byAssignment($assignment->id);
        if ($existing !== null) {
            if (!$existing->payload->equals($payload)) {
                throw new ValidationException('This erasure assignment already has a different immutable result.');
            }
            if (!$allowRepeat) {
                throw new AccountErasureResultRepeatedException('An equal saved erasure result requires participant work to roll back');
            }

            return $existing;
        }

        return $this->createDbItemFromObject($this->objectCollection->record($assignment->id, $assignment->owner->agentId(), $payload));
    }
}
