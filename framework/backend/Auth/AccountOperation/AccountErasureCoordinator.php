<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Throwable;

/**
 * The session holder's erasure transitions, selected from assignments and their saved results.
 * Participants carry their own work and record their own results. The coordinator records duties,
 * reconciles refusals and returns the assignments to send, keeping the operation at its current stage.
 * SCAFFOLD: no live caller until the erasure entry (HIL-1952) or recovery passes (HIL-1951).
 */
final class AccountErasureCoordinator
{
    /**
     * Enters an already open erasure, resending every ready assignment that has no result.
     * The root first gets its start; a lost start ends the operation without capturing or finalizing.
     * Saved finals also end an operation whose last answer was lost, before reading its completed request.
     *
     * @param AccountOperation $operation Open erasure owned by the caller
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationAssignment> Ready assignments in primary id order, or just the root's start
     * @throws ValidationException When the operation is not an open erasure or its start belongs to another stage
     * @throws Throwable When reading or writing fails; each helper keeps its own transaction boundary
     */
    public static function begin(AccountOperation $operation, AccountErasureParticipantCatalog $catalog): array
    {
        self::requireOpen($operation);
        $key = AccountErasureStart::personalKey($operation->rootUserId);
        $start = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, $key)
            ?? AccountErasureStart::recordMissing($operation);
        if ($start === null) {
            throw new ValidationException('The erasure start must belong to its current stage.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($start->id);
        if ($result === null) {
            return [$start];
        }
        if (!AccountErasureStartResult::startedOf($result->payload)) {
            Database::transaction(
                DatabaseNames::PRIMARY,
                static fn (): bool => $operation->actions->finish($operation->stage, AccountOperationOutcome::NotStarted),
            );

            return [];
        }
        if (AccountErasureFinal::end($operation)) {
            return [];
        }
        AccountErasureCapture::recordAfterStart($operation, $catalog);
        AccountErasureFinal::recordMissing($operation);

        return self::ready($operation, Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, $operation->stage));
    }

    /**
     * Reacts to an answer already correlated by the holder to this operation, stage and assignment.
     * A done answer selects only its direct successors, and finals after a final. A refusal that
     * grows the census selects only the new duties; uncaptured retries the same duty only when new
     * assignments were recorded. Other refusals leave the duty for a later recovery pass.
     *
     * @param AccountOperation $operation Open erasure owned by the caller
     * @param AccountOperationAssignment $answered Assignment the holder accepted the answer for
     * @param AccountOperationAnswerSignalData $answer Participant's answer, without business data
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @return list<AccountOperationAssignment> Ready successors or the one retry, without recording participant results
     * @throws ValidationException When the operation is not an open erasure or a reconciliation refuses its facts
     * @throws Throwable When reading or writing fails; each helper keeps its own transaction boundary
     */
    public static function afterAnswer(
        AccountOperation $operation,
        AccountOperationAssignment $answered,
        AccountOperationAnswerSignalData $answer,
        AccountErasureParticipantCatalog $catalog,
    ): array {
        self::requireOpen($operation);
        if ($answer->answer === AccountOperationAnswerKind::Uncaptured) {
            return AccountErasureCapture::recordAfterStart($operation, $catalog) !== [] ? [$answered] : [];
        }
        if ($answer->answer === AccountOperationAnswerKind::FinalRefused) {
            return self::ready($operation, AccountErasureFinal::refused(
                $operation,
                $catalog,
                $answer->refusedUserId,
                $answer->refusedTable,
            ));
        }
        if ($answer->answer !== AccountOperationAnswerKind::Done
            || Hilos::$db->accountOperationResults->byAssignment($answered->id) === null) {
            return [];
        }
        if ($answered->assignmentKey === AccountErasureStart::personalKey($operation->rootUserId)) {
            return self::begin($operation, $catalog);
        }
        $isFinal = $answered->userId !== null && $answered->assignmentKey === AccountErasureFinal::personalKey($answered->userId);
        if ($isFinal && AccountErasureFinal::end($operation)) {
            return [];
        }

        return self::ready($operation, self::successors($operation, $answered, $isFinal));
    }

