<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * Reads erasure progress from DB facts, remembering even absence in this process.
 *
 * This memory has no date epoch: only its four DB sources can change the answer.
 * Their mirrors drop it at the write, including inside a transaction, through
 * {@see AccountErasureProgressChangeSubscriber}. It is derived memory, not shared RT state.
 * The card and its standing frame read one account's answer; the list of people reads every begun
 * erasure at once through {@see begun()} (HIL-1955). Live erasure stays inert until HIL-1952 opens its entry.
 */
final class AccountErasureProgressResolver
{
    /** @var array<int, ?AccountErasureProgress> Progress or remembered absence, keyed by account */
    private static array $progress = [];

    /** @var ?array<int, AccountErasureProgress> Every begun erasure's progress keyed by account, null until read */
    private static ?array $begun = null;

    /**
     * @param int $userId Any account in an erasure's fixed circle
     * @return ?AccountErasureProgress Begun open erasure, or null when none covers the account
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws InvalidJsonException When a stored expectation payload cannot be decoded
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    public static function of(int $userId): ?AccountErasureProgress
    {
        if (!array_key_exists($userId, self::$progress)) {
            try {
                self::$progress[$userId] = self::read($userId);
            } catch (JsonException $failure) {
                throw new InvalidJsonException('The account erasure expectation must be valid JSON.', previous: $failure);
            }
        }

        return self::$progress[$userId];
    }

    /**
     * Reads every begun, open erasure once and hands its progress to each account of its circle.
     *
     * @return array<int, AccountErasureProgress> Progress keyed by account, empty without a database or a begun erasure
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws InvalidJsonException When a stored expectation payload cannot be decoded
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    public static function begun(): array
    {
        if (self::$begun === null) {
            try {
                self::$begun = self::readBegun();
            } catch (JsonException $failure) {
                throw new InvalidJsonException('The account erasure expectation must be valid JSON.', previous: $failure);
            }
        }

        return self::$begun;
    }

    /** Drops all remembered answers when a source fact changes. */
    public static function forgetAll(): void
    {
        self::$progress = [];
        self::$begun = null;
    }

    /**
     * @param int $userId Account whose erasure is read
     * @return ?AccountErasureProgress Begun open erasure, or null before its start or without a database
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws JsonException When a stored expectation payload cannot be decoded
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    private static function read(int $userId): ?AccountErasureProgress
    {
        if (Hilos::$db === null) {
            return null;
        }
        $operation = Hilos::$db->accountOperations->openErasureCovering($userId);

        return $operation === null ? null : self::fromOperation($operation);
    }

    /**
     * Walks the open erasures page by page in primary id order, the way their covering lookup does.
     *
     * @return array<int, AccountErasureProgress> Progress of each begun erasure keyed by every account of its circle
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws JsonException When a stored expectation payload cannot be decoded
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    private static function readBegun(): array
    {
        if (Hilos::$db === null) {
            return [];
        }
        $begun = [];
        $afterId = 0;
        do {
            $page = Hilos::$db->accountOperations->openPage(
                AccountOperationKind::Erasure,
                $afterId,
                ObjectAccountOperations::PAGE_LIMIT_MAX,
            );
            foreach ($page as $operationId => $operation) {
                $afterId = (int) $operationId;
                $progress = self::fromOperation($operation);
                if ($progress === null) {
                    continue;
                }
                foreach ($operation->circle->toArray() as $userId) {
                    $begun[$userId] = $progress;
                }
            }
        } while (count($page) === ObjectAccountOperations::PAGE_LIMIT_MAX);

        return $begun;
    }

    /**
     * @param AccountOperation $operation Open erasure
     * @return ?AccountErasureProgress Its progress once its request has begun, null before
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws JsonException When a stored expectation payload cannot be decoded
     * @throws EmptyValueException Never for the person's agent type; declared by the final key
     * @throws ValueTooLongException Never for the person's agent type and an integer id; declared by the final key
     */
    private static function fromOperation(AccountOperation $operation): ?AccountErasureProgress
    {
        $request = $operation->requestId === null ? null : (Hilos::$db->accountDeletions[$operation->requestId] ?? null);
        if ($request?->startedAt === null) {
            return null;
        }

        $set = AccountOperationExpectedSet::of($operation->id);
        $parts = [];
        foreach ($set->expected() as $assignment) {
            if (isset($parts[$assignment->assignmentKey])) {
                continue;
            }
            $parts[$assignment->assignmentKey] = [
                AccountErasureProgress::participant => AccountOperationExpectation::participantOf($assignment->payload),
                AccountErasureProgress::owner => $assignment->owner->ownerType,
                AccountErasureProgress::userId => $assignment->userId,
                // SCAFFOLD: HIL-1950 fills done from the coordinator's end predicate.
                AccountErasureProgress::done => false,
            ];
        }
        $waits = [];
        foreach ($set->waiting() as $assignment) {
            if (isset($waits[$assignment->assignmentKey])) {
                continue;
            }
            $waits[$assignment->assignmentKey] = [
                AccountErasureProgress::participant => AccountOperationExpectation::participantOf($assignment->payload),
                AccountErasureProgress::userId => $assignment->userId,
            ];
        }

        return new AccountErasureProgress(
            $request->startedAt,
            array_values($parts),
            array_values($waits),
            AccountErasureFinal::refusalsOf($operation),
        );
    }
}
