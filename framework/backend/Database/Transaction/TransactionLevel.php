<?php

declare(strict_types=1);

namespace Hilos\Database\Transaction;

use Closure;

/**
 * One level of the transaction stack a database connection carries.
 *
 * The outermost level is the MySQL transaction itself and has no savepoint; every level
 * above it is a savepoint inside that transaction, named by its depth. A level holds the
 * announcements made while it was the innermost open one — the DB-sync and RT-sync frames to
 * the other processes and the reactions of this process to a changed row — until the
 * transaction they belong to commits: a committed nested level hands them to its parent,
 * a committed outermost level releases them, a rollback drops them.
 *
 * Beside them it keeps the journal of the same writes' memory: one step per change a write
 * door made to this process's memory, each knowing how to put back what it changed. The
 * journal lives the same life — a committed nested level hands it to its parent, a committed
 * outermost level throws it away — and a rollback runs it backwards, so the row cache and the
 * runtime go back with the database (HIL-1165).
 *
 * A level is pushed without SQL and opens its MySQL part only at the first query of the
 * connection — BEGIN for the outermost, SAVEPOINT for a nested one — so a transaction that
 * touched only the runtime never needs a connection at all.
 *
 * A level whose commit failed is marked failed rather than removed: the caller's own
 * rollback closes it, and removing it early would hand that rollback to the parent level.
 */
final class TransactionLevel
{
    /** Name prefix of a savepoint; the depth of the level completes it. */
    public const string SAVEPOINT_PREFIX = 'hilos_tx_';

    /** @var list<Closure> Announcements waiting for the commit, in the order they were made */
    private array $held = [];

    /** @var list<Closure> Steps putting this process's memory back, in the order the writes were made */
    private array $undo = [];

    private bool $opened = false;

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
     * @param Closure $undo Step putting back what a write changed in memory, should the level not commit
     */
    public function remember(Closure $undo): void
    {
        $this->undo[] = $undo;
    }

    /**
     * Hands over the memory journal and keeps none of it, so no step can run twice.
     *
     * @return list<Closure> Steps in the order they were remembered
     */
    public function takeUndo(): array
    {
        $undo = $this->undo;
        $this->undo = [];

        return $undo;
    }

    /**
     * Marks the MySQL part of the level as open: BEGIN sent for the outermost, SAVEPOINT for a nested one.
     */
    public function markOpened(): void
    {
        $this->opened = true;
    }

    /**
     * @return bool Whether the MySQL part of the level is open
     */
    public function isOpened(): bool
    {
        return $this->opened;
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
