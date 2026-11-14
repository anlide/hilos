<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Throwable;

/**
 * The first write of the person's agent in the erasure of an account: its deletion request marked begun (HIL-1939).
 *
 * Called by the person's agent in its own frame. The root account's agent carries the start as its
 * participant step ({@see run()}): inside the step's transaction it marks the deletion request the
 * erasure answers begun - against "Keep my account", of which exactly one wins - and the answer,
 * started or lost ({@see AccountErasureStartResult}), is recorded as the result in the same
 * transaction. A repeated call returns the saved result without starting again. The agent of an
 * account folded into the root marks its own standing request begun as the first write of its
 * part, inside that part's transaction ({@see startOwnRequest()}). Neither sends a frame: the
 * coordinator assigns the step at its current stage through recordMissing().
 * SCAFFOLD: the production erasure entry opens in HIL-1952.
 */
final class AccountErasureStart
{
    /** First part of the root account's start assignment key. */
    public const string START_PREFIX = 'start';
    public const string ASSIGNMENT_KEY_SEPARATOR = ':';

    /**
     * @param int $userId Root account whose agent starts the deletion request
     * @return string Assignment key `start:personal:<owner agent id>:<user id>`
     * @throws EmptyValueException Never for the person's agent type; declared by the owner address
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the owner address
     */
    public static function personalKey(int $userId): string
    {
        return implode(self::ASSIGNMENT_KEY_SEPARATOR, [
            self::START_PREFIX,
            AccountErasureParticipantCatalog::PERSONAL,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId)->agentId(),
            (string)$userId,
        ]);
    }

    /**
     * Records the root's start at the current stage, unless its key is already stored at any stage.
     *
     * SCAFFOLD: no live caller until the erasure entry (HIL-1952) or recovery passes (HIL-1951).
     *
     * @param AccountOperation $operation Open erasure whose root is to start
     * @return ?AccountOperationAssignment New start assignment, or null on a repeat
     * @throws ValidationException When the operation is not an open erasure
     * @throws Throwable When reading or recording fails; the transaction rolls back whole
     */
    public static function recordMissing(AccountOperation $operation): ?AccountOperationAssignment
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure records its start.');
        }

        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): ?AccountOperationAssignment {
            $key = self::personalKey($operation->rootUserId);
            foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
                if ($assignment->assignmentKey === $key) {
                    return null;
                }
            }
            $personal = new AccountOperationExpectation(
                AccountErasureParticipantCatalog::PERSONAL,
                $operation->rootUserId,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->rootUserId),
            );

            return Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id,
                $operation->stage,
                $key,
                $personal->assignmentOwner(),
                $operation->rootUserId,
                $personal->payload(),
            );
        });
    }

    /**
     * @param int $assignmentId Assignment the session holder gave the root account's agent
     * @return AccountOperationResult Committed result or the saved outcome of an earlier attempt
     * @throws AccountOperationStepRefusedException When the caller, assignment or current stage refuses new work
     * @throws ValidationException When the operation is no erasure, the assignment is not its root
     *     account's, or the erasure names no deletion request of its root account
     * @throws Throwable When storage or the start fails, preserving the original failure
     */
    public static function run(int $assignmentId): AccountOperationResult
    {
        return AccountOperationParticipantStep::run(
            $assignmentId,
            static function (AccountOperationAssignment $assignment): AccountOperationResultPayload {
                $operation = $assignment->operation;
                if ($operation?->kind !== AccountOperationKind::Erasure) {
                    throw new ValidationException('An account erasure start must belong to an erasure.');
                }
                if ($assignment->userId !== $operation->rootUserId) {
                    throw new ValidationException('Only the root account starts its erasure request.');
                }
                $request = Hilos::$db->accountDeletions[$operation->requestId] ?? null;
                if ($request === null || $request->userId !== $operation->rootUserId) {
                    throw new ValidationException('The erasure names no deletion request of its root account.');
                }

                return AccountErasureStartResult::payloadOf($request->actions->start());
            },
        );
    }

    /**
     * Marks the person's standing deletion request begun, as the first write of the person's part.
     *
     * A person with no standing request gets none: the answer is false and nothing is written. The
     * account is erased whatever the answer, because its circle is fixed already.
     *
     * @param int $userId Person whose agent runs its part of the erasure
     * @return bool True only when this call began the standing request
     * @throws DatabaseException When no transaction is open, or the lookup, the update or the re-announcement fails
     * @throws InvalidArgumentException When the lookup is invalid or the queued DB-sync signal cannot be named
     * @throws LogicException When the deletion collection class constants are not configured
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws WriteNotAllowedException When the caller does not own the person's deletion requests
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     */
    public static function startOwnRequest(int $userId): bool
    {
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('An account erasure start must run inside its participant transaction');
        }
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        if ($request === null) {
            return false;
        }

        return $request->actions->start();
    }
}
