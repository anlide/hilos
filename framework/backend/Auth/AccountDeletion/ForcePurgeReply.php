<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectParticipant;
use Hilos\Auth\AccountOperation\AccountErasureProjectResult;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountErasure;
use Hilos\Users\AccountErasureProgressResolver;
use JsonException;

/**
 * The reply of {@see CliCommands::ACCOUNT_TEST_FORCE_PURGE} read from the erasure's facts at this moment.
 *
 * Only a finished erasure says it was carried out; an open one names the stage it reached, with
 * the same progress block the people card reads (HIL-1953).
 * SCAFFOLD: no live caller until the force-purge entry switches (HIL-1952).
 */
final class ForcePurgeReply
{
    /**
     * @param string $correlationId Command being answered
     * @param AccountOperation $operation Erasure the command watches, as read now
     * @return CommandReplyDTO Completed with the rows erased, canceled with the earlier path's words, or the stage reached
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws InvalidJsonException When a stored expectation payload cannot be decoded
     * @throws JsonException When a saved result or expectation payload cannot be decoded
     * @throws InvalidFormatException When a saved result or expectation payload has another shape
     * @throws InvalidTopologyException When the project's account data owner directory is malformed
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    public static function of(string $correlationId, AccountOperation $operation): CommandReplyDTO
    {
        $userId = $operation->rootUserId;
        if ($operation->finishedAt !== null && $operation->outcome === AccountOperationOutcome::Completed->value) {
            return CommandReplyDTO::ok($correlationId, [
                AccountDeletionCommandConstants::FIELD_USER_ID => $userId,
                AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Completed->value,
                AccountDeletionCommandConstants::FIELD_ROWS_ERASED => self::rowsOf($operation),
            ]);
        }
        if ($operation->finishedAt !== null) {
            return CommandReplyDTO::error($correlationId, "The deletion of user {$userId} was canceled before it could be carried out");
        }
        $progress = AccountErasureProgressResolver::of($userId);
        if ($progress === null) {
            return CommandReplyDTO::ok($correlationId, [
                AccountDeletionCommandConstants::FIELD_USER_ID => $userId,
                AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Starting->value,
            ]);
        }

        return CommandReplyDTO::ok($correlationId, [
            AccountDeletionCommandConstants::FIELD_USER_ID => $userId,
            AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Erasing->value,
            AccountDeletionCommandConstants::FIELD_ERASURE => $progress->toArray(),
        ]);
    }

    /**
     * Sums the saved results of the personal part and of the project owners' parts.
     *
     * @param AccountOperation $operation Completed erasure
     * @return array<string, int> Rows erased per family, only from what was actually erased
     * @throws HilosException When a DB reader or item wrapper refuses the lookup
     * @throws JsonException When a saved result or expectation payload cannot be decoded
     * @throws InvalidFormatException When a saved result or expectation payload has another shape
     * @throws InvalidTopologyException When the project's account data owner directory is malformed
     */
    private static function rowsOf(AccountOperation $operation): array
    {
        $counted = [AccountErasureParticipantCatalog::PERSONAL];
        foreach (AccountErasureParticipantCatalog::installation()->participants() as $participant) {
            if ($participant instanceof AccountErasureProjectParticipant) {
                $counted[] = $participant->key();
            }
        }
        $erasure = new AccountErasure([], []);
        foreach (Hilos::$db->accountOperationResults->forOperationStage($operation->id, $operation->stage) as $result) {
            $assignment = $result->assignment;
            if ($assignment !== null && AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && in_array(AccountOperationExpectation::participantOf($assignment->payload), $counted, true)) {
                $erasure = $erasure->plus(AccountErasureProjectResult::erasureOf($result->payload));
            }
        }

        return $erasure->rowsErased;
    }
}
