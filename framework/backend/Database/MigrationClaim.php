<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Database\Exception\SqlRuntime\DeadlockDetectedException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Helpers\UtcMoment;
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
                self::writeClaim($holder, false);

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
                self::writeClaim($holder, true);
                if (Database::affectedRows() === 1) {
                    Logger::warning(
                        "Taking back the schema rollout claim this node's previous start left at " . UtcMoment::fromMs($row->claimedAt),
                    );

                    return;
                }

                continue;
            }

            if ($polls % self::POLLS_PER_WAITING_LINE === 0) {
                Logger::warning(
                    "Waiting for the schema rollout claim held by {$row->holder} since " . UtcMoment::fromMs($row->claimedAt),
                );
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

        return $row === null ? null : new MigrationClaimRow((string)$row['holder'], self::readClaimedAt($row['claimed_at']));
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

    /**
     * Writes through the pre-change boundary without retaining a physical type across a pause.
     *
     * @param MigrationClaimHolder $holder Process taking or reclaiming the row
     * @param bool $reclaim Whether to restamp this holder's existing row
     * @throws DatabaseException When the type is unsupported or the write fails without a type transition
     */
    private static function writeClaim(MigrationClaimHolder $holder, bool $reclaim): void
    {
        $milliseconds = TimeHelper::nowMs();
        $legacy = self::legacyColumn();
        $write = static function (bool $legacy) use ($milliseconds, $holder, $reclaim): void {
            $stamp = $legacy
                ? DateTimeImmutable::createFromTimestamp(intdiv($milliseconds, TimeConstants::MS_PER_SECOND))
                    ->setTimezone(new DateTimeZone(TimeConstants::UTC_TIME_ZONE))->format('Y-m-d H:i:s')
                : $milliseconds;
            if ($reclaim) {
                Database::sql(
                    'UPDATE `' . self::TABLE . '` SET `claimed_at` = ? WHERE `id` = ? AND `holder` = ?',
                    [$stamp, self::CLAIM_ID, $holder->name],
                );
            } else {
                Database::sql(
                    'INSERT INTO `' . self::TABLE . '` (`id`, `holder`, `claimed_at`) VALUES (?, ?, ?)',
                    [self::CLAIM_ID, $holder->name, $stamp],
                );
            }
        };
        try {
            $write($legacy);
        } catch (DatabaseException $failure) {
            if (self::legacyColumn() === $legacy) {
                throw $failure;
            }
            $write(!$legacy);
        }
    }

    /**
     * @return bool Whether the current connection still carries the legacy DATETIME column
     * @throws DatabaseException When the physical type cannot be read or is unsupported
     */
    private static function legacyColumn(): bool
    {
        Database::sql(
            'SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'claimed_at'],
        );
        $column = Database::row();
        if ($column !== null && strtolower($column['DATA_TYPE']) === 'datetime') {
            return true;
        }
        if ($column !== null && preg_match('/^bigint(?:\([0-9]+\))?(?: signed)?$/iD', $column['COLUMN_TYPE']) === 1) {
            return false;
        }

        throw new DatabaseException('Unsupported schema rollout claim moment type');
    }

    /**
     * @param int|string $value Stored milliseconds or a legacy UTC SQL datetime
     * @return int Unix epoch milliseconds
     * @throws DatabaseException When the legacy moment cannot be parsed
     */
    private static function readClaimedAt(int|string $value): int
    {
        if (is_int($value) || preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int)$value;
        }
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone(TimeConstants::UTC_TIME_ZONE));
        if ($moment === false || $moment->format('Y-m-d H:i:s') !== $value) {
            throw new DatabaseException('Invalid legacy schema rollout claim moment');
        }

        return $moment->getTimestamp() * TimeConstants::MS_PER_SECOND;
    }
}
