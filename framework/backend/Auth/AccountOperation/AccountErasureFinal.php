<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountErasureFinalRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AccountErasureProgressResolver;
use JsonException;
use Throwable;

/**
 * The coordinator's side of the final in the erasure of an account (HIL-1942): the assignments that
 * ask each account's agent to end its account, the gate the final waits on, the refusals it leaves,
 * and the end of the operation once every account has its final.
 *
 * The person's agent runs its final ({@see AccountErasurePersonalFinal}) on an assignment keyed
 * {@see personalKey()} once every part of its account has a result, no wait for an owner stands
 * ({@see missingPartsOf()}) and no other account is folded into it. A final the database refused is
 * kept on the operation as its safe reason ({@see refused()}) until a repeat of the same final
 * passes; the operation ends ({@see end()}) only when every account of the circle has its final.
 * These are durable records of the operation, so they live in `Hilos::$db` and never in `Hilos::$rt`.
 * SCAFFOLD: no live caller until the erasure coordinator drives the final (HIL-1950), live at the switch (HIL-1952).
 */
final class AccountErasureFinal
{
    /** First part of every final's assignment key. */
    public const string FINAL_PREFIX = 'final';
    /** Key of the account in each refusal {@see refusalsOf()} lists. */
    public const string userId = 'userId';
    /** Key of the referencing table in each refusal {@see refusalsOf()} lists. */
    public const string table = 'table';
    private const string ASSIGNMENT_KEY_SEPARATOR = ':';

