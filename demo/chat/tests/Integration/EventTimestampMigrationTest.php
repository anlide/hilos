<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Hilos;
use Hilos\Database\Database;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\Utils\Helpers\TimeHelper;

/** DDL stays outside rollback scopes; the migration level and every pre-existing moment are restored in finally. */
final class EventTimestampMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_INDEX = 142;
    private const int BEFORE_MIGRATION = 141;
    private const string LEGACY_MOMENT = '2026-01-02 03:04:05';

    /** A populated legacy row survives UP, DOWN and a repeated UP with the same UTC instant. */
    public function testPopulatedUpgradeDownAndRepeatedUpgrade(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $latestIndex = Migration::getCurrentIndex();
        self::assertGreaterThanOrEqual(self::MIGRATION_INDEX, $latestIndex);
        $originalRows = Database::sql('SELECT `id`, `timestamp` FROM `event` ORDER BY `id`')->rows();
        $eventId = null;

        try {
            self::assertSame($latestIndex - self::BEFORE_MIGRATION, Migration::migrateDown(self::BEFORE_MIGRATION));
            Database::sql('INSERT INTO `event` (`type`, `timestamp`) VALUES (?, ?)',
                [ChatEventType::CHAT_STARTED->value, self::LEGACY_MOMENT]);
            $eventId = Database::lastInsertId();

            self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
            self::assertSame(TimeHelper::sqlToMs(self::LEGACY_MOMENT), (int)Database::sql(
                'SELECT `timestamp` FROM `event` WHERE `id` = ?', [$eventId],
            )->field('timestamp'));

            self::assertSame(1, Migration::migrateDown(self::BEFORE_MIGRATION));
            self::assertSame(self::LEGACY_MOMENT, Database::sql(
                'SELECT `timestamp` FROM `event` WHERE `id` = ?', [$eventId],
            )->field('timestamp'));
            self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
            self::assertSame(TimeHelper::sqlToMs(self::LEGACY_MOMENT), (int)Database::sql(
                'SELECT `timestamp` FROM `event` WHERE `id` = ?', [$eventId],
            )->field('timestamp'));
        } finally {
            try {
                Migration::migrateUp($latestIndex);
                Database::sqlRun('DELETE FROM `event` WHERE `id` = ?', [$eventId]);
                // DOWN keeps only whole seconds; restore the exact original milliseconds of shared rows.
                foreach ($originalRows as $row) {
                    Database::sqlRun('UPDATE `event` SET `timestamp` = ? WHERE `id` = ?', [$row['timestamp'], $row['id']]);
                }
                self::assertSame($originalRows, Database::sql('SELECT `id`, `timestamp` FROM `event` ORDER BY `id`')->rows());
                self::assertSame($latestIndex, Migration::getCurrentIndex());
            } finally {
                Schema::reset();
                Schema::initialize();
                Hilos::$db->forgetLoadedDataForTest();
            }
        }
    }
}
