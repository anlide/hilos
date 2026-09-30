<?php

declare(strict_types=1);

namespace Hilos\Database\Transaction;

use Closure;
use Hilos\Database\Database;

/**
 * One level of the transaction stack a database connection carries.
 *
 * The outermost level is the MySQL transaction itself and has no savepoint; every level
 * above it is a savepoint inside that transaction, named by its depth. A level holds the
 * announcements made while it was the innermost open one — the DB-sync frames to the
 * other processes and the reactions of this process to a changed row — until the
 * transaction they belong to commits: a committed nested level hands them to its parent,
 * a committed outermost level releases them, a rollback drops them.
 *
 * A level whose commit failed is marked failed rather than removed: the caller's own
 * rollback closes it, and removing it early would hand that rollback to the parent level.
 *
 * Held here and not on {@see Database} so that the second leaf of this pair (HIL-1165) can
 * put the memory snapshots a rollback restores beside the announcements it drops.
 */
final class TransactionLevel
{
    /** Name prefix of a savepoint; the depth of the level completes it. */
    public const string SAVEPOINT_PREFIX = 'hilos_tx_';

    /** @var list<Closure> Announcements waiting for the commit, in the order they were made */
    private array $held = [];

    private bool $failed = false;

    /**
     * @param bool $nestable Whether this level allows another level to start inside it
     * @param ?string $savepoint Savepoint this level stands on, or null for the outermost level
     */
    public function __construct(
        public readonly bool $nestable,
        public readonly ?string $savepoint,
    ) {
    }

    /**
     * @param Closure $announce Announcement to make once the transaction commits
     */
    public function hold(Closure $announce): void
    {
        $this->held[] = $announce;
    }

    /**
     * Hands over the held announcements and keeps none of them.
     *
     * @return list<Closure> Announcements in the order they were held
     */
    public function takeHeld(): array
    {
        $held = $this->held;
        $this->held = [];

        return $held;
    }

    /**
     * Drops the held announcements: what they announced is not going to be committed.
     */
    public function dropHeld(): void
    {
        $this->held = [];
    }

    /**
     * Marks the level as one whose commit failed and whose database side is already rolled back.
     */
    public function markFailed(): void
    {
        $this->failed = true;
    }

    /**
     * @return bool Whether the commit of this level failed
     */
    public function isFailed(): bool
    {
        return $this->failed;
    }
}
