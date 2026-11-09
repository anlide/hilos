<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\HilosException;

/**
 * Validates file and file variant creation moments migration between legacy TIMESTAMP and signed BIGINT ms (HIL-1926).
 */
final class FileMomentsMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_BEFORE = 129;
    private const int MIGRATION_FILE = 130;
    private const int MIGRATION_FILE_VARIANT = 131;
    private const string LEGACY_MOMENT = '2026-01-02 03:04:05';
    private const int MIGRATED_MOMENT_MS = 1767323045000;
    private const int UNREPRESENTABLE_HIGH_MS = 9223372036854775807;
    private const int UNREPRESENTABLE_LOW_MS = -1;
    private const string FILE_STORED_NAME = 'hil1926-test-file.bin';
    private const string VARIANT_STORED_NAME = 'hil1926-test-file-variant.bin';
    private const string UNREPRESENTABLE_FILE_STORED_NAME = 'hil1926-unrep-file.bin';
    private const string UNREPRESENTABLE_VARIANT_STORED_NAME = 'hil1926-unrep-variant.bin';

    /** Migration level this shared test database had before this case rewound it. */
    private ?int $latestMigrationIndex = null;

    /**
     * @throws HilosException When migration or fixture statement fails
     */
    public function testFileAndVariantMomentsMigrateUpAndDownPreservingValues(): void
    {
        $this->ensureMigrationPath();

        self::assertSame(
            $this->latestMigrationIndex - self::MIGRATION_BEFORE,
            Migration::migrateDown(self::MIGRATION_BEFORE),
        );
        Schema::reset();

        $fileId = $this->insertLegacyFile(self::FILE_STORED_NAME, self::LEGACY_MOMENT);
        $variantId = $this->insertLegacyVariant($fileId, self::VARIANT_STORED_NAME, self::LEGACY_MOMENT);

        self::assertSame(2, Migration::migrateUp(self::MIGRATION_FILE_VARIANT));
        Schema::reset();
        Schema::initialize();

        $this->assertMigratedFile($fileId, self::MIGRATED_MOMENT_MS);
        $this->assertMigratedVariant($variantId, self::MIGRATED_MOMENT_MS);
        $this->assertPhysicalBigintMoments();
        $this->assertFileBoundCreatedIndex();

        self::assertSame(2, Migration::migrateDown(self::MIGRATION_BEFORE));
        Schema::reset();

        $this->assertLegacyFile($fileId, self::LEGACY_MOMENT);
        $this->assertLegacyVariant($variantId, self::LEGACY_MOMENT);

        Database::sqlRun('DELETE FROM `hilos_file_variant` WHERE `id` = ?', [$variantId]);
        Database::sqlRun('DELETE FROM `hilos_file` WHERE `id` = ?', [$fileId]);
    }

    /**
     * @throws HilosException When migration or fixture statement fails
     */
    public function testDownRefusesBeforeReplacingAnUnrepresentableSource(): void
    {
        $this->ensureMigrationPath();

        $fileId = $this->insertMigratedFile(self::UNREPRESENTABLE_FILE_STORED_NAME, self::MIGRATED_MOMENT_MS);
        $variantId = $this->insertMigratedVariant(
            $fileId,
            self::UNREPRESENTABLE_VARIANT_STORED_NAME,
            self::UNREPRESENTABLE_HIGH_MS,
        );

        $beforeVariant = Database::sql('SELECT * FROM `hilos_file_variant` WHERE `id` = ?', [$variantId])->rows();
        try {
            Migration::migrateDown(self::MIGRATION_BEFORE);
            self::fail('A variant moment outside the legacy range must refuse DOWN');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString(
                'File moment cannot be represented by the legacy type',
                $failure->getMessage(),
            );
        }

        self::assertSame(
            $beforeVariant,
            Database::sql('SELECT * FROM `hilos_file_variant` WHERE `id` = ?', [$variantId])->rows(),
            'Failed DOWN must not mutate hilos_file_variant rows',
        );
        $this->assertPhysicalBigintColumn('hilos_file_variant');
        self::assertSame(
            [],
            Database::sql("SHOW COLUMNS FROM `hilos_file_variant` LIKE '__hil1926_created_at'")->rows(),
        );

        Database::sqlRun('DELETE FROM `hilos_file_variant` WHERE `id` = ?', [$variantId]);
        Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [self::MIGRATION_FILE_VARIANT]);

        self::assertSame(1, Migration::migrateDown(self::MIGRATION_FILE));
        Schema::reset();

        Database::sqlRun(
            'UPDATE `hilos_file` SET `created_at` = ? WHERE `id` = ?',
            [self::UNREPRESENTABLE_LOW_MS, $fileId],
        );

        $beforeFile = Database::sql('SELECT * FROM `hilos_file` WHERE `id` = ?', [$fileId])->rows();
        try {
            Migration::migrateDown(self::MIGRATION_BEFORE);
            self::fail('A file moment outside the legacy range must refuse DOWN');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString(
                'File moment cannot be represented by the legacy type',
                $failure->getMessage(),
            );
        }

        self::assertSame(
            $beforeFile,
            Database::sql('SELECT * FROM `hilos_file` WHERE `id` = ?', [$fileId])->rows(),
            'Failed DOWN must not mutate hilos_file rows',
        );
        $this->assertPhysicalBigintColumn('hilos_file');
        self::assertSame([], Database::sql("SHOW COLUMNS FROM `hilos_file` LIKE '__hil1926_created_at'")->rows());

        Database::sqlRun('DELETE FROM `hilos_file` WHERE `id` = ?', [$fileId]);
        Database::sqlRun('UPDATE `migration` SET `failed` = 0 WHERE `index` = ?', [self::MIGRATION_FILE]);
    }

    /**
     * Restores the migration level for cases that follow this one in the shared suite.
     *
     * @throws HilosException When replaying a migration or rebuilding schema fails
     */
    protected function tearDown(): void
    {
        try {
            Database::sqlRun('DELETE FROM `hilos_file_variant` WHERE `stored_name` IN (?, ?)', [
                self::VARIANT_STORED_NAME,
                self::UNREPRESENTABLE_VARIANT_STORED_NAME,
            ]);
            Database::sqlRun('DELETE FROM `hilos_file` WHERE `stored_name` IN (?, ?)', [
                self::FILE_STORED_NAME,
                self::UNREPRESENTABLE_FILE_STORED_NAME,
            ]);
            Database::sqlRun(
                'UPDATE `migration` SET `failed` = 0 WHERE `index` IN (?, ?)',
                [self::MIGRATION_FILE, self::MIGRATION_FILE_VARIANT],
            );
            if ($this->latestMigrationIndex !== null) {
                Migration::migrateUp($this->latestMigrationIndex);
                Schema::reset();
                Schema::initialize();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @throws HilosException When setting up the migration path or migrating up fails
     */
    private function ensureMigrationPath(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        Database::sqlRun(
            'UPDATE `migration` SET `failed` = 0 WHERE `index` IN (?, ?)',
            [self::MIGRATION_FILE, self::MIGRATION_FILE_VARIANT],
        );
        $this->latestMigrationIndex = Migration::getCurrentIndex();
        if ($this->latestMigrationIndex < self::MIGRATION_FILE_VARIANT) {
            Migration::migrateUp(self::MIGRATION_FILE_VARIANT);
            $this->latestMigrationIndex = Migration::getCurrentIndex();
        }
        self::assertGreaterThanOrEqual(self::MIGRATION_FILE_VARIANT, $this->latestMigrationIndex);
    }

    /**
     * @throws DatabaseException When inserting the legacy file fixture fails
     */
    private function insertLegacyFile(string $storedName, string $createdAt): int
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file` (`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
            . '`owner_user_id`, `visibility`, `bound`, `created_at`) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)',
            [$storedName, 'test.bin', 'application/octet-stream', 1024, str_repeat('a', 64), 'public', 1, $createdAt],
        );

        return (int)Database::sql(
            'SELECT `id` FROM `hilos_file` WHERE `stored_name` = ?',
            [$storedName],
        )->field('id');
    }

    /**
     * @throws DatabaseException When inserting the legacy file variant fixture fails
     */
    private function insertLegacyVariant(int $fileId, string $storedName, string $createdAt): int
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file_variant` '
            . '(`file_id`, `variant`, `signature`, `stored_name`, `mime_type`, `size`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$fileId, 'thumb', 'sig12345', $storedName, 'image/jpeg', 512, $createdAt],
        );

        return (int)Database::sql(
            'SELECT `id` FROM `hilos_file_variant` WHERE `stored_name` = ?',
            [$storedName],
        )->field('id');
    }

    /**
     * @throws DatabaseException When inserting the migrated file fixture fails
     */
    private function insertMigratedFile(string $storedName, int $createdAtMs): int
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file` (`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
            . '`owner_user_id`, `visibility`, `bound`, `created_at`) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)',
            [$storedName, 'test.bin', 'application/octet-stream', 1024, str_repeat('b', 64), 'public', 1, $createdAtMs],
        );

        return (int)Database::sql(
            'SELECT `id` FROM `hilos_file` WHERE `stored_name` = ?',
            [$storedName],
        )->field('id');
    }

    /**
     * @throws DatabaseException When inserting the migrated file variant fixture fails
     */
    private function insertMigratedVariant(int $fileId, string $storedName, int $createdAtMs): int
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file_variant` '
            . '(`file_id`, `variant`, `signature`, `stored_name`, `mime_type`, `size`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$fileId, 'thumb', 'sig12345', $storedName, 'image/jpeg', 512, $createdAtMs],
        );

        return (int)Database::sql(
            'SELECT `id` FROM `hilos_file_variant` WHERE `stored_name` = ?',
            [$storedName],
        )->field('id');
    }

    /**
     * @throws DatabaseException When querying the file fails
     */
    private function assertMigratedFile(int $fileId, int $expectedMs): void
    {
        $row = Database::sql('SELECT `created_at` FROM `hilos_file` WHERE `id` = ?', [$fileId])->row();
        self::assertNotNull($row, 'Migrated file row must exist');
        self::assertSame($expectedMs, (int)$row['created_at']);
    }

    /**
     * @throws DatabaseException When querying the file variant fails
     */
    private function assertMigratedVariant(int $variantId, int $expectedMs): void
    {
        $row = Database::sql('SELECT `created_at` FROM `hilos_file_variant` WHERE `id` = ?', [$variantId])->row();
        self::assertNotNull($row, 'Migrated variant row must exist');
        self::assertSame($expectedMs, (int)$row['created_at']);
    }

    /**
     * @throws DatabaseException When querying the legacy file fails
     */
    private function assertLegacyFile(int $fileId, string $expectedMoment): void
    {
        $row = Database::sql('SELECT `created_at` FROM `hilos_file` WHERE `id` = ?', [$fileId])->row();
        self::assertNotNull($row, 'Legacy file row must exist');
        self::assertSame($expectedMoment, (string)$row['created_at']);
    }

    /**
     * @throws DatabaseException When querying the legacy file variant fails
     */
    private function assertLegacyVariant(int $variantId, string $expectedMoment): void
    {
        $row = Database::sql('SELECT `created_at` FROM `hilos_file_variant` WHERE `id` = ?', [$variantId])->row();
        self::assertNotNull($row, 'Legacy variant row must exist');
        self::assertSame($expectedMoment, (string)$row['created_at']);
    }

    /**
     * @throws DatabaseException When querying table schema fails
     */
    private function assertPhysicalBigintMoments(): void
    {
        $this->assertPhysicalBigintColumn('hilos_file');
        $this->assertPhysicalBigintColumn('hilos_file_variant');
    }

    /**
     * @throws DatabaseException When querying column schema fails
     */
    private function assertPhysicalBigintColumn(string $table): void
    {
        $row = Database::sql("SHOW COLUMNS FROM `{$table}` LIKE 'created_at'")->row();
        self::assertNotNull($row, "Column created_at must exist in {$table}");
        self::assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/i', (string)$row['Type']);
        self::assertSame('NO', (string)$row['Null']);
        self::assertNull($row['Default']);
        self::assertSame('', (string)$row['Extra']);
    }

    /**
     * @throws DatabaseException When querying index schema fails
     */
    private function assertFileBoundCreatedIndex(): void
    {
        $indexes = Database::sql(
            "SHOW INDEX FROM `hilos_file` WHERE `Key_name` = 'idx_file_bound_created'",
        )->rows();
        self::assertCount(2, $indexes, 'Index idx_file_bound_created must have 2 columns');
        self::assertSame('bound', $indexes[0]['Column_name']);
        self::assertSame('created_at', $indexes[1]['Column_name']);
    }
}
