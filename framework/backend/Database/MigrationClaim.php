<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use Hilos\Database\Exception\SqlRuntime\DeadlockDetectedException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Utils\Logger;

/**
 * The schema rollout claim: one row in the database says who is rolling the schema out, so
 * that nodes starting together on one database roll it out once (HIL-1228).
 *
 * A row rather than the server's named lock (`GET_LOCK`): MariaDB Galera refuses `GET_LOCK`
 * outright since 10.6.13/10.11.3, and a multi-primary cluster on MariaDB is Galera, so a named
 * lock would hold on one server and stop the node from starting on the other topology. The
 * insert is the only arbiter on every topology - a duplicate key on one server, a certification
 * conflict on Galera; reading the row is only a hint about whom to wait for.
 *
 * The price of a row: the server does not remove it when its holder dies. A node's own start
 * takes it back ({@see MigrationClaimHolder}), an operator removes any other with
 * `db:migration:release`, and a restore clears the table because an archive may carry a row
 * taken at the moment of the backup.
 *
 * The table itself is created beside `migration` by {@see Migration::initialize()}.
 */
final class MigrationClaim
{
    /** @var string Table the claim row lives in */
    public const string TABLE = 'hilos_migration_claim';

    /** @var int Key of the one claim row: one rollout per database */
    public const int CLAIM_ID = 1;

    /** @var int Seconds a waiting process sleeps between two reads of the claim row */
    private const int POLL_INTERVAL_SECONDS = 1;

    /** @var int Polls between two waiting lines in the journal; the first poll always writes one */
    private const int POLLS_PER_WAITING_LINE = 30;

    /**
     * Takes the claim, waiting as long as another holder keeps it.
     *
     * There is no deadline: only the fact that the row is gone lets a waiting process go on.
     * While it waits, the journal names the holder on the first poll and on every 30th after it.
     *
     * @param MigrationClaimHolder $holder Process taking the claim
     * @param ?Closure(): void $pause Wait between two polls; one second when null
     * @throws DatabaseException When the claim row cannot be written or read
     */
    public static function take(MigrationClaimHolder $holder, ?Closure $pause = null): void
    {
        $polls = 0;
        while (true) {
            try {
                Database::sql(
                    'INSERT INTO `' . self::TABLE . '` (`id`, `holder`, `claimed_at`) VALUES (?, ?, NOW())',
                    [self::CLAIM_ID, $holder->name],
                );

                return;
            } catch (DuplicateEntryException | DeadlockDetectedException) {
                // Another row is in the way: a duplicate key on one server, a certification
                // conflict on Galera. The read below only says whom to wait for.
            }

            $row = self::current();
            if ($row === null) {
                // Released between the insert and the read: the next insert decides again.
                continue;
            }

            if ($holder->mayTakeBack && $row->holder === $holder->name) {
                Database::sql(
                    'UPDATE `' . self::TABLE . '` SET `claimed_at` = NOW() WHERE `id` = ? AND `holder` = ?',
                    [self::CLAIM_ID, $holder->name],
                );
                if (Database::affectedRows() === 1) {
                    Logger::warning(
                        "Taking back the schema rollout claim this node's previous start left at {$row->claimedAt}",
                    );

                    return;
                }

                continue;
            }

            if ($polls % self::POLLS_PER_WAITING_LINE === 0) {
                Logger::warning("Waiting for the schema rollout claim held by {$row->holder} since {$row->claimedAt}");
            }
            $polls++;

            if ($pause === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
            } else {
                $pause();
            }
        }
    }

    /**
     * Gives the claim up; a row another holder wrote in the meantime stays where it is.
     *
     * @param MigrationClaimHolder $holder Process that took the claim
     * @throws DatabaseException When the claim row cannot be deleted
     */
    public static function release(MigrationClaimHolder $holder): void
    {
        Database::sql(
            'DELETE FROM `' . self::TABLE . '` WHERE `id` = ? AND `holder` = ?',
            [self::CLAIM_ID, $holder->name],
        );
    }

    /**
     * @return ?MigrationClaimRow The claim as it stands, or null when nobody holds it
     * @throws DatabaseException When the claim row cannot be read
     */
    public static function current(): ?MigrationClaimRow
    {
        Database::sql(
            'SELECT `holder`, `claimed_at` FROM `' . self::TABLE . '` WHERE `id` = ?',
            [self::CLAIM_ID],
        );
        $row = Database::row();

        return $row === null ? null : new MigrationClaimRow((string)$row['holder'], (string)$row['claimed_at']);
    }

    /**
     * Removes the claim an operator names, and only while that holder still has it.
     *
     * @param string $holder Holder name the operator read off the waiting line or the status
     * @return bool Whether the named holder had the claim and it is gone now
     * @throws DatabaseException When the claim row cannot be deleted
     */
    public static function releaseHeldBy(string $holder): bool
    {
        Database::sql(
            'DELETE FROM `' . self::TABLE . '` WHERE `id` = ? AND `holder` = ?',
            [self::CLAIM_ID, $holder],
        );

        return Database::affectedRows() === 1;
    }

    /**
     * Empties the claim table, whoever holds it.
     *
     * For a restore alone: the row an archive carried belongs to a process of the backup's time.
     *
     * @throws DatabaseException When the claim table cannot be emptied
     */
    public static function clear(): void
    {
        Database::sql('DELETE FROM `' . self::TABLE . '`');
    }
}
