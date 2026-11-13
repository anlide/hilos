<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * Writes the expected composition of one account operation: the expectations a census names that
 * the operation does not carry yet.
 */
final class AccountOperationComposition
{
    /**
     * Records every expectation the operation does not carry: a key already stored at any stage is
     * skipped, and so is a wait whose participant and account already have an owner expected. Runs
     * inside the caller's transaction.
     *
     * @param AccountOperation $operation Operation to record on, at its current stage
     * @param list<AccountOperationExpectation> $expectations What the operation expects, in the order to record
     * @return list<AccountOperationAssignment> Assignments written, in the order given
     * @throws JsonException When a payload cannot be encoded or a stored one decoded
     * @throws HilosException When a reader or the record refuses
     */
    public static function recordMissing(AccountOperation $operation, array $expectations): array
    {
        $keys = [];
        $expected = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            $keys[$assignment->assignmentKey] = true;
            if (AccountOperationExpectation::isExpectKey($assignment->assignmentKey)) {
                $expected[AccountOperationExpectation::participantOf($assignment->payload)][$assignment->userId] = true;
            }
        }
        $recorded = [];
        foreach ($expectations as $expectation) {
            $key = $expectation->assignmentKey();
            if (isset($keys[$key]) || ($expectation->isWait() && isset($expected[$expectation->participantKey][$expectation->userId]))) {
                continue;
            }
            $recorded[] = Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id,
                $operation->stage,
                $key,
                $expectation->assignmentOwner(),
                $expectation->userId,
                $expectation->payload(),
            );
            $keys[$key] = true;
            if (!$expectation->isWait()) {
                $expected[$expectation->participantKey][$expectation->userId] = true;
            }
        }

        return $recorded;
    }
}
