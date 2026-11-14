<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\DataExport;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\LegalAcceptance;
use Hilos\Database\Entity\Item\LegalAcceptanceExport;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\DataExport\DataExportState;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\Database\Object\Item\LegalAcceptance as ObjectLegalAcceptance;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\View\Item\DataExport as ViewDataExport;
use Hilos\Database\View\Item\LegalAcceptance as ViewLegalAcceptance;
use Hilos\Database\View\Item\LegalAcceptanceExport as ViewLegalAcceptanceExport;
use Hilos\Legal\Export\LegalAcceptancesExportState;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real DDL over delivered legal and export moment parts; these cases cannot run inside a rollback scope. */
final class ExportLegalMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261114014747194725_HIL-1925_export_legal_moments';

    /** @return iterable<string, array{string, string, string, bool}> Unit, moment column, legacy declaration and nullability */
    public static function sources(): iterable
    {
        yield 'acceptance' => ['legal_acceptance', LegalAcceptance::accepted_at, 'TIMESTAMP NOT NULL', false];
        yield 'export requested' => ['data_export', DataExport::requested_at, 'TIMESTAMP NOT NULL', false];
        yield 'export finished' => ['data_export', DataExport::finished_at, 'TIMESTAMP NULL DEFAULT NULL', true];
        yield 'export expires' => ['data_export', DataExport::expires_at, 'TIMESTAMP NULL DEFAULT NULL', true];
        yield 'acceptance file requested' => ['legal_acceptance_export', LegalAcceptanceExport::requested_at, 'TIMESTAMP NOT NULL', false];
        yield 'acceptance file finished' => ['legal_acceptance_export', LegalAcceptanceExport::finished_at, 'TIMESTAMP NULL DEFAULT NULL', true];
        yield 'acceptance file expires' => ['legal_acceptance_export', LegalAcceptanceExport::expires_at, 'TIMESTAMP NULL DEFAULT NULL', true];
    }

    /** @return iterable<string, array{string, string, bool, int}> Each field with values outside the TIMESTAMP range */
    public static function unrepresentableMoments(): iterable
    {
        foreach (self::sources() as $name => [$unit, $column, , $nullable]) {
            foreach ([0, 999, -1, 2147483648000] as $moment) {
                yield $name . ' ' . $moment => [$unit, $column, $nullable, $moment];
            }
        }
    }

    /** @return iterable<string, array{string, string, bool, ?int}> Exact signed moments and nullable absence */
    public static function roundTripMoments(): iterable
    {
        foreach (self::sources() as $name => [$unit, $column, , $nullable]) {
            foreach ([0, -1234567890123, 2147483648001] as $moment) {
                yield $name . ' ' . $moment => [$unit, $column, $nullable, $moment];
            }
            if ($nullable) {
                yield $name . ' absent' => [$unit, $column, $nullable, null];
            }
        }
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     * @param bool $nullable Whether the moment may be absent
     */
    #[DataProvider('sources')]
    public function testCleanSchemaKeepsTheMomentAsASignedBigintWithoutAnAutoWriter(
        string $unit,
        string $column,
        string $legacy,
        bool $nullable,
    ): void {
        $this->assertMoment($unit, $column, $nullable);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     * @param bool $nullable Whether the moment may be absent
     */
    #[DataProvider('sources')]
    public function testDeliveredUpConvertsTimestampRowsAndKeepsTheFreshColumnsAndIndexes(
        string $unit,
        string $column,
        string $legacy,
        bool $nullable,
    ): void {
        $freshColumns = self::columns($unit);
        $freshIndexes = self::indexes($unit);
        $foreignKeys = self::structure($unit)->foreignKeys;
        self::restoreLegacyColumns($unit, $column, $legacy);
        self::seed($unit, $column, 1, '2030-06-15 12:34:56');
        self::seed($unit, $column, 2, '2001-09-09 01:46:40');
        self::seed($unit, $column, 3, '1970-01-01 00:00:01');
        if ($nullable) {
            self::seed($unit, $column, 4, null);
        }
        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered($unit);
        Database::sql("SET time_zone = '+00:00'");

        $expected = [1907757296000, 1000000000000, 1000];
        if ($nullable) {
            $expected[] = null;
        }
        $this->assertSame($expected, self::moments($unit, $column));
        $this->assertSame($freshColumns, self::columns($unit));
        $this->assertSame($freshIndexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $this->assertMoment($unit, $column, $nullable);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Legacy declaration
     * @param bool $nullable Whether the moment may be absent
     */
    #[DataProvider('sources')]
    public function testRestorePlannerRecognizesAndReplaysEveryDeliveredUpStatement(
        string $unit,
        string $column,
        string $legacy,
        bool $nullable,
    ): void {
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
     * @param bool $nullable Whether the moment may be absent
     */
    #[DataProvider('sources')]
    public function testDownRestoresLegacyTypeAndIndexesAndUpReadsBackWholeSeconds(
        string $unit,
        string $column,
        string $legacy,
        bool $nullable,
    ): void {
        $indexes = self::indexes($unit);
        $foreignKeys = self::structure($unit)->foreignKeys;
        self::seed($unit, $column, 1, 1000000000123);
        self::seed($unit, $column, 2, 1000);
        self::seed($unit, $column, 3, 2147483647999);
        if ($nullable) {
            self::seed($unit, $column, 4, null);
        }
        self::runDelivered($unit, down: true);

        $row = self::column($unit, $column);
        $this->assertSame('timestamp', $row['Type']);
        $this->assertSame($nullable ? 'YES' : 'NO', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertArrayNotHasKey('__hil1925_' . $column, array_column(self::columns($unit), null, 'Field'));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $stored = ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'];
        if ($nullable) {
            $stored[] = null;
        }
        $this->assertSame($stored, self::stored($unit, $column));
        self::runDelivered($unit);

        $expected = [1000000000000, 1000, 2147483647000];
        if ($nullable) {
            $expected[] = null;
        }
        $this->assertSame($expected, self::moments($unit, $column));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertEquals($foreignKeys, self::structure($unit)->foreignKeys);
        $this->assertMoment($unit, $column, $nullable);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param bool $nullable Whether the moment may be absent
     * @param int $moment Unrepresentable legacy value
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeChangingRowsOrSchema(string $unit, string $column, bool $nullable, int $moment): void
    {
        self::seed($unit, $column, 1, $moment);
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
        $this->assertMoment($unit, $column, $nullable);
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param bool $nullable Whether the moment may be absent
     * @param ?int $moment Exact epoch milliseconds or an absent optional time
     */
    #[DataProvider('roundTripMoments')]
    public function testEntityObjectAndViewKeepExactSignedMilliseconds(string $unit, string $column, bool $nullable, ?int $moment): void
    {
        $entity = self::entity($unit);
        $entity->$column = $moment;
        $entity->save();
        $this->assertSame([$moment], self::moments($unit, $column));
        $read = $entity::getById($entity->{$entity::_primary});
        $this->assertSame($moment, $read->$column);
        [$object, $viewClass, $field] = match ($unit) {
            'legal_acceptance' => [ObjectLegalAcceptance::fromEntity($read), ViewLegalAcceptance::class, ObjectLegalAcceptance::acceptedAt],
            'data_export' => [ObjectDataExport::fromEntity($read), ViewDataExport::class, match ($column) {
                DataExport::requested_at => ObjectDataExport::requestedAt,
                DataExport::finished_at => ObjectDataExport::finishedAt,
                DataExport::expires_at => ObjectDataExport::expiresAt,
            }],
            'legal_acceptance_export' => [ObjectLegalAcceptanceExport::fromEntity($read), ViewLegalAcceptanceExport::class, match ($column) {
                LegalAcceptanceExport::requested_at => ObjectLegalAcceptanceExport::requestedAt,
                LegalAcceptanceExport::finished_at => ObjectLegalAcceptanceExport::finishedAt,
                LegalAcceptanceExport::expires_at => ObjectLegalAcceptanceExport::expiresAt,
            }],
        };
        $this->assertSame($moment, $object->$field);
        $this->assertSame($moment, $object->toArray()[$field]);
        // A stored acceptance refuses every write, so the setter is checked on a new record, the one it serves;
        // the end and the expiry of either queue have no setter: only its conditional completion writes them.
        if ($unit === 'legal_acceptance' || $column === DataExport::requested_at) {
            $written = $object::create();
            $written->$field = $moment;
            $this->assertSame($moment, $written->$field);
        }
        $this->assertSame($moment, new $viewClass($object)->$field);
        $this->assertMoment($unit, $column, $nullable);
    }

    /** Starts each case with the current stubs, including the referenced people: one copy each. */
    protected function setUp(): void
    {
        parent::setUp();
        self::dropFixtureTables();
        self::runStub('user');
        foreach (self::units() as $unit) {
            self::runStub($unit);
        }
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1, 'One'), (2, 'Two'), (3, 'Three'), (4, 'Four')");
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
     * @param bool $nullable Whether the moment may be absent
     */
    private function assertMoment(string $unit, string $column, bool $nullable): void
    {
        $row = self::column($unit, $column);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
        $this->assertSame($nullable ? 'YES' : 'NO', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertArrayNotHasKey('__hil1925_' . $column, array_column(self::columns($unit), null, 'Field'));
    }

    /** @return list<string> Units of the sources, each once, in their order */
    private static function units(): array
    {
        return array_values(array_unique(array_column(iterator_to_array(self::sources()), 0)));
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment under test
     * @param int $row Row number, unique within the case, and the person of a copy
     * @param int|string|null $moment Value in the current physical representation
     */
    private static function seed(string $unit, string $column, int $row, int|string|null $moment): void
    {
        match ($unit) {
            'legal_acceptance' => Database::sql(
                'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
                [1, 'terms', 'r' . $row, $moment],
            ),
            'data_export', 'legal_acceptance_export' => self::seedExport($unit, $row, $column, $moment),
        };
    }

    /**
     * Both queues name their state and moment columns alike, so the copy queue's names serve either.
     *
     * @param string $unit Queue's schema unit
     * @param int $person Order's person
     * @param string $column Moment under test
     * @param int|string|null $moment Current storage representation
     */
    private static function seedExport(string $unit, int $person, string $column, int|string|null $moment): void
    {
        $requestedAt = str_starts_with(self::column($unit, DataExport::requested_at)['Type'], 'timestamp')
            ? '2001-09-09 01:46:40' : 1000000000123;
        Database::sql(
            'INSERT INTO `hilos_' . $unit . '` (user_id, state, requested_at, finished_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$person, DataExportState::READY, $column === DataExport::requested_at ? $moment : $requestedAt,
                $column === DataExport::finished_at ? $moment : null,
                $column === DataExport::expires_at ? $moment : null],
        );
    }

    /**
     * @param string $unit Schema unit
     * @return Entity A new row of the unit with every column but its moment filled
     */
    private static function entity(string $unit): Entity
    {
        $entity = match ($unit) {
            'legal_acceptance' => new LegalAcceptance(),
            'data_export' => new DataExport(),
            'legal_acceptance_export' => new LegalAcceptanceExport(),
        };
        if ($entity instanceof LegalAcceptance) {
            $entity->user_id = 1;
            $entity->document = 'terms';
            $entity->revision_id = 'r1';
        }
        if ($entity instanceof DataExport) {
            $entity->user_id = 1;
            $entity->state = DataExportState::READY;
            $entity->requested_at = 1000000000123;
        }
        if ($entity instanceof LegalAcceptanceExport) {
            $entity->user_id = 1;
            $entity->state = LegalAcceptancesExportState::READY;
            $entity->requested_at = 1000000000123;
        }

        return $entity;
    }

    /**
     * @param string $unit Schema unit
     * @param string $column Moment column
     * @param string $legacy Original physical declaration
     */
    private static function restoreLegacyColumns(string $unit, string $column, string $legacy): void
    {
        if ($unit !== 'legal_acceptance') {
            Database::sql('ALTER TABLE `hilos_' . $unit . '` MODIFY requested_at TIMESTAMP NOT NULL,'
                . ' MODIFY finished_at TIMESTAMP NULL DEFAULT NULL, MODIFY expires_at TIMESTAMP NULL DEFAULT NULL');
            return;
        }
        Database::sql('ALTER TABLE `hilos_' . $unit . '` MODIFY `' . $column . '` ' . $legacy);
    }

    /** Drops the case's tables in reverse foreign-key order. */
    private static function dropFixtureTables(): void
    {
        foreach ([...array_reverse(self::units()), 'user'] as $unit) {
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
     * @return array<string, list<string>> Indexed columns by key, in their order
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
     * @return list<array<string, mixed>> Rows in id order
     */
    private static function rows(string $unit): array
    {
        return Database::sql('SELECT * FROM `hilos_' . $unit . '` ORDER BY id')->rows();
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
