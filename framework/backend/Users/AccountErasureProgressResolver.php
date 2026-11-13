<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * Reads erasure progress from DB facts, remembering even absence in this process.
 *
 * This memory has no date epoch: only its four DB sources can change the answer.
 * Their mirrors drop it at the write, including inside a transaction, through
 * {@see AccountErasureProgressChangeSubscriber}. It is derived memory, not shared RT state.
 * The card and its standing frame read it; live erasure stays inert until HIL-1952 opens its entry.
 */
final class AccountErasureProgressResolver
{
    /** @var array<int, ?AccountErasureProgress> Progress or remembered absence, keyed by account */
    private static array $progress = [];

    /**
     * @param int $userId Any account in an erasure's fixed circle
     * @return ?AccountErasureProgress Begun open erasure, or null when none covers the account
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws InvalidJsonException When a stored expectation payload cannot be decoded
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

    /** Drops all remembered answers when a source fact changes. */
    public static function forgetAll(): void
    {
        self::$progress = [];
    }

    /**
     * @param int $userId Account whose erasure is read
     * @return ?AccountErasureProgress Begun open erasure, or null before its start or without a database
     * @throws HilosException When a DB reader, item wrapper or stored expectation refuses the lookup
     * @throws JsonException When a stored expectation payload cannot be decoded
     */
    private static function read(int $userId): ?AccountErasureProgress
    {
        if (Hilos::$db === null) {
            return null;
        }
        $operation = Hilos::$db->accountOperations->openErasureCovering($userId);
        if ($operation === null) {
            return null;
        }
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

        return new AccountErasureProgress($request->startedAt, array_values($parts), array_values($waits));
    }
}
