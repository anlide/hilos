<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Hilos;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** DDL stays outside rollback scopes; the migration level and every pre-existing moment are restored in finally. */
final class GuestCreatedAtMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_INDEX = 100;
    private const int BEFORE_MIGRATION = 99;
    private const string SESSION_TOKEN = 'guest-created-at-migration';
    private const string LEGACY_MOMENT = '2026-01-02 03:04:05';
    private const string LEGACY_UTC_MOMENT = '2026-01-02 00:04:05';

    /** TIMESTAMP conversion preserves its instant through UP, DOWN and repeated UP in a non-UTC session. */
    public function testPopulatedUpgradeDownAndRepeatedUpgrade(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $latestIndex = Migration::getCurrentIndex();
        self::assertGreaterThanOrEqual(self::MIGRATION_INDEX, $latestIndex);
        $originalRows = Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows();
        $originalTimeZone = Database::sql('SELECT @@session.time_zone AS time_zone')->field('time_zone');
        $guestId = null;

        try {
            Database::sqlRun("SET SESSION time_zone = '+03:00'");
            self::assertSame($latestIndex - self::BEFORE_MIGRATION, Migration::migrateDown(self::BEFORE_MIGRATION));
            Database::sql('INSERT INTO `guest` (`session_token`, `name`, `created_at`) VALUES (?, ?, ?)',
                [self::SESSION_TOKEN, 'Migration guest', self::LEGACY_MOMENT]);
            $guestId = Database::lastInsertId();

            self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
            self::assertSame(TimeHelper::sqlToMs(self::LEGACY_UTC_MOMENT), (int)Database::sql(
                'SELECT `created_at` FROM `guest` WHERE `id` = ?', [$guestId],
            )->field('created_at'));

            self::assertSame(1, Migration::migrateDown(self::BEFORE_MIGRATION));
            self::assertSame(self::LEGACY_MOMENT, Database::sql(
                'SELECT `created_at` FROM `guest` WHERE `id` = ?', [$guestId],
            )->field('created_at'));
            self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
            self::assertSame(TimeHelper::sqlToMs(self::LEGACY_UTC_MOMENT), (int)Database::sql(
                'SELECT `created_at` FROM `guest` WHERE `id` = ?', [$guestId],
            )->field('created_at'));
        } finally {
            try {
                Migration::migrateUp($latestIndex);
                Database::sqlRun('DELETE FROM `guest` WHERE `id` = ?', [$guestId]);
                // DOWN keeps only whole seconds; restore the exact original milliseconds of shared rows.
                foreach ($originalRows as $row) {
                    Database::sqlRun('UPDATE `guest` SET `created_at` = ? WHERE `id` = ?', [$row['created_at'], $row['id']]);
                }
                self::assertSame($originalRows, Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows());
                self::assertSame($latestIndex, Migration::getCurrentIndex());
            } finally {
                Database::sqlRun('SET SESSION time_zone = ?', [$originalTimeZone]);
                Schema::reset();
                Schema::initialize();
                Hilos::$db->forgetLoadedDataForTest();
            }
        }
    }

    /** @return iterable<string, array{int, ?int, ?int}> Milliseconds, legacy whole milliseconds or the expected server error */
    public static function legacyTimestampBoundaries(): iterable
    {
        yield 'negative whole second' => [-1000, null, 1265];
        yield 'negative fractional second' => [-1, null, 1292];
        yield 'epoch zero' => [0, null, 1292];
        yield 'below first second' => [999, null, 1292];
        yield 'first second' => [1000, 1000, null];
        yield 'first second with milliseconds' => [1999, 1000, null];
        yield 'last second' => [2147483647000, 2147483647000, null];
        yield 'last second with milliseconds' => [2147483647999, 2147483647000, null];
        yield 'above last second' => [2147483648000, null, 1292];
    }

    /**
     * An unrepresentable moment must fail before the original BIGINT is dropped or changed.
     * SQL supplies the migration fixture and independent checks; the application writer always supplies nowMs().
     *
     * @param int $momentMs Fixture in the signed millisecond column
     * @param ?int $legacyMs Expected whole-second moment after a successful DOWN
     * @param ?int $mysqlError Expected real SQL failure, absent for representable moments
     */
    #[DataProvider('legacyTimestampBoundaries')]
    public function testDownPreservesTheMomentOrRefuses(int $momentMs, ?int $legacyMs, ?int $mysqlError): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $latestIndex = Migration::getCurrentIndex();
        self::assertGreaterThanOrEqual(self::MIGRATION_INDEX, $latestIndex);
        $originalRows = Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows();
        $originalColumns = Database::sql('SHOW COLUMNS FROM `guest`')->rows();
        $originalMigrations = Database::sql('SELECT `index`, `failed` FROM `migration` ORDER BY `index`')->rows();
        $originalTimeZone = Database::sql('SELECT @@session.time_zone AS time_zone')->field('time_zone');
        $guestId = null;

        try {
            Database::sqlRun("SET SESSION time_zone = '+00:00'");
            Migration::migrateDown(self::MIGRATION_INDEX);
            Database::sql('INSERT INTO `guest` (`session_token`, `name`, `created_at`) VALUES (?, ?, ?)',
                [self::SESSION_TOKEN, 'Migration boundary guest', $momentMs]);
            $guestId = Database::lastInsertId();
            $rowsBeforeDown = Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows();
            $failure = null;
            try {
                self::assertSame(1, Migration::migrateDown(self::BEFORE_MIGRATION));
            } catch (DatabaseException $exception) {
                $failure = $exception;
            }

            if ($mysqlError !== null) {
                self::assertInstanceOf(DatabaseException::class, $failure, 'DOWN must refuse an unrepresentable TIMESTAMP.');
                self::assertSame($mysqlError, $failure->getMysqlErrorCode());
                self::assertStringContainsString('Migration 100 rollback failed:', $failure->getMessage());
                self::assertSame($rowsBeforeDown, Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows());
                self::assertSame($originalColumns, Database::sql('SHOW COLUMNS FROM `guest` WHERE `Field` <> ?',
                    ['__hil1930_created_at'])->rows());
                self::assertSame('timestamp', Database::sql('SHOW COLUMNS FROM `guest` WHERE `Field` = ?',
                    ['__hil1930_created_at'])->row()['Type']);
                self::assertSame(1, (int)Database::sql('SELECT `failed` FROM `migration` WHERE `index` = ?',
                    [self::MIGRATION_INDEX])->field('failed'));
            } else {
                self::assertNull($failure);
                $legacyColumn = Database::sql('SHOW COLUMNS FROM `guest` WHERE `Field` = ?', ['created_at'])->row();
                self::assertSame('timestamp', $legacyColumn['Type']);
                self::assertSame('NO', $legacyColumn['Null']);
                self::assertSame('current_timestamp()', $legacyColumn['Default']);
                self::assertSame('', $legacyColumn['Extra']);
                self::assertSame($legacyMs, (int)Database::sql(
                    'SELECT UNIX_TIMESTAMP(`created_at`) * 1000 AS moment_ms FROM `guest` WHERE `id` = ?', [$guestId],
                )->field('moment_ms'));
                self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
                self::assertSame($legacyMs, (int)Database::sql(
                    'SELECT `created_at` FROM `guest` WHERE `id` = ?', [$guestId],
                )->field('created_at'));
            }
        } finally {
            try {
                // A failed DOWN leaves its BIGINT, staging column and failed marker; do not replay UP over that schema.
                if (Database::sql('SHOW COLUMNS FROM `guest` WHERE `Field` = ?', ['created_at'])->row()['Type'] === 'bigint(20)') {
                    if (Database::sql('SHOW COLUMNS FROM `guest` WHERE `Field` = ?', ['__hil1930_created_at'])->rows() !== []) {
                        Database::sqlRun('ALTER TABLE `guest` DROP COLUMN `__hil1930_created_at`');
                    }
                    Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [self::MIGRATION_INDEX]);
                }
                Migration::migrateUp($latestIndex);
                Database::sqlRun('DELETE FROM `guest` WHERE `id` = ?', [$guestId]);
                foreach ($originalRows as $row) {
                    Database::sqlRun('UPDATE `guest` SET `created_at` = ? WHERE `id` = ?', [$row['created_at'], $row['id']]);
                }
                self::assertSame($originalRows, Database::sql('SELECT `id`, `created_at` FROM `guest` ORDER BY `id`')->rows());
                self::assertSame($originalColumns, Database::sql('SHOW COLUMNS FROM `guest`')->rows());
                self::assertSame($originalMigrations, Database::sql('SELECT `index`, `failed` FROM `migration` ORDER BY `index`')->rows());
                self::assertSame($latestIndex, Migration::getCurrentIndex());
            } finally {
                Database::sqlRun('SET SESSION time_zone = ?', [$originalTimeZone]);
                Schema::reset();
                Schema::initialize();
                Hilos::$db->forgetLoadedDataForTest();
            }
        }
    }
}
