<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises the three sign-in moment migrations on legacy rows with live identity journal triggers. */
final class SignInMomentsMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_BEFORE = 148;
    private const int MIGRATION_IDENTITY = 149;
    private const int MIGRATION_OAUTH_PROVIDER = 150;
    private const int MIGRATION_PASSKEY = 151;
    private const string PROVIDER_KEY = 'oauth:sign-in-moments-fixture';
    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];
    private const array COLUMNS = [
        'hilos_identity' => [
            'id', 'user_id', 'type', 'identifier', 'secret', 'provider', 'verified',
            'password_changed_at', 'provider_name', 'created_at', 'updated_at',
        ],
        'hilos_oauth_provider' => ['id', 'provider_key', 'client_id', 'client_secret', 'scope', 'created_at', 'updated_at'],
        'hilos_passkey_credential' => [
            'id', 'identity_id', 'user_id', 'credential_id', 'public_key', 'algorithm', 'sign_count',
            'transports', 'aaguid', 'user_handle', 'label', 'last_used_at', 'created_at',
        ],
    ];
    private const array MOMENTS = [
        'hilos_identity' => ['password_changed_at', 'created_at', 'updated_at'],
        'hilos_oauth_provider' => ['created_at', 'updated_at'],
        'hilos_passkey_credential' => ['last_used_at', 'created_at'],
    ];

    private ?int $latestMigrationIndex = null;
    private ?int $userId = null;
    private ?int $providerId = null;

    /** @var array<string, int> Journal boundaries before any fixture writes or migration receipts */
    private array $journalBaseline = [];

    /** UP converts seconds to ms without journal noise; DOWN restores the legacy schema and the original rows. */
    public function testAllPartsConvertLegacyRowsAndKeepTheIdentityJournalWorking(): void
    {
        $this->seedLegacyRows();
        $legacy = $this->fixtureRows();
        $beforeJournal = $this->identityJournal();
        self::assertCount(2, $beforeJournal);
        $triggers = $this->identityTriggers();
        self::assertCount(3, $triggers);

        self::assertSame(3, Migration::migrateUp(self::MIGRATION_PASSKEY));
        Schema::reset();
        $migrated = $this->fixtureRows();
        self::assertSame([
            [1735689600123, 1767323045000, 1767323046000],
            [null, 1767323045000, 1767323046000],
        ], array_map(static fn (array $row): array => [
            $row['password_changed_at'] === null ? null : (int)$row['password_changed_at'],
            (int)$row['created_at'], (int)$row['updated_at'],
        ], $migrated['hilos_identity']));
        self::assertSame([[1767323045000, 1767323046000]], array_map(static fn (array $row): array => [
            (int)$row['created_at'], (int)$row['updated_at'],
        ], $migrated['hilos_oauth_provider']));
        self::assertSame([[null, 1767323045000], [1767323046000, 1767323045000]], array_map(static fn (array $row): array => [
            $row['last_used_at'] === null ? null : (int)$row['last_used_at'], (int)$row['created_at'],
        ], $migrated['hilos_passkey_credential']));
        $this->assertMomentSchema(legacy: false);
        self::assertSame($beforeJournal, $this->identityJournal(), 'Converting moments must create no identity journal entries');
        self::assertSame($triggers, $this->identityTriggers(), 'The conversion must leave the live triggers installed');

        Database::sqlRun('UPDATE `hilos_identity` SET `verified` = 1 WHERE `id` = ?', [(int)$legacy['hilos_identity'][0]['id']]);
        $journal = $this->identityJournal();
        self::assertCount(3, $journal);
        self::assertSame('update', $journal[2]['mutation_type']);
        self::assertSame([['name' => 'verified', 'old_value' => '0', 'new_value' => '1']], Database::sql(
            'SELECT f.`name`, c.`old_value`, c.`new_value` FROM `hilos_change_log_change` c '
            . 'JOIN `hilos_change_log_field` f ON f.`id` = c.`field_id` WHERE c.`log_id` = ?',
            [(int)$journal[2]['id']],
            index: ChangeLogDatabase::CONNECTION_INDEX,
        )->rows());
        $legacy['hilos_identity'][0]['verified'] = '1';

        self::assertSame(3, Migration::migrateDown(self::MIGRATION_BEFORE));
        Schema::reset();
        $this->assertMomentSchema(legacy: true);
        self::assertSame($legacy, $this->fixtureRows());
        self::assertSame($triggers, $this->identityTriggers());
    }

    /** @return iterable<string, array{string, string, int, int}> Every converted column's legacy lower and upper refusals */
    public static function unrepresentableMoments(): iterable
    {
        yield 'negative password moment' => ['hilos_identity', 'password_changed_at', -1, self::MIGRATION_IDENTITY];
        yield 'identity creation below one second' => ['hilos_identity', 'created_at', 999, self::MIGRATION_IDENTITY];
        yield 'identity creation after 2038' => ['hilos_identity', 'created_at', 2147483648123, self::MIGRATION_IDENTITY];
        yield 'identity update below one second' => ['hilos_identity', 'updated_at', 999, self::MIGRATION_IDENTITY];
        yield 'identity update after 2038' => ['hilos_identity', 'updated_at', 2147483648123, self::MIGRATION_IDENTITY];
        yield 'provider creation below one second' => ['hilos_oauth_provider', 'created_at', 999, self::MIGRATION_OAUTH_PROVIDER];
        yield 'provider creation after 2038' => ['hilos_oauth_provider', 'created_at', 2147483648123, self::MIGRATION_OAUTH_PROVIDER];
        yield 'provider update below one second' => ['hilos_oauth_provider', 'updated_at', 999, self::MIGRATION_OAUTH_PROVIDER];
        yield 'provider update after 2038' => ['hilos_oauth_provider', 'updated_at', 2147483648123, self::MIGRATION_OAUTH_PROVIDER];
        yield 'passkey use below one second' => ['hilos_passkey_credential', 'last_used_at', 999, self::MIGRATION_PASSKEY];
        yield 'passkey use after 2038' => ['hilos_passkey_credential', 'last_used_at', 2147483648123, self::MIGRATION_PASSKEY];
        yield 'passkey creation below one second' => ['hilos_passkey_credential', 'created_at', 999, self::MIGRATION_PASSKEY];
        yield 'passkey creation after 2038' => ['hilos_passkey_credential', 'created_at', 2147483648123, self::MIGRATION_PASSKEY];
    }

    /**
     * @param string $table Source table whose individual DOWN part must refuse
     * @param string $column Converted moment to make unrepresentable
     * @param int $moment Invalid legacy value, still valid signed milliseconds
     * @param int $part Migration level that isolates this table's DOWN
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeChangingAnyColumnsOrRows(string $table, string $column, int $moment, int $part): void
    {
        $this->seedLegacyRows();
        self::assertSame($part - self::MIGRATION_BEFORE, Migration::migrateUp($part));
        Database::sqlRun(
            'UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `'
            . ($table === 'hilos_oauth_provider' ? 'id' : 'user_id') . '` = ?',
            [$moment, $table === 'hilos_oauth_provider' ? $this->providerId : $this->userId],
        );
        $beforeRows = $this->fixtureRows();
        $beforeSchema = [];
        foreach (array_keys(self::COLUMNS) as $source) {
            $beforeSchema[$source] = Database::sql('SHOW CREATE TABLE `' . $source . '`')->rows();
        }

        try {
            Migration::migrateDown($part - 1);
            self::fail('An unrepresentable moment must refuse DOWN');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString('Sign-in moment cannot be represented by the legacy type', $failure->getMessage());
        }

        self::assertSame($beforeRows, $this->fixtureRows(), 'Refused DOWN must not rewrite source rows');
        foreach ($beforeSchema as $source => $definition) {
            self::assertSame($definition, Database::sql('SHOW CREATE TABLE `' . $source . '`')->rows(),
                'Refused DOWN must not even add a staging column');
        }
    }

    /** Sets the real migration track and installs the canonical triggers before rewinding any schema. */
    protected function setUp(): void
    {
        parent::setUp();
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->latestMigrationIndex = Migration::getCurrentIndex();
        self::assertGreaterThanOrEqual(self::MIGRATION_PASSKEY, $this->latestMigrationIndex);
        $this->restoreMigrationLevel();
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            $this->journalBaseline[$table] = (int)Database::sql(
                'SELECT COALESCE(MAX(`id`), 0) AS `last_id` FROM `' . $table . '`',
                index: ChangeLogDatabase::CONNECTION_INDEX,
            )->field('last_id');
        }
    }

    /** Removes fixture rows, repairs refused DOWN markers and restores the schema and triggers even after a failed case. */
    protected function tearDown(): void
    {
        try {
            if ($this->userId !== null) {
                Database::sqlRun('DELETE FROM `hilos_passkey_credential` WHERE `user_id` = ?', [$this->userId]);
                Database::sqlRun('DELETE FROM `hilos_identity` WHERE `user_id` = ?', [$this->userId]);
                Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$this->userId]);
            }
            if ($this->providerId !== null) {
                Database::sqlRun('DELETE FROM `hilos_oauth_provider` WHERE `id` = ?', [$this->providerId]);
            }
            Database::sqlRun(
                'UPDATE `migration` SET `failed` = 0 WHERE `index` IN (?, ?, ?)',
                [self::MIGRATION_IDENTITY, self::MIGRATION_OAUTH_PROVIDER, self::MIGRATION_PASSKEY],
            );
            if ($this->latestMigrationIndex !== null) {
                $this->restoreMigrationLevel();
            }
        } finally {
            try {
                foreach ($this->journalBaseline as $table => $lastId) {
                    Database::sqlRun('DELETE FROM `' . $table . '` WHERE `id` > ?', [$lastId], index: ChangeLogDatabase::CONNECTION_INDEX);
                }
            } finally {
                parent::tearDown();
            }
        }
    }

    /** Restores the original schema level and the canonical triggers under the migration claim. */
    private function restoreMigrationLevel(): void
    {
        Migration::migrateUp($this->latestMigrationIndex, afterRollout: static function (): void {
            Schema::reset();
            Schema::initialize();
            ChangeLogPartitions::ensureUnderClaim();
            JournalTriggerInstaller::apply();
        });
    }

    /** Rewinds just these three parts and writes old TIMESTAMP rows, including absent moments. */
    private function seedLegacyRows(): void
    {
        self::assertSame($this->latestMigrationIndex - self::MIGRATION_BEFORE, Migration::migrateDown(self::MIGRATION_BEFORE));
        Schema::reset();
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Sign-in moment fixture')");
        $this->userId = Database::lastInsertId();
        foreach ([IdentityType::PASSWORD, IdentityType::PASSKEY] as $type) {
            Database::sql(
                'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `password_changed_at`, `created_at`, `updated_at`) '
                . "VALUES (?, ?, ?, ?, '2026-01-02 03:04:05', '2026-01-02 03:04:06')",
                [$this->userId, $type, $type . '-moment-fixture', $type === IdentityType::PASSWORD ? 1735689600123 : null],
            );
        }
        $anchorId = Database::lastInsertId();
        Database::sql(
            'INSERT INTO `hilos_oauth_provider` (`provider_key`, `created_at`, `updated_at`) '
            . "VALUES (?, '2026-01-02 03:04:05', '2026-01-02 03:04:06')",
            [self::PROVIDER_KEY],
        );
        $this->providerId = Database::lastInsertId();
        foreach ([null, '2026-01-02 03:04:06'] as $index => $lastUsedAt) {
            Database::sqlRun(
                'INSERT INTO `hilos_passkey_credential` '
                . '(`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`, `created_at`, `last_used_at`) '
                . "VALUES (?, ?, ?, 'PUBLIC_KEY', ?, 'user-handle', '2026-01-02 03:04:05', ?)",
                [$anchorId, $this->userId, 'moment-key-' . $index, PasskeyAlgorithm::Es256->value, $lastUsedAt],
            );
        }
    }

    /** @return array<string, list<array<string, mixed>>> Fixture rows in stable primary-key order */
    private function fixtureRows(): array
    {
        $rows = [];
        foreach (array_keys(self::COLUMNS) as $table) {
            $rows[$table] = Database::sql(
                'SELECT * FROM `' . $table . '` WHERE `' . ($table === 'hilos_oauth_provider' ? 'id' : 'user_id') . '` = ? ORDER BY `id`',
                [$table === 'hilos_oauth_provider' ? $this->providerId : $this->userId],
            )->rows();
        }
        return $rows;
    }

    /** @param bool $legacy Check TIMESTAMP defaults and auto-writers after DOWN, or signed BIGINT without them after UP */
    private function assertMomentSchema(bool $legacy): void
    {
        foreach (self::COLUMNS as $table => $expectedColumns) {
            $columns = Database::sql('SHOW COLUMNS FROM `' . $table . '`')->rows();
            self::assertSame($expectedColumns, array_column($columns, 'Field'), $table . ' must retain stub column order');
            $columns = array_column($columns, null, 'Field');
            foreach (self::MOMENTS[$table] as $name) {
                $column = $columns[$name];
                $nullable = in_array($name, ['password_changed_at', 'last_used_at'], true);
                self::assertSame($nullable ? 'YES' : 'NO', $column['Null']);
                if (!$legacy) {
                    self::assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/i', $column['Type']);
                    self::assertNull($column['Default']);
                    self::assertSame('', $column['Extra']);
                } elseif ($name === 'password_changed_at') {
                    self::assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))? unsigned$/i', $column['Type']);
                    self::assertNull($column['Default']);
                    self::assertSame('', $column['Extra']);
                } else {
                    self::assertSame('timestamp', strtolower($column['Type']));
                    if ($nullable) {
                        self::assertNull($column['Default']);
                    } else {
                        self::assertMatchesRegularExpression('/^current_timestamp(?:\(\))?$/i', $column['Default']);
                    }
                    if ($name === 'updated_at') {
                        self::assertMatchesRegularExpression('/^on update current_timestamp(?:\(\))?$/i', $column['Extra']);
                    } else {
                        self::assertSame('', $column['Extra']);
                    }
                }
            }
        }
    }

    /** @return list<array<string, mixed>> Identity journal entries created since this case began */
    private function identityJournal(): array
    {
        return Database::sql(
            'SELECT l.`id`, l.`mutation_type` FROM `hilos_change_log` l '
            . 'JOIN `hilos_change_log_table` t ON t.`id` = l.`table_id` '
            . 'WHERE t.`name` = ? AND l.`id` > ? ORDER BY l.`id`',
            ['hilos_identity', $this->journalBaseline['hilos_change_log']],
            index: ChangeLogDatabase::CONNECTION_INDEX,
        )->rows();
    }

    /** @return list<array<string, mixed>> Actual live trigger bodies and event bindings on the identity table */
    private function identityTriggers(): array
    {
        return Database::sql(
            'SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM INFORMATION_SCHEMA.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME',
            ['hilos_identity'],
        )->rows();
    }
}
