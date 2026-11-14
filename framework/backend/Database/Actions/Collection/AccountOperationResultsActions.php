<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\Exception\AccountOperationResultRepeatedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountOperationResults as ObjectAccountOperationResults;
use Hilos\Database\View\Collection\AccountOperationResults as DbCollectionAccountOperationResults;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\HilosException;

/**
 * @extends DbActions<AccountOperationResult, ObjectAccountOperationResults>
 * @property-read DbCollectionAccountOperationResults $collection
 * @property-read ObjectAccountOperationResults $objectCollection
 */
class AccountOperationResultsActions extends DbActions
{
    /**
     * Joins the participant's transaction; even an equal repeat first passes its set guard.
     * A contended unique insert escapes to the transaction owner without being retried here.
     *
     * @param AccountOperationAssignment $assignment Coordinator's immutable work assignment
     * @param AccountOperationResultPayload $payload Participant's immutable preparation or result
     * @return AccountOperationResult Saved result, including an exact repeat
     * @throws HilosException When the transaction, create right, immutable payload or storage refuses the record
     */
    public function record(AccountOperationAssignment $assignment, AccountOperationResultPayload $payload): AccountOperationResult
    {
        return $this->recordResult($assignment, $payload, allowRepeat: true);
    }

    /**
     * An equal saved result must roll back the participant work that preceded this call.
     *
     * @internal Used by the participant step inside its managed transaction
     * @param AccountOperationAssignment $assignment Coordinator's immutable work assignment
     * @param AccountOperationResultPayload $payload Participant's immutable preparation or result
     * @return AccountOperationResult Newly recorded result
     * @throws AccountOperationResultRepeatedException When an equal saved result requires the work to roll back
     * @throws HilosException When the transaction, create right, immutable payload or storage refuses the record
     */
    public function recordNew(AccountOperationAssignment $assignment, AccountOperationResultPayload $payload): AccountOperationResult
    {
        return $this->recordResult($assignment, $payload, allowRepeat: false);
    }

    /**
     * Deletes the saved result of one assignment inside the participant transaction that ends what it kept -
     * the personal capture of an erasure goes in the final of its own agent (HIL-1942).
     *
     * The row leaves through its object, so every reader hears the delete. An assignment with no saved
     * result is not an error.
     *
     * @param int $assignmentId Assignment whose saved result goes
     * @return bool Whether a saved result was deleted
     * @throws DatabaseException When no transaction is open
     * @throws HilosException When the remove right over the owner's set, the lookup or storage refuses the delete
     */
    public function deleteForAssignment(int $assignmentId): bool
    {
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('An account operation result must be deleted inside its participant transaction');
        }
        $result = $this->objectCollection->byAssignment($assignmentId);
        if ($result === null) {
            return false;
        }
        $this->ensureCanWriteSet($result->ownerAgentId, TruthSourceOperation::Remove);
        $result->delete();
        unset($this->objectCollection[$result->id]);

        return true;
    }

    /**
     * @param AccountOperationAssignment $assignment Coordinator's immutable work assignment
     * @param AccountOperationResultPayload $payload Participant's immutable preparation or result
     * @param bool $allowRepeat Whether an equal saved result may return without rolling back work
     * @return AccountOperationResult New result or an allowed equal repeat
     * @throws HilosException When the transaction, rights, payload, repeat policy or storage refuses the record
     */
    private function recordResult(
        AccountOperationAssignment $assignment,
        AccountOperationResultPayload $payload,
        bool $allowRepeat,
    ): AccountOperationResult
    {
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('An account operation result must be recorded inside its participant transaction');
        }
        $this->ensureCanCreateInSet($assignment->owner->agentId());
        $existing = $this->collection->byAssignment($assignment->id);
        if ($existing !== null) {
            if (!$existing->payload->equals($payload)) {
                throw new ValidationException('This account operation assignment already has a different immutable result.');
            }
            if (!$allowRepeat) {
                throw new AccountOperationResultRepeatedException('An equal saved account operation result requires participant work to roll back');
            }

            return $existing;
        }

        return $this->createDbItemFromObject($this->objectCollection->record($assignment->id, $assignment->owner->agentId(), $payload));
    }
}
