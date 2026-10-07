<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Environment\Exception\EnvException;

/**
 * Extends the four append-only journal tables before writers start.
 */
final class ChangeLogPartitions
{
    /** @var list<string> */
    public const array TABLES = [
        'hilos_change_log_receipt',
        'hilos_change_log',
        'hilos_change_log_change',
        'hilos_change_log_value',
    ];

    private const int MONTHS_AHEAD = 24;

    private const string FUTURE_PARTITION = 'p_future';

    /**
     * Uses the primary database's rollout claim to serialize DDL on the journal database.
     * The caller's active connection is restored even when a check or DDL fails.
     *
     * @param ?DateTimeImmutable $at UTC month to prepare; database UTC now when null
     * @param ?MigrationClaimHolder $holder Claim identity; process identity when null
     * @throws DatabaseException When a table or partition differs or DDL fails
     * @throws EnvException When a process claim cannot read its node name
     */
    public static function ensure(?DateTimeImmutable $at = null, ?MigrationClaimHolder $holder = null): void
    {
        $originalIndex = Database::getCurrentIndex();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            $holder ??= MigrationClaimHolder::process();
            MigrationClaim::take($holder);
            try {
                self::ensureUnderClaim($at);
            } finally {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                MigrationClaim::release($holder);
            }
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * The caller already holds the primary schema rollout claim and releases it afterward.
     * This entry neither takes it again nor releases it, and restores the active connection.
     *
     * @param ?DateTimeImmutable $at UTC month to prepare; database UTC now when null
     * @throws DatabaseException When a table or partition differs or DDL fails
     */
    public static function ensureUnderClaim(?DateTimeImmutable $at = null): void
    {
        $originalIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if ($at === null) {
                Database::sql("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01') AS `month_start`");
                $at = new DateTimeImmutable((string)Database::field('month_start'), new DateTimeZone('UTC'));
            }
            $month = $at->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);
            Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
            foreach (self::TABLES as $table) {
                self::ensureTable($table, $month);
            }
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * @param string $table One known journal table
     * @param DateTimeImmutable $month First day of the current UTC month
     * @throws DatabaseException When the table's partition shape differs or DDL fails
     */
    private static function ensureTable(string $table, DateTimeImmutable $month): void
    {
        $database = ChangeLogDatabase::configuredName();
        Database::sql(
            'SELECT PARTITION_NAME AS `name`, PARTITION_DESCRIPTION AS `boundary`, '
            . 'PARTITION_METHOD AS `method`, PARTITION_EXPRESSION AS `expression` '
            . 'FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
            . 'ORDER BY PARTITION_ORDINAL_POSITION',
            [$database, $table],
        );
        $partitions = Database::rows();
        if ($partitions === []) {
            throw new DatabaseException("Change log database {$database}: table {$table} has no partitions");
        }

        $firstMonth = null;
        $lastMonth = null;
        foreach ($partitions as $position => $partition) {
            $name = (string)$partition['name'];
            if ($partition['method'] !== 'RANGE COLUMNS' || trim((string)$partition['expression'], '`') !== 'created_at') {
                throw new DatabaseException("Change log database {$database}: table {$table} partition {$name} has an unexpected method");
            }
            if ($name === self::FUTURE_PARTITION) {
                if ($position !== count($partitions) - 1 || $partition['boundary'] !== 'MAXVALUE') {
                    throw new DatabaseException("Change log database {$database}: table {$table} partition p_future is not last");
                }
                continue;
            }
            if (preg_match('/\Ap[0-9]{6}\z/D', $name) !== 1) {
                throw new DatabaseException("Change log database {$database}: table {$table} has unexpected partition {$name}");
            }
            $partitionMonth = DateTimeImmutable::createFromFormat('!Ym', substr($name, 1), new DateTimeZone('UTC'));
            if ($partitionMonth === false || $partitionMonth->format('Ym') !== substr($name, 1)) {
                throw new DatabaseException("Change log database {$database}: table {$table} has invalid partition {$name}");
            }
            if ($lastMonth !== null && $partitionMonth->format('Ym') !== $lastMonth->modify('+1 month')->format('Ym')) {
                throw new DatabaseException("Change log database {$database}: table {$table} has a gap before {$name}");
            }
            $expectedBoundary = $partitionMonth->modify('+1 month')->format('Y-m-d');
            if (!str_contains((string)$partition['boundary'], $expectedBoundary)) {
                throw new DatabaseException("Change log database {$database}: table {$table} partition {$name} has a wrong boundary");
            }
            $firstMonth ??= $partitionMonth;
            $lastMonth = $partitionMonth;
        }
        if ((string)$partitions[count($partitions) - 1]['name'] !== self::FUTURE_PARTITION) {
            throw new DatabaseException("Change log database {$database}: table {$table} is missing p_future");
        }
        if ($firstMonth !== null && $firstMonth > $month) {
            throw new DatabaseException("Change log database {$database}: table {$table} starts after the current month");
        }

        $nextMonth = $lastMonth?->modify('+1 month') ?? $month;
        $lastNeededMonth = $month->modify('+' . self::MONTHS_AHEAD . ' months');
        if ($nextMonth > $lastNeededMonth) {
            return;
        }

        $definitions = [];
        for ($cursor = $nextMonth; $cursor <= $lastNeededMonth; $cursor = $cursor->modify('+1 month')) {
            $definitions[] = 'PARTITION `p' . $cursor->format('Ym') . '` VALUES LESS THAN ('
                . "'" . $cursor->modify('+1 month')->format('Y-m-d') . "')";
        }
        $definitions[] = 'PARTITION `p_future` VALUES LESS THAN (MAXVALUE)';
        Database::sql(
            'ALTER TABLE ' . ChangeLogDatabase::identifier($database) . '.`' . $table . '` '
            . 'REORGANIZE PARTITION `p_future` INTO (' . implode(', ', $definitions) . ')',
        );
    }
}