    /**
     * SCAFFOLD: no live caller until the erasure coordinator drives the final (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $userId Account of the erasure's circle whose agent runs the final
     * @return string Assignment key `final:personal:<owner agent id>:<user id>`
     * @throws EmptyValueException Never for the person's agent type; declared by the owner address
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the owner address
     */
    public static function personalKey(int $userId): string
    {
        return implode(self::ASSIGNMENT_KEY_SEPARATOR, [
            self::FINAL_PREFIX,
            AccountErasureParticipantCatalog::PERSONAL,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId)->agentId(),
            (string)$userId,
        ]);
    }

    /**
     * Hands out the finals of an open erasure: every account of the circle whose final assignment is
     * stored at no stage gets one at the current stage, written to the account's own agent. A repeat
     * records only what is missing.
     *
     * SCAFFOLD: no live caller until the erasure coordinator drives the final (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperation $operation Open erasure to hand the finals out on, at its current stage
     * @return list<AccountOperationAssignment> Final assignments written, in circle order
     * @throws ValidationException When the operation is not an open erasure
     * @throws Throwable When reading or recording fails; the assignments of this call roll back together
     */
    public static function recordMissing(AccountOperation $operation): array
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure records its finals.');
        }

        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): array {
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
                // The final names its participant exactly as the census payload does: {"participant":"personal"}.
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

            return $recorded;
        });
    }

    /**
     * The parts of one account the final still waits for: the participant of every expected owner of
     * the account with no result yet, and of every wait for an owner of the account that no expected
     * owner answers. The same predicate gates the final of the person's agent.
     *
     * SCAFFOLD: no live caller until the erasure coordinator reads the parts (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $operationId Erasure whose circle the account belongs to
     * @param int $userId Account whose parts are read
     * @return list<string> Participants still waited for, each once, in assignment id order; empty when none
     * @throws HilosException When the storage reader or item wrapper refuses a lookup
     * @throws JsonException When a stored payload cannot be decoded
     * @throws InvalidFormatException When a stored payload names no participant
     */
    public static function missingPartsOf(int $operationId, int $userId): array
    {
        $composition = AccountOperationExpectedSet::of($operationId);
        $missing = [];
        foreach ($composition->expected() as $assignment) {
            if ($assignment->userId === $userId && Hilos::$db->accountOperationResults->byAssignment($assignment->id) === null) {
                $missing[$assignment->id] = AccountOperationExpectation::participantOf($assignment->payload);
            }
        }
        foreach ($composition->waiting() as $assignment) {
            if ($assignment->userId === $userId) {
                $missing[$assignment->id] = AccountOperationExpectation::participantOf($assignment->payload);
            }
        }
        ksort($missing);

        return array_values(array_unique($missing));
    }

    /**
     * Whether the account's final has a result, at whatever stage it was assigned.
     *
     * SCAFFOLD: no live caller until the erasure coordinator reads the finals (HIL-1950), live at the switch (HIL-1952).
     *
     * @param int $operationId Erasure whose circle the account belongs to
     * @param int $userId Account whose final is read
     * @return bool Whether the account's agent has recorded its final
     * @throws HilosException When the storage reader or item wrapper refuses a lookup
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    public static function hasFinal(int $operationId, int $userId): bool
    {
        $key = self::personalKey($userId);
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operationId) as $assignment) {
            if ($assignment->assignmentKey === $key && Hilos::$db->accountOperationResults->byAssignment($assignment->id) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the safe reason of an account's refused final on its open erasure. The census is
     * reconciled first, in its own transaction, so a part that appeared since the opening - the
     * owner of the row that refused the final, say - is expected as well; then the reason is
     * written in a second one. A repeat for the same account replaces its reason. The session
     * holder calls it; the operation is its own to change.
     *
     * SCAFFOLD: no live caller until the erasure coordinator answers the refusal (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperation $operation Open erasure whose final was refused
     * @param AccountErasureParticipantCatalog $catalog Participants of the installation
     * @param AccountErasureFinalRefusedException $refusal The refusal the person's agent answered with
     * @return list<AccountOperationAssignment> Assignments written by the reconciliation, in census order
     * @throws ValidationException When the operation is not an open erasure, or the refusal names an account outside its circle
     * @throws Throwable When reading or writing fails; each of the two transactions rolls back whole
     */
    public static function refused(
        AccountOperation $operation,
        AccountErasureParticipantCatalog $catalog,
        AccountErasureFinalRefusedException $refusal,
    ): array {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure records a refused final.');
        }
        if (!$operation->circle->contains($refusal->userId)) {
            throw new ValidationException('A refused final must name an account of its erasure.');
        }
        $reconciled = AccountErasureOpening::reconcile($operation, $catalog);
        Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): bool => $operation->actions->noteFinalRefusal($refusal->userId, $refusal->table),
        );

        return $reconciled;
    }

    /**
     * Ends an open erasure once every account of its circle has its final; until then it writes
     * nothing. The check and the end run in one transaction.
     *
     * SCAFFOLD: no live caller until the erasure coordinator ends the operation (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperation $operation Open erasure to end, at its current stage
     * @return bool Whether this call ended the operation; false while an account has no final, or when
     *     the operation had already moved on
     * @throws ValidationException When the operation is not an open erasure
     * @throws Throwable When reading or writing fails; the transaction rolls back whole
     */
    public static function end(AccountOperation $operation): bool
    {
        if ($operation->kind !== AccountOperationKind::Erasure || $operation->finishedAt !== null) {
            throw new ValidationException('Only an open erasure ends.');
        }

        return Database::transaction(DatabaseNames::PRIMARY, static function () use ($operation): bool {
            foreach ($operation->circle->toArray() as $userId) {
                if (!self::hasFinal($operation->id, $userId)) {
                    return false;
                }
            }

            return $operation->actions->finish($operation->stage, AccountOperationOutcome::Completed);
        });
    }

    /**
     * The refused finals still standing on an erasure: the safe reason of every account of the
     * circle whose final was refused and has not passed since.
     *
     * The account card's erasure block reads them ({@see AccountErasureProgressResolver}).
     * SCAFFOLD: no refusal is written live until the erasure coordinator answers it (HIL-1950), live at the switch (HIL-1952).
     *
     * @param AccountOperation $operation Erasure whose refusals are read
     * @return list<array{userId: int, table: ?string}> Refusals in circle order; empty when none stands
     * @throws HilosException When the operation's stored refusals or a final lookup cannot be read
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    public static function refusalsOf(AccountOperation $operation): array
    {
        $refusals = $operation->finalRefusals;
        $standing = [];
        foreach ($operation->circle->toArray() as $userId) {
            if (array_key_exists($userId, $refusals) && !self::hasFinal($operation->id, $userId)) {
                $standing[] = [self::userId => $userId, self::table => $refusals[$userId]];
            }
        }

        return $standing;
    }
}
