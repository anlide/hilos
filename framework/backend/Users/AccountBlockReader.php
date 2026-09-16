<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Exception\View\UnknownLazyStrategyException;
use Hilos\Database\View\Collection\HilosUserBlockSource;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Exception\UserBlockSourceMissingException;

/**
 * AccountBlockReader - the framework's way to ask whether an account is blocked (HIL-944).
 *
 * The block column is the project's and the contract is the framework's: the project's users
 * collection implements {@see HilosUserBlockSource}, and framework code asks here without knowing
 * the project schema. The project answers in bulk only; the single read is the bulk read with
 * one id, so the two shapes cannot drift apart.
 *
 * Nothing is kept between calls. Every call resolves the source through the database context, so
 * a block an administrator just decided is the answer to the very next question, and a process
 * that does not read the users collection is refused by the read guard rather than answered out
 * of a stale copy. That refusal passes through untouched.
 *
 * A missing source is refused as well, never answered with false: a guard that fails open on a
 * wiring gap would let a blocked account through and say nothing about why.
 */
final class AccountBlockReader
{
    /**
     * Reports whether one account is blocked.
     *
     * A non-positive id is no account at all - zero is the framework's "no user resolved" - and
     * answers false without reaching the source.
     *
     * @param int $userId User id to ask about
     * @return bool True when the account is blocked
     * @throws UserBlockSourceMissingException When no block source can be read in this process
     * @throws DbCollectionNotReadableException When this process does not read the block source
     * @throws CollectionNotFoundException When the block source disappears between the scan and the read
     * @throws UnknownLazyStrategyException When the block source's lazy strategy is unknown
     * @throws LogicException When the block source's entity class is not configured
     * @throws DatabaseException On connection or schema error
     * @throws HilosException When the block source cannot read its database state
     */
    public function isBlocked(int $userId): bool
    {
        return $this->blockedAmong([$userId])[$userId];
    }

    /**
     * Reports the block flag of each requested account.
     *
     * Duplicates collapse into one key, and a non-positive id answers false without reaching the
     * source. A list with no positive id answers without touching the database layer at all.
     *
     * @param list<int> $userIds User ids to ask about
     * @return array<int, bool> Block flag per requested id; every requested id present, a row that is gone answers false
     * @throws UserBlockSourceMissingException When no block source can be read in this process
     * @throws DbCollectionNotReadableException When this process does not read the block source
     * @throws CollectionNotFoundException When the block source disappears between the scan and the read
     * @throws UnknownLazyStrategyException When the block source's lazy strategy is unknown
     * @throws LogicException When the block source's entity class is not configured
     * @throws DatabaseException On connection or schema error
     * @throws HilosException When the block source cannot read its database state
     */
    public function blockedAmong(array $userIds): array
    {
        $answers = [];
        $accountIds = [];
        foreach ($userIds as $userId) {
            $answers[$userId] = false;
            if ($userId > 0) {
                $accountIds[$userId] = $userId;
            }
        }

        if ($accountIds === []) {
            return $answers;
        }

        $reported = $this->source()->blockedAmong(array_values($accountIds));
        foreach ($accountIds as $userId) {
            // A row that is gone answers false by the source contract, and an id the source left out is that same row.
            $answers[$userId] = $reported[$userId] ?? false;
        }

        return $answers;
    }

    /**
     * Resolves the project's block source for this one call.
     *
     * @return HilosUserBlockSource Block source mounted by the project
     * @throws UserBlockSourceMissingException When the process has no database layer, or the project mounted no block source
     * @throws DbCollectionNotReadableException When this process does not read the block source
     * @throws CollectionNotFoundException When the block source disappears between the scan and the read
     * @throws UnknownLazyStrategyException When the block source's lazy strategy is unknown
     * @throws LogicException When the block source's entity class is not configured
     * @throws DatabaseException On connection or schema error
     */
    private function source(): HilosUserBlockSource
    {
        if (Hilos::$db === null) {
            throw UserBlockSourceMissingException::forDatabaseFreeProcess();
        }

        return Hilos::$db->userBlockSource() ?? throw UserBlockSourceMissingException::forFacade(Hilos::appClass());
    }
}