    /**
     * @param AccountOperation $operation Operation to enter or advance
     * @throws ValidationException When the operation is not an open erasure
     */
    private static function requireOpen(AccountOperation $operation): void
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure has coordinator transitions.');
        }
    }

    /**
     * @param AccountOperation $operation Erasure whose current duties are considered
     * @param AccountOperationAssignment $answered Completed assignment
     * @param bool $isFinal Whether the completed assignment is an account's final
     * @return list<AccountOperationAssignment> Only the transition's candidates, in primary id order
     */
    private static function successors(AccountOperation $operation, AccountOperationAssignment $answered, bool $isFinal): array
    {
        $isCapture = $answered->userId !== null
            && $answered->assignmentKey === AccountErasureCapture::personalKey($answered->userId);
        $isPersonal = AccountOperationExpectation::isExpectKey($answered->assignmentKey)
            && AccountOperationExpectation::participantOf($answered->payload) === AccountErasureParticipantCatalog::PERSONAL;
        $successors = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperationStage($operation->id, $operation->stage) as $assignment) {
            if ($assignment->userId === null) {
                continue;
            }
            if ($assignment->assignmentKey === AccountErasureFinal::personalKey($assignment->userId)) {
                if ($isFinal || $assignment->userId === $answered->userId) {
                    $successors[] = $assignment;
                }
                continue;
            }
            if ($isFinal || $assignment->userId !== $answered->userId || !AccountOperationExpectation::isExpectKey($assignment->assignmentKey)) {
                continue;
            }
            $participant = AccountOperationExpectation::participantOf($assignment->payload);
            if (($isCapture && self::needsPersonalCapture($participant)) || ($isPersonal && self::needsPersonalPart($participant))) {
                $successors[] = $assignment;
            }
        }

        return $successors;
    }

    /**
     * @param AccountOperation $operation Erasure whose saved prerequisites are read
     * @param iterable<AccountOperationAssignment> $assignments Candidates selected by the entry or transition
     * @return list<AccountOperationAssignment> Current candidates whose gates are open and which have no result
     */
    private static function ready(AccountOperation $operation, iterable $assignments): array
    {
        $start = self::resultForKey($operation, AccountErasureStart::personalKey($operation->rootUserId));
        $started = $start !== null && AccountErasureStartResult::startedOf($start->payload);
        $ready = [];
        foreach ($assignments as $assignment) {
            if ($assignment->stage !== $operation->stage || Hilos::$db->accountOperationResults->byAssignment($assignment->id) !== null) {
                continue;
            }
            if (self::isReady($operation, $assignment, $started)) {
                $ready[] = $assignment;
            }
        }

        return $ready;
    }

    /**
     * @param AccountOperation $operation Erasure whose gates are read
     * @param AccountOperationAssignment $assignment Current duty with no result
     * @param bool $started Whether the root's saved start won
     * @return bool Whether this duty's prerequisites are saved
     */
    private static function isReady(AccountOperation $operation, AccountOperationAssignment $assignment, bool $started): bool
    {
        if ($assignment->assignmentKey === AccountErasureStart::personalKey($operation->rootUserId)) {
            return true;
        }
        $userId = $assignment->userId;
        if ($userId === null) {
            return false;
        }
        if ($assignment->assignmentKey === AccountErasureCapture::personalKey($userId)) {
            return $started;
        }
        if ($assignment->assignmentKey === AccountErasureFinal::personalKey($userId)) {
            return AccountErasureFinal::missingPartsOf($operation->id, $userId) === [] && count(Hilos::$db->userMerges->foldedInto($userId)) === 0;
        }
        if (!AccountOperationExpectation::isExpectKey($assignment->assignmentKey)) {
            return false;
        }
        $participant = AccountOperationExpectation::participantOf($assignment->payload);
        if (in_array($participant, [AccountErasureParticipantCatalog::SESSIONS_HOLDER, AccountErasureParticipantCatalog::DATA_EXPORT], true)) {
            return $started;
        }
        if (self::needsPersonalPart($participant)) {
            $personal = new AccountOperationExpectation(
                AccountErasureParticipantCatalog::PERSONAL,
                $userId,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
            );

            return self::resultForKey($operation, $personal->assignmentKey()) !== null;
        }

        return self::resultForKey($operation, AccountErasureCapture::personalKey($userId)) !== null;
    }

    /**
     * @param string $participant Participant key from the assignment's payload
     * @return bool Whether the participant follows the person's capture
     */
    private static function needsPersonalCapture(string $participant): bool
    {
        return !in_array($participant, [AccountErasureParticipantCatalog::SESSIONS_HOLDER, AccountErasureParticipantCatalog::DATA_EXPORT], true)
            && !self::needsPersonalPart($participant);
    }

    /**
     * @param string $participant Participant key from the assignment's payload
     * @return bool Whether the participant follows the person's part
     */
    private static function needsPersonalPart(string $participant): bool
    {
        return $participant === AccountErasureParticipantCatalog::LEGAL_EXPORT
            || str_starts_with($participant, AccountErasureDeliveryParticipant::KEY . AccountErasureDeliveryParticipant::CHANNEL_SEPARATOR);
    }

    /**
     * @param AccountOperation $operation Erasure whose prerequisite is read
     * @param string $key Exact key of the prerequisite, without splitting its owner or participant
     * @return ?AccountOperationResult Saved result of that current-stage duty, or null
     */
    private static function resultForKey(AccountOperation $operation, string $key): ?AccountOperationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, $key);

        return $assignment === null ? null : Hilos::$db->accountOperationResults->byAssignment($assignment->id);
    }
}
