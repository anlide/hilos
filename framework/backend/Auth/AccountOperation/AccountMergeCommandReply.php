<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\SecondFactorOutcome;
use JsonException;
use Throwable;

/**
 * The prepared merge reply read from the saved operation, without starting or advancing it.
 *
 * Summary is present only for a saved completed end. The cancel sentence is present only when
 * that end never started and names no business refusal; a refusal already carries its own reason.
 */
final class AccountMergeCommandReply
{
    /**
     * @param string $correlationId Command being answered
     * @param AccountOperation $operation Merge the command watches, as read now
     * @return CommandReplyDTO Saved progress, its summary when the merge completed, and the cancel sentence when that is the whole end
     * @throws InvalidFormatException When the operation is not a saved merge or a completed tally cannot be read
     * @throws Throwable When the saved merge cannot be read
     * @throws HilosException When a saved assignment or result cannot be read
     * @throws InvalidJsonException When the canonical give payload cannot be read
     * @throws JsonException When a saved project result cannot be decoded
     * @throws EmptyValueException When the canonical give owner address is empty
     * @throws ValueTooLongException When the canonical give owner address is too long
     */
    public static function of(string $correlationId, AccountOperation $operation): CommandReplyDTO
    {
        if ($operation->id === null || $operation->id <= 0 || $operation->kind !== AccountOperationKind::Merge) {
            throw new InvalidFormatException('A merge reply requires a saved merge.');
        }
        $progress = AccountMergeProgressResolver::forOperation($operation->id);
        if ($progress === null) {
            throw new InvalidFormatException('A merge reply requires a saved merge.');
        }
        $summary = $progress->state === AccountMergeProgress::END
            && $progress->outcome === AccountOperationOutcome::Completed->value
            ? self::completedSummary($progress)
            : null;
        $payload = [
            AccountMergeCommandConstants::FIELD_MERGE => $progress->toArray(),
            AccountMergeCommandConstants::FIELD_SUMMARY => $summary?->toArray(),
        ];
        if ($progress->outcome === AccountOperationOutcome::NotStarted->value && $progress->refusal === null) {
            $payload[CommandConstants::FIELD_MESSAGE] = 'The account merge was canceled before any accounts were merged.';
        }

        return CommandReplyDTO::ok($correlationId, $payload);
    }

    /**
     * Counts the saved give and the exact project results, including a preparing expectation carried onto go.
     *
     * Prepare, release, session and wait rows are outside that composition. The survivor's current
     * identities and the handover copy are not read again.
     *
     * @param AccountMergeProgress $progress Saved completed end
     * @return AccountMergeSummary Tallies taken only from the saved give and project results
     * @throws InvalidFormatException When the give, a project result, or a count is missing, foreign, negative or overflowing
     * @throws HilosException When a saved assignment or result cannot be read
     * @throws InvalidJsonException When the canonical give payload cannot be read
     * @throws JsonException When a saved project result cannot be decoded
     * @throws EmptyValueException When the canonical give owner address is empty
     * @throws ValueTooLongException When the canonical give owner address is too long
     */
    private static function completedSummary(AccountMergeProgress $progress): AccountMergeSummary
    {
        $loserOwner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$progress->loserUserId);
        $give = Hilos::$db->accountOperationAssignments->byIdentity(
            $progress->operationId,
            AccountMergeStage::Go->value,
            AccountMergeDuty::Give->value,
        );
        if ($give === null || $give->operationId !== $progress->operationId || $give->userId !== $progress->loserUserId
            || $give->stage !== AccountMergeStage::Go->value || !$give->owner->equals($loserOwner)
            || !$give->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))) {
            throw new InvalidFormatException('A completed merge summary requires the canonical give of the merged pair.');
        }
        $given = AccountMergeGiveResult::of(self::exactResult($give)->payload);
        $secondFactorKept = SecondFactorOutcome::tryFrom($given->secondFactorKept);
        if ($secondFactorKept === null) {
            throw new InvalidFormatException('A completed merge summary requires a known second-factor outcome.');
        }
        $rowsMoved = [];
        foreach (AccountOperationExpectedSet::of($progress->operationId)->expected() as $assignment) {
            if ($assignment->operationId !== $progress->operationId || $assignment->userId !== $progress->loserUserId) {
                throw new InvalidFormatException('A completed merge summary requires each project result on the merged pair.');
            }
            foreach (AccountMergeProjectResult::rowsMovedOf(self::exactResult($assignment)->payload) as $family => $moved) {
                $rowsMoved[$family] = self::addCount($rowsMoved[$family] ?? 0, $moved);
            }
        }

        return new AccountMergeSummary($given->identitiesMoved, $rowsMoved, $given->passwordKept, $secondFactorKept);
    }

    /**
     * @param AccountOperationAssignment $assignment Saved give or project expectation
     * @return AccountOperationResult Result bound to that assignment and its owner
     * @throws InvalidFormatException When the result is missing or bound to another assignment or owner
     * @throws HilosException When the result cannot be read
     */
    private static function exactResult(AccountOperationAssignment $assignment): AccountOperationResult
    {
        if ($assignment->id === null || $assignment->id <= 0) {
            throw new InvalidFormatException('A completed merge summary requires each saved result on its assignment and owner.');
        }
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($saved === null || $saved->assignmentId !== $assignment->id || $saved->ownerAgentId !== $assignment->owner->agentId()) {
            throw new InvalidFormatException('A completed merge summary requires each saved result on its assignment and owner.');
        }

        return $saved;
    }

    /**
     * @param int $sum Rows already counted for one family
     * @param int $moved Rows the next saved result adds for that family
     * @return int The combined count
     * @throws InvalidFormatException When the added count is negative or the sum overflows
     */
    private static function addCount(int $sum, int $moved): int
    {
        if ($moved < 0 || $sum > PHP_INT_MAX - $moved) {
            throw new InvalidFormatException('A completed merge summary cannot count a negative or overflowing number of rows.');
        }

        return $sum + $moved;
    }
}
