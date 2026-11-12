<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use Throwable;

/**
 * Opens the erasure of a live deletion request with its expected composition in one transaction,
 * and reconciles an open erasure by recording what the census expects and the operation does not
 * carry yet. Neither path deletes or rewrites an assignment.
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952); the
 * reconciliation is called by the start, the passes and the refusals (HIL-1951, HIL-1942,
 * HIL-1950, HIL-1940).
 */
final class AccountErasureOpening
{
    /**
     * A repeat for the same request records nothing new unless the census grew. An account of the
     * circle already in another open erasure defers the opening without writing anything.
     *
     * @param AccountDeletion $request Deletion request the erasure answers
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return AccountErasureOpeningResult Opened or recorded erasure with the assignments written, or the covering erasure's id
     * @throws ValidationException When the request is no longer live and has no erasure yet
     * @throws Throwable When reading or recording fails; the operation and its assignments roll back together
     */
    public static function open(AccountDeletion $request, AccountErasureParticipantCatalog $catalog): AccountErasureOpeningResult
    {
        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($request, $catalog): AccountErasureOpeningResult {
            $existing = Hilos::$db->accountOperations->byRequest($request->id);
            if ($existing !== null) {
                return new AccountErasureOpeningResult($existing, null, self::recordMissing($existing, $catalog));
            }
            if (!$request->isLive()) {
                throw new ValidationException('The deletion request is no longer live.');
            }
            $circle = Hilos::$db->userMerges->erasureCircleOf($request->userId);
            foreach ($circle->toArray() as $userId) {
                $covering = Hilos::$db->accountOperations->openErasureCovering($userId);
                if ($covering !== null) {
                    return new AccountErasureOpeningResult(null, $covering->id, []);
                }
            }
            $operation = Hilos::$db->accountOperations->actions->recordErasure($request->id, $request->userId, $circle);

            return new AccountErasureOpeningResult($operation, null, self::recordMissing($operation, $catalog));
        });
    }

    /**
     * @param AccountOperation $operation Open erasure to reconcile
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationAssignment> Assignments written at the operation's current stage, in census order
     * @throws ValidationException When the operation is not an erasure or is already finished
     * @throws Throwable When reading or recording fails; the assignments of this call roll back together
     */
    public static function reconcile(AccountOperation $operation, AccountErasureParticipantCatalog $catalog): array
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure can be reconciled.');
        }

        return Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): array => self::recordMissing($operation, $catalog),
        );
    }

    /**
     * Records every census expectation the operation does not carry: a key already stored at any
     * stage is skipped, and so is a wait whose participant and account already have an owner
     * expected. Runs inside the caller's transaction.
     *
     * @param AccountOperation $operation Erasure to record on, at its current stage
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationAssignment> Assignments written, in census order
     * @throws JsonException When a payload cannot be encoded or a stored one decoded
     * @throws HilosException When the census, a reader or the record refuses
     */
    private static function recordMissing(AccountOperation $operation, AccountErasureParticipantCatalog $catalog): array
    {
        $keys = [];
        $expected = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            $keys[$assignment->assignmentKey] = true;
            if (AccountErasureExpectation::isExpectKey($assignment->assignmentKey)) {
                $expected[AccountErasureExpectation::participantOf($assignment->payload)][$assignment->userId] = true;
            }
        }
        $recorded = [];
        foreach (AccountErasureCensus::of($operation->circle, $catalog) as $expectation) {
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
