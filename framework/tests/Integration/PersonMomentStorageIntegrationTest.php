<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AccessLogEntry;
use Hilos\Database\Entity\Item\AccountDeletion;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Entity\Item\UserMerge;
use Hilos\Database\Entity\Item\UserPhoto;
use Hilos\Database\Entity\Item\UserRename;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Object\Item\AccessLogEntry as ObjectAccessLogEntry;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\Object\Item\UserPhoto as ObjectUserPhoto;
use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\View\Item\AccessLogEntry as ViewAccessLogEntry;
use Hilos\Database\View\Item\AccountDeletion as ViewAccountDeletion;
use Hilos\Database\View\Item\User as ViewUser;
use Hilos\Database\View\Item\UserMerge as ViewUserMerge;
use Hilos\Database\View\Item\UserPhoto as ViewUserPhoto;
use Hilos\Database\View\Item\UserRename as ViewUserRename;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real DDL over delivered person-moment parts; these cases cannot run inside a rollback scope. */
final class PersonMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261110024242374685_HIL-1924_people_moments';

    /** @return iterable<string, array{string, string, string}> Unit, moment column and legacy declaration */
    public static function sources(): iterable
    {
        yield 'activity' => ['user', User::last_activity, 'TIMESTAMP NULL DEFAULT NULL'];
        yield 'rename' => ['user_rename', UserRename::renamed_at, 'TIMESTAMP NOT NULL'];
        yield 'merge' => ['user_merge', UserMerge::merged_at, 'TIMESTAMP NULL DEFAULT NULL'];
        yield 'photo' => ['user_photo', UserPhoto::set_at, 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'];
        yield 'access log' => ['access_log', AccessLogEntry::occurred_at, 'TIMESTAMP NOT NULL'];
        yield 'deletion requested' => ['account_deletion', AccountDeletion::requested_at, 'TIMESTAMP NOT NULL'];
        yield 'deletion effective' => ['account_deletion', AccountDeletion::effective_at, 'TIMESTAMP NOT NULL'];
        yield 'deletion canceled' => ['account_deletion', AccountDeletion::canceled_at, 'TIMESTAMP NULL DEFAULT NULL'];
        yield 'deletion completed' => ['account_deletion', AccountDeletion::completed_at, 'TIMESTAMP NULL DEFAULT NULL'];
    }

    /** @return iterable<string, array{string, string, int}> Each field with values outside the TIMESTAMP range */
    public static function unrepresentableMoments(): iterable
    {
        foreach (self::sources() as $name => [$unit, $column]) {
            foreach ([0, 999, -1, 2147483648000] as $moment) {
                yield $name . ' ' . $moment => [$unit, $column, $moment];
            }
        }
    }

    /** @return iterable<string, array{string, string, ?int}> Exact signed moments and nullable absence */
    public static function roundTripMoments(): iterable
    {
        foreach (self::sources() as $name => [$unit, $column]) {
            foreach ([0, -1234567890123, 2147483648001] as $moment) {
                yield $name . ' ' . $moment => [$unit, $column, $moment];
            }
        }
        yield 'absent activity' => ['user', User::last_activity, null];
        yield 'absent merge moment' => ['user_merge', UserMerge::merged_at, null];
        yield 'absent cancellation' => ['account_deletion', AccountDeletion::canceled_at, null];
        yield 'absent completion' => ['account_deletion', AccountDeletion::completed_at, null];
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     */
    #[DataProvider('sources')]
    public function testDeliveredUpConvertsTimestampRowsAndKeepsTheFreshColumnsAndIndexes(string $unit, string $column, string $legacy): void
    {
        $freshColumns = self::columns($unit);
        $freshIndexes = self::indexes($unit);
        $foreignKeys = self::structure($unit)->foreignKeys;
        self::restoreLegacyColumns($unit, $column, $legacy);
        self::seed($unit, 1, '2030-06-15 12:34:56', $column);
        self::seed($unit, 2, '2001-09-09 01:46:40', $column);
        self::seed($unit, 3, '1970-01-01 00:00:01', $column);
        if (self::isNullable($unit, $column)) {
            self::seed($unit, 4, null, $column);
        }
        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered($unit);
        Database::sql("SET time_zone = '+00:00'");

        $expected = [1907757296000, 1000000000000, 1000];
        if (self::isNullable($unit, $column)) {
            $expected[] = null;
        }
        $this->assertSame($expected, self::moments($unit, $column));
        $this->assertSame($freshColumns, self::columns($unit));
        $this->assertSame($freshIndexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $this->assertMoment($unit, $column);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     */
    #[DataProvider('sources')]
    public function testRestorePlannerRecognizesAndReplaysEveryDeliveredUpStatement(string $unit, string $column, string $legacy): void
    {
        $fresh = self::structure($unit);
        self::restoreLegacyColumns($unit, $column, $legacy);
        $structure = self::structure($unit);
        $statements = MigrationSqlReader::statements(self::deliveredPair($unit)[0]);

        $this->assertCount(3, $statements);
        foreach ($statements as $statement) {
            $operations = MigrationOperationReader::read($statement, self::database());
            $this->assertNotEmpty($operations);
            foreach ($operations as $operation) {
                $this->assertNotSame(MigrationOperationKind::UNKNOWN, $operation->kind, $statement);
                if ($operation->kind === MigrationOperationKind::ALTER_TABLE) {
                    $structure->alter($operation);
                }
            }
        }
        $this->assertEquals($fresh->columns, $structure->columns);
        $this->assertEquals($fresh->keys, $structure->keys);
        $this->assertEquals($fresh->foreignKeys, $structure->foreignKeys);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     */
    #[DataProvider('sources')]
    public function testDownRestoresLegacyTypeAndDefaultAndUpReadsBackWholeSeconds(string $unit, string $column, string $legacy): void
    {
        $indexes = self::indexes($unit);
        $foreignKeys = self::structure($unit)->foreignKeys;
        self::seed($unit, 1, 1000000000123, $column);
        self::seed($unit, 2, 1000, $column);
        self::seed($unit, 3, 2147483647999, $column);
        if (self::isNullable($unit, $column)) {
            self::seed($unit, 4, null, $column);
        }
        self::runDelivered($unit, down: true);

        $row = self::column($unit, $column);
        $this->assertSame('timestamp', $row['Type']);
        $this->assertSame(self::isNullable($unit, $column) ? 'YES' : 'NO', $row['Null']);
        $this->assertSame($unit === 'user_photo' ? 'current_timestamp()' : null, $row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertArrayNotHasKey('__hil1924_' . $column, array_column(self::columns($unit), null, 'Field'));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $stored = ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'];
        if (self::isNullable($unit, $column)) {
            $stored[] = null;
        }
        $this->assertSame($stored, self::stored($unit, $column));
        if ($unit === 'user_photo') {
            Database::sql('DELETE FROM hilos_user_photo WHERE user_id = 3');
            Database::sql('INSERT INTO hilos_user_photo (user_id, file_id) VALUES (3, 3)');
            $this->assertNotNull(self::column($unit, $column)['Default']);
            $this->assertSame(1, (int)Database::sql(
                'SELECT COUNT(*) AS present FROM hilos_user_photo WHERE user_id = 3 AND set_at IS NOT NULL',
            )->field('present'));
            Database::sql('UPDATE hilos_user_photo SET set_at = ? WHERE user_id = 3', ['2038-01-19 03:14:07']);
        }
        self::runDelivered($unit);

        $expected = [1000000000000, 1000, 2147483647000];
        if (self::isNullable($unit, $column)) {
            $expected[] = null;
        }
        $this->assertSame($expected, self::moments($unit, $column));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $this->assertMoment($unit, $column);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param int $moment Unrepresentable legacy value
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeChangingRowsOrSchema(string $unit, string $column, int $moment): void
    {
        self::seed($unit, 1, $moment, $column);
        $rows = self::rows($unit);
        $columns = self::columns($unit);
        $indexes = self::indexes($unit);
        try {
            self::runDelivered($unit, down: true);
            $this->fail('A moment outside TIMESTAMP must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }
        $this->assertSame($rows, self::rows($unit));
        $this->assertSame($columns, self::columns($unit));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertMoment($unit, $column);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param ?int $moment Exact epoch milliseconds or an absent optional time
     */
    #[DataProvider('roundTripMoments')]
    public function testEntityObjectAndViewKeepExactSignedMilliseconds(string $unit, string $column, ?int $moment): void
    {
        if ($unit === 'user') {
            Database::sql('DELETE FROM hilos_user');
        }
        $entity = match ($unit) {
            'user' => new User(),
            'user_rename' => new UserRename(),
            'user_merge' => new UserMerge(),
            'user_photo' => new UserPhoto(),
            'access_log' => new AccessLogEntry(),
            'account_deletion' => new AccountDeletion(),
        };
        if ($entity instanceof User) {
            $entity->name = 'Person';
            $entity->theme_pick_initialized = false;
        } else {
            $entity->user_id = 1;
        }
        if ($entity instanceof UserPhoto) {
            $entity->file_id = 1;
        }
        if ($entity instanceof AccessLogEntry) {
            $entity->event = AccessLogEvent::SIGN_IN->value;
        }
        if ($entity instanceof AccountDeletion) {
            $entity->requested_at = 1000;
            $entity->effective_at = 1000;
        }
        $entity->$column = $moment;
        $entity->save();
        $this->assertSame([$moment], self::moments($unit, $column));
        $read = $entity::getById($entity->{$entity::_primary});
        $this->assertSame($moment, $read->$column);
        [$object, $viewClass, $field] = match ($unit) {
            'user' => [ObjectUser::fromEntity($read), ViewUser::class, ObjectUser::lastActivity],
            'user_rename' => [ObjectUserRename::fromEntity($read), ViewUserRename::class, ObjectUserRename::renamedAt],
            'user_merge' => [ObjectUserMerge::fromEntity($read), ViewUserMerge::class, ObjectUserMerge::mergedAt],
            'user_photo' => [ObjectUserPhoto::fromEntity($read), ViewUserPhoto::class, ObjectUserPhoto::setAt],
            'access_log' => [ObjectAccessLogEntry::fromEntity($read), ViewAccessLogEntry::class, ObjectAccessLogEntry::occurredAt],
            'account_deletion' => [ObjectAccountDeletion::fromEntity($read), ViewAccountDeletion::class, match ($column) {
                AccountDeletion::requested_at => ObjectAccountDeletion::requestedAt,
                AccountDeletion::effective_at => ObjectAccountDeletion::effectiveAt,
                AccountDeletion::canceled_at => ObjectAccountDeletion::canceledAt,
                AccountDeletion::completed_at => ObjectAccountDeletion::completedAt,
            }],
        };
        $this->assertSame($moment, $object->$field);
        if ($unit !== 'account_deletion' || !self::isNullable($unit, $column)) {
            $object->$field = $moment;
        }
        $this->assertSame($moment, $object->toArray()[$field]);
        $this->assertSame($moment, new $viewClass($object)->$field);
        $this->assertMoment($unit, $column);
    }

    /** Starts each case with the current stubs, including the referenced people and file rows. */
    protected function setUp(): void
    {
        parent::setUp();
        self::dropFixtureTables();
        foreach (['user', 'file', 'user_rename', 'user_merge', 'user_photo', 'access_log', 'account_deletion'] as $unit) {
            self::runStub($unit);
        }
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1, 'One'), (2, 'Two'), (3, 'Three'), (4, 'Four')");
        for ($id = 1; $id <= 3; $id++) {
            Database::sql(
                'INSERT INTO hilos_file (id, stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, 'photo-' . $id, 'photo.jpg', 'image/jpeg', 4, str_repeat('a', 64), 'public', 1000],
            );
        }
    }

    /** Releases only the tables this case owns, in reverse foreign-key order. */
    protected function tearDown(): void
    {
        try {
            self::dropFixtureTables();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Migrated moment
     */
    private function assertMoment(string $unit, string $column): void
    {
        $row = self::column($unit, $column);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
        $this->assertSame(self::isNullable($unit, $column) ? 'YES' : 'NO', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertArrayNotHasKey('__hil1924_' . $column, array_column(self::columns($unit), null, 'Field'));
    }

    /**
     * @param string $unit Schema unit
     * @param int $person Person/file primary key
     * @param int|string|null $moment Value in the current physical representation
     * @param string $column Moment under test
     */
    private static function seed(string $unit, int $person, int|string|null $moment, string $column): void
    {
        match ($unit) {
            'user' => Database::sql('UPDATE hilos_user SET last_activity = ? WHERE id = ?', [$moment, $person]),
            'user_rename' => Database::sql('INSERT INTO hilos_user_rename (user_id, old_name, new_name, renamed_at) VALUES (?, ?, ?, ?)',
                [$person, 'Old', 'New', $moment]),
            'user_merge' => Database::sql('INSERT INTO hilos_user_merge (user_id, survivor_user_id, merged_at) VALUES (?, ?, ?)',
                [$person, $person === 1 ? 2 : 1, $moment]),
            'user_photo' => Database::sql('INSERT INTO hilos_user_photo (user_id, file_id, set_at) VALUES (?, ?, ?)', [$person, $person, $moment]),
            'access_log' => Database::sql('INSERT INTO hilos_access_log (user_id, event, occurred_at) VALUES (?, ?, ?)',
                [$person, AccessLogEvent::SIGN_IN->value, $moment]),
            'account_deletion' => self::seedDeletion($person, $column, $moment),
        };
    }

    /**
     * @param int $person Request's person
     * @param string $column Moment under test
     * @param int|string|null $moment Current storage representation
     */
    private static function seedDeletion(int $person, string $column, int|string|null $moment): void
    {
        $ordinary = str_starts_with(self::column('account_deletion', $column)['Type'], 'timestamp')
            ? '2001-09-09 01:46:40' : 1000000000123;
        Database::sql(
            'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, canceled_at, completed_at) VALUES (?, ?, ?, ?, ?)',
            [$person, $column === AccountDeletion::requested_at ? $moment : $ordinary,
                $column === AccountDeletion::effective_at ? $moment : $ordinary,
                $column === AccountDeletion::canceled_at ? $moment : null,
                $column === AccountDeletion::completed_at ? $moment : null],
        );
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @return bool Whether the source preserves an absent moment
     */
    private static function isNullable(string $unit, string $column): bool
    {
        return in_array($unit, ['user', 'user_merge'], true) || ($unit === 'account_deletion'
            && in_array($column, [AccountDeletion::canceled_at, AccountDeletion::completed_at], true));
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Original physical declaration
     */
    private static function restoreLegacyColumns(string $unit, string $column, string $legacy): void
    {
        if ($unit === 'account_deletion') {
            Database::sql('ALTER TABLE hilos_account_deletion MODIFY requested_at TIMESTAMP NOT NULL,'
                . ' MODIFY effective_at TIMESTAMP NOT NULL, MODIFY canceled_at TIMESTAMP NULL DEFAULT NULL,'
                . ' MODIFY completed_at TIMESTAMP NULL DEFAULT NULL');
            return;
        }
        Database::sql('ALTER TABLE `hilos_' . $unit . '` MODIFY `' . $column . '` ' . $legacy);
    }

    /** Drops the case's tables in reverse foreign-key order. */
    private static function dropFixtureTables(): void
    {
        foreach (['account_deletion', 'access_log', 'user_photo', 'user_merge', 'user_rename', 'file', 'user'] as $unit) {
            self::runStub($unit, down: true);
        }
    }

    /**
     * @param string $unit Stub's schema unit
     * @param bool $down Whether to drop its tables
     */
    private static function runStub(string $unit, bool $down = false): void
    {
        if ($down) {
            $unit .= '_down';
        }
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_' . $unit . '.sql'));
    }

    /**
     * @param string $unit Schema unit
     * @param bool $down Whether to run DOWN
     */
    private static function runDelivered(string $unit, bool $down = false): void
    {
        foreach (MigrationSqlReader::statements(self::deliveredPair($unit)[$down ? 1 : 0]) as $statement) {
            Database::sql($statement);
        }
    }

    /**
     * @param string $unit Schema unit
     * @return array{string, string} Verified delivered UP/DOWN pair
     */
    private static function deliveredPair(string $unit): array
    {
        $reference = '"changeId":"' . self::CHANGE . '","unit":"' . $unit . '"';
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            if (str_ends_with($file, '_down.sql') || !str_contains(file_get_contents($file), $reference)) {
                continue;
            }
            $up = file_get_contents($file);
            $down = file_get_contents(substr($file, 0, -4) . '_down.sql');
            new FrameworkMigrationCatalog(new FrameworkSchemaCatalog())->verifyPair($up, $down);

            return [$up, $down];
        }
        self::fail('The chat demo did not receive ' . $unit . ' of ' . self::CHANGE);
    }

    /** @return string Current schema name */
    private static function database(): string
    {
        return Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database;
    }

    /**
     * @param string $unit Schema unit
     * @return TableStructure Restore planner's model of its physical table
     */
    private static function structure(string $unit): TableStructure
    {
        $create = Database::sql('SHOW CREATE TABLE `hilos_' . $unit . '`')->row()['Create Table'];
        $collations = [];
        foreach (Database::sql('SHOW COLLATION')->rows() as $row) {
            $collations[$row['Collation']] = $row['Charset'];
        }

        return TableStructure::read($create, self::database(),
            new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', charsetByCollation: $collations));
    }

    /**
     * @param string $unit Schema unit
     * @return list<array<string, mixed>> Physical columns
     */
    private static function columns(string $unit): array
    {
        return Database::sql('SHOW COLUMNS FROM `hilos_' . $unit . '`')->rows();
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @return array<string, mixed> Physical column description
     */
    private static function column(string $unit, string $column): array
    {
        return Database::sql('SHOW COLUMNS FROM `hilos_' . $unit . '` LIKE ?', [$column])->row();
    }

    /**
     * @param string $unit Schema unit
     * @return array<string, list<string>> Indexed columns by key
     */
    private static function indexes(string $unit): array
    {
        $indexes = [];
        foreach (Database::sql('SHOW INDEX FROM `hilos_' . $unit . '`')->rows() as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }
        ksort($indexes);

        return $indexes;
    }

    /**
     * @param string $unit Schema unit
     * @return list<array<string, mixed>> Rows in person order
     */
    private static function rows(string $unit): array
    {
        return Database::sql('SELECT * FROM `hilos_' . $unit . '` ORDER BY ' . ($unit === 'user' ? 'id' : 'user_id'))->rows();
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @return list<?string> Stored values
     */
    private static function stored(string $unit, string $column): array
    {
        return array_column(self::rows($unit), $column);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @return list<?int> Exact epoch milliseconds
     */
    private static function moments(string $unit, string $column): array
    {
        return array_map(static fn (mixed $moment): ?int => $moment === null ? null : (int)$moment, self::stored($unit, $column));
    }
}
