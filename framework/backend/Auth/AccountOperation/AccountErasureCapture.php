<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Throwable;

/**
 * The coordinator's side of the capture in the erasure of an account (HIL-1940): the assignments
 * that ask an owner to write down, before it deletes them, what the owners after it will need.
 *
 * The person's agent captures its ways in and the project's part ({@see AccountErasurePersonalCapture})
 * on an assignment keyed {@see personalKey()}, and the owners that delete later read that capture
 * ({@see AccountErasurePersonalCaptureResult::of()}). The capture is written once and never copied:
 * no other assignment, result or log line carries an address. The channel owners get the numbers of
 * the person's notifications whose deliveries are theirs ({@see AccountErasureDeliveryCapture}), and
 * {@see recordAfterStart()} hands out both kinds.
 * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
 */
final class AccountErasureCapture
{
    /** First part of every capture's assignment key, which the delivery portions start with too. */
    public const string CAPTURE_PREFIX = 'capture';
    public const string ASSIGNMENT_KEY_SEPARATOR = ':';

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $userId Account of the erasure's circle whose agent captures
     * @return string Assignment key `capture:personal:<owner agent id>:<user id>`
     * @throws EmptyValueException Never for the person's agent type; declared by the owner address
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the owner address
     */
    public static function personalKey(int $userId): string
    {
        return implode(self::ASSIGNMENT_KEY_SEPARATOR, [
            self::CAPTURE_PREFIX,
            AccountErasureParticipantCatalog::PERSONAL,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId)->agentId(),
            (string)$userId,
        ]);
    }

    /**
     * Hands out the captures of an open erasure once its root request has started. The census is
     * reconciled first, so a channel that appeared after the opening is expected as well; then, in
     * a second transaction, every account of the circle whose personal capture is stored at no stage
     * gets one, and whatever delivery is not captured yet gets its portion
     * ({@see AccountErasureDeliveryCapture::recordMissing()}). The coordinator calls it right after
     * the start it won and again after a refusal for uncaptured deliveries; a repeat records only
     * what is missing. The start is read in the table, not in the coordinator's memory: the root's
     * agent commits it in its own worker, and the coordinator would learn of it only by sync.
     *
     * SCAFFOLD: no live caller until the erasure coordinator drives the capture (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperation $operation Open erasure to hand the captures out on, at its current stage
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationAssignment> Assignments written by the reconciliation, then by the capture, in order
     * @throws ValidationException When the operation is not an open erasure, or its request is not the root's begun one
     * @throws Throwable When reading or recording fails; each of the two transactions rolls back whole
     */
    public static function recordAfterStart(AccountOperation $operation, AccountErasureParticipantCatalog $catalog): array
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure captures its anchors.');
        }
        if (
            $operation->requestId === null
            || Hilos::$db->accountDeletions->startedOf($operation->rootUserId)?->id !== $operation->requestId
        ) {
            throw new ValidationException('An erasure captures its anchors only after its start.');
        }
        $reconciled = AccountErasureOpening::reconcile($operation, $catalog);

        return [...$reconciled, ...Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): array {
            $keys = [];
            foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
                $keys[$assignment->assignmentKey] = true;
            }
            $recorded = [];
            foreach ($operation->circle->toArray() as $userId) {
                $key = self::personalKey($userId);
                if (isset($keys[$key])) {
                    continue;
                }
                // The capture names its participant exactly as the census payload does: {"participant":"personal"}.
                $personal = new AccountOperationExpectation(
                    AccountErasureParticipantCatalog::PERSONAL,
                    $userId,
                    new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
                );
                $recorded[] = Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id,
                    $operation->stage,
                    $key,
                    $personal->assignmentOwner(),
                    $userId,
                    $personal->payload(),
                );
            }

            return [...$recorded, ...AccountErasureDeliveryCapture::recordMissing($operation)];
        })];
    }
}
