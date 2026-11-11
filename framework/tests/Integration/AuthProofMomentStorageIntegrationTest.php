<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\RegistrationReservation;
use Hilos\Database\Entity\Item\StepUp;
use Hilos\Database\Entity\Item\UserVerification;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Verification\VerificationType;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real DDL over the four delivered auth-proof parts, so outside rollback scopes: every case changes
 * a column's physical type. The SQL run here is the generated pair a project receives, verified
 * against the immutable catalog, and every case takes the schema unit it judges: the verification
 * challenge, the registration hold, the step-up confirmation and the throttle block.
 *
 * Moments are listed per unit in the order their columns stand in the stub, and every seeded or
 * expected row of a case is written in that same order.
 */
final class AuthProofMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261111013234414363_HIL-1921_auth_proof_moments';
    private const string STAGING_PREFIX = '__hil1921_';

    private const string VERIFICATION_UNIT = 'user_verification';
    private const string RESERVATION_UNIT = 'registration_reservation';
    private const string STEP_UP_UNIT = 'step_up';
    private const string BLOCK_UNIT = 'auth_block';

    private const string LEGACY_REQUIRED = 'TIMESTAMP NOT NULL';
    private const string LEGACY_OPTIONAL = 'TIMESTAMP NULL DEFAULT NULL';
    private const string LEGACY_STAMPED = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
    private const string LEGACY_TOUCHED = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

    /** @var array<string, class-string<Entity>> Entity of the table each delivered part converts, by schema unit */
    private const array ENTITIES = [
        self::VERIFICATION_UNIT => UserVerification::class,
        self::RESERVATION_UNIT => RegistrationReservation::class,
        self::STEP_UP_UNIT => StepUp::class,
        self::BLOCK_UNIT => AuthBlock::class,
    ];

    /** @var array<string, string> Table each delivered part converts, by schema unit */
    private const array TABLES = [
        self::VERIFICATION_UNIT => UserVerification::_table,
        self::RESERVATION_UNIT => RegistrationReservation::_table,
        self::STEP_UP_UNIT => StepUp::_table,
        self::BLOCK_UNIT => AuthBlock::_table,
    ];

    /**
     * Every moment column with the definition projects held before this change, in stub order, by schema unit.
     *
     * A column held as {@see LEGACY_OPTIONAL} is the one that stays nullable as BIGINT.
     *
     * @var array<string, array<string, string>>
     */
    private const array LEGACY_MOMENTS = [
        self::VERIFICATION_UNIT => [
            UserVerification::expires_at => self::LEGACY_REQUIRED,
            UserVerification::consumed_at => self::LEGACY_OPTIONAL,
            UserVerification::created_at => self::LEGACY_STAMPED,
        ],
        self::RESERVATION_UNIT => [
            RegistrationReservation::code_accepted_at => self::LEGACY_OPTIONAL,
            RegistrationReservation::expires_at => self::LEGACY_REQUIRED,
            RegistrationReservation::created_at => self::LEGACY_STAMPED,
        ],
        self::STEP_UP_UNIT => [
            StepUp::confirmed_until => self::LEGACY_REQUIRED,
            StepUp::created_at => self::LEGACY_STAMPED,
        ],
        self::BLOCK_UNIT => [
            AuthBlock::blocked_until => self::LEGACY_OPTIONAL,
            AuthBlock::updated_at => self::LEGACY_TOUCHED,
        ],
    ];

    /** @var array<string, array{string, ?string, string}> Null, Default and Extra SHOW COLUMNS prints for each legacy definition */
    private const array LEGACY_SHAPES = [
        self::LEGACY_REQUIRED => ['NO', null, ''],
        self::LEGACY_OPTIONAL => ['YES', null, ''],
        self::LEGACY_STAMPED => ['NO', 'current_timestamp()', ''],
        self::LEGACY_TOUCHED => ['NO', 'current_timestamp()', 'on update current_timestamp()'],
    ];

    private const string USER_STUB = 'create_hilos_user';

    /** Person the step-up rows are recorded on. */
    private const int USER_ID = 1921;

    /**
     * Starts from absent tables: none of the four, nor the person two of them point at, is part of the shared test
     * schema. The person is raised once here, since every case keeps it while it drops and raises a part's table.
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::dropFixtureTables();
        self::runStub(self::USER_STUB);
        Database::sql("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Step-up owner')", [self::USER_ID]);
    }

    /** Leaves the shared test database as it was found. */
    protected function tearDown(): void
    {
        self::dropFixtureTables();
        parent::tearDown();
    }

    /** @param string $unit Schema unit of the part under test */
    #[DataProvider('units')]
    public function testFreshMomentsAreSignedBigintWithoutSqlWriters(string $unit): void
    {
        self::fresh($unit);

        $this->assertMoments($unit);
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param list<list<?string>> $legacy Rows as projects hold them, moments in stub order
     * @param list<list<?int>> $expected The same rows in epoch milliseconds after the delivered UP
     */
    #[DataProvider('legacyRows')]
    public function testDeliveredUpConvertsLegacyRowsToExactMillisecondsAndKeepsAbsence(string $unit, array $legacy, array $expected): void
    {
        $this->legacy($unit);
        foreach ($legacy as $ordinal => $moments) {
            self::seed($unit, $ordinal, $moments);
        }

        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered($unit);
        Database::sql("SET time_zone = '+00:00'");

        $this->assertSame($expected, self::moments($unit));
        $this->assertMoments($unit);
    }

    /**
     * @return iterable<string, array{string, list<list<?string>>, list<list<?int>>}> Legacy rows of each part and their
     *   converted moments; the second row leaves its optional moment absent
     */
    public static function legacyRows(): iterable
    {
        yield self::VERIFICATION_UNIT => [
            self::VERIFICATION_UNIT,
            [['2030-06-15 12:34:56', '2001-09-09 01:46:40', '1970-01-01 00:00:01'], ['2001-09-09 01:46:41', null, '2030-06-15 12:34:56']],
            [[1907757296000, 1000000000000, 1000], [1000000001000, null, 1907757296000]],
        ];
        yield self::RESERVATION_UNIT => [
            self::RESERVATION_UNIT,
            [['2030-06-15 12:34:56', '2001-09-09 01:46:40', '1970-01-01 00:00:01'], [null, '2001-09-09 01:46:41', '2030-06-15 12:34:56']],
            [[1907757296000, 1000000000000, 1000], [null, 1000000001000, 1907757296000]],
        ];
        yield self::STEP_UP_UNIT => [
            self::STEP_UP_UNIT,
            [['2030-06-15 12:34:56', '2001-09-09 01:46:40'], ['1970-01-01 00:00:01', '2001-09-09 01:46:41']],
            [[1907757296000, 1000000000000], [1000, 1000000001000]],
        ];
        yield self::BLOCK_UNIT => [
            self::BLOCK_UNIT,
            [['2030-06-15 12:34:56', '2001-09-09 01:46:40'], [null, '1970-01-01 00:00:01']],
            [[1907757296000, 1000000000000], [null, 1000]],
        ];
    }

    /** @param string $unit Schema unit of the part under test */
    #[DataProvider('units')]
    public function testDeliveredUpEndsWithTheColumnsAndIndexesOfTheCurrentStub(string $unit): void
    {
        self::fresh($unit);
        $columns = self::columns($unit);
        $indexes = self::indexes($unit);
        self::runStub(self::stub($unit) . '_down');
        $this->legacy($unit);

        self::runDelivered($unit);

        $this->assertSame($columns, self::columns($unit));
        $this->assertSame($indexes, self::indexes($unit));
    }

    /** @param string $unit Schema unit of the part under test */
    #[DataProvider('units')]
    public function testRestorePlannerReplaysEveryDeliveredUpStatement(string $unit): void
    {
        $this->legacy($unit);
        $structure = self::structure($unit);

        $this->replayDeliveredUp($unit, $structure, 3);

        self::runStub(self::stub($unit) . '_down');
        self::fresh($unit);
        $fresh = self::structure($unit);
        $this->assertEquals($fresh->columns, $structure->columns);
        $this->assertEquals($fresh->keys, $structure->keys);
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param list<list<?int>> $rows Rows in epoch milliseconds, moments in stub order
     * @param list<list<?string>> $stored The same rows as the legacy columns print them after DOWN
     * @param list<list<?int>> $readBack The same rows after UP again, down to the second
     */
    #[DataProvider('representableRows')]
    public function testDownRestoresTheLegacyColumnsAndIndexesAndUpReadsThemBackToTheSecond(
        string $unit,
        array $rows,
        array $stored,
        array $readBack,
    ): void {
        $this->legacy($unit);
        $legacyColumns = self::columns($unit);
        self::runStub(self::stub($unit) . '_down');
        self::fresh($unit);
        $indexes = self::indexes($unit);
        foreach ($rows as $ordinal => $moments) {
            self::seed($unit, $ordinal, $moments);
        }

        self::runDelivered($unit, down: true);

        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $this->assertLegacy($unit, $column, $definition);
        }
        $this->assertSame($legacyColumns, self::columns($unit));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertSame($stored, self::stored($unit));

        self::runDelivered($unit);

        $this->assertSame($readBack, self::moments($unit));
        $this->assertMoments($unit);
    }

    /**
     * @return iterable<string, array{string, list<list<?int>>, list<list<?string>>, list<list<?int>>}> Millisecond rows of
     *   each part inside the TIMESTAMP range, what DOWN stores for them, and what UP reads back
     */
    public static function representableRows(): iterable
    {
        yield self::VERIFICATION_UNIT => [
            self::VERIFICATION_UNIT,
            [[1000000000123, 1000, 2147483647999], [1000, null, 1000000001999]],
            [['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'], ['1970-01-01 00:00:01', null, '2001-09-09 01:46:41']],
            [[1000000000000, 1000, 2147483647000], [1000, null, 1000000001000]],
        ];
        yield self::RESERVATION_UNIT => [
            self::RESERVATION_UNIT,
            [[1000000000123, 1000, 2147483647999], [null, 1000, 1000000001999]],
            [['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'], [null, '1970-01-01 00:00:01', '2001-09-09 01:46:41']],
            [[1000000000000, 1000, 2147483647000], [null, 1000, 1000000001000]],
        ];
        yield self::STEP_UP_UNIT => [
            self::STEP_UP_UNIT,
            [[1000000000123, 2147483647999], [1000, 1000000001999]],
            [['2001-09-09 01:46:40', '2038-01-19 03:14:07'], ['1970-01-01 00:00:01', '2001-09-09 01:46:41']],
            [[1000000000000, 2147483647000], [1000, 1000000001000]],
        ];
        yield self::BLOCK_UNIT => [
            self::BLOCK_UNIT,
            [[1000000000123, 2147483647999], [null, 1000]],
            [['2001-09-09 01:46:40', '2038-01-19 03:14:07'], [null, '1970-01-01 00:00:01']],
            [[1000000000000, 2147483647000], [null, 1000]],
        ];
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param string $column Moment column holding the value
     * @param int $moment Stored value the legacy TIMESTAMP cannot hold
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeReplacingAnUnrepresentableMoment(string $unit, string $column, int $moment): void
    {
        self::fresh($unit);
        $moments = array_fill_keys(array_keys(self::LEGACY_MOMENTS[$unit]), 1000);
        $moments[$column] = $moment;
        self::seed($unit, 0, array_values($moments));
        $before = self::rows($unit);

        try {
            self::runDelivered($unit, down: true);
            $this->fail('A value outside the TIMESTAMP range must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }

        $this->assertSame($before, self::rows($unit));
        $this->assertMoments($unit);
    }

    /** @return iterable<string, array{string, string, int}> Each moment column of each part with each value outside TIMESTAMP(0) */
    public static function unrepresentableMoments(): iterable
    {
        foreach (self::LEGACY_MOMENTS as $unit => $moments) {
            foreach (array_keys($moments) as $column) {
                yield $unit . '.' . $column . ' under the first second' => [$unit, $column, 999];
                yield $unit . '.' . $column . ' after 2038' => [$unit, $column, 2147483648000];
            }
        }
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param int $moment Epoch milliseconds written into every moment through the Entity and read back
     */
    #[DataProvider('roundTripMoments')]
    public function testEntityRoundTripsExactMoments(string $unit, int $moment): void
    {
        self::fresh($unit);
        $entity = self::entity($unit, $moment);
        $entity->save();

        $moments = array_fill(0, count(self::LEGACY_MOMENTS[$unit]), $moment);
        $this->assertSame([$moments], self::moments($unit));
        $read = $entity::getById($entity->id)?->toArray();
        $this->assertNotNull($read);
        $this->assertSame($moments, array_map(static fn (string $column): mixed => $read[$column], array_keys(self::LEGACY_MOMENTS[$unit])));
    }

    /** @return iterable<string, array{string, int}> Signed moments a BIGINT must keep exactly, for each part */
    public static function roundTripMoments(): iterable
    {
        foreach (array_keys(self::TABLES) as $unit) {
            yield $unit . ' at the first millisecond' => [$unit, 1];
            yield $unit . ' at the epoch' => [$unit, 0];
            yield $unit . ' before the epoch' => [$unit, -1];
            yield $unit . ' in 2025 with milliseconds' => [$unit, 1735689600123];
            yield $unit . ' in 2100 with milliseconds' => [$unit, 4102444800123];
        }
    }

    /** @return iterable<string, array{string}> Every schema unit this change delivers */
    public static function units(): iterable
    {
        foreach (array_keys(self::TABLES) as $unit) {
            yield $unit => [$unit];
        }
    }

    /**
     * Every moment of the part is signed BIGINT with no SQL writer, in the nullability its column carries.
     *
     * @param string $unit Schema unit whose table is judged
     */
    private function assertMoments(string $unit): void
    {
        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $row = self::column($unit, $column);
            $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type'], $column);
            $this->assertSame($definition === self::LEGACY_OPTIONAL ? 'YES' : 'NO', $row['Null'], $column);
            $this->assertNull($row['Default'], $column);
            $this->assertSame('', $row['Extra'], $column);
            $this->assertStagingAbsent($unit, $column);
        }
    }

    /**
     * @param string $unit Schema unit whose table is judged
     * @param string $column Moment column that must be back in its legacy form
     * @param string $definition Legacy definition the column carried before this change
     */
    private function assertLegacy(string $unit, string $column, string $definition): void
    {
        $row = self::column($unit, $column);
        $this->assertSame('timestamp', $row['Type'], $column);
        $this->assertSame(self::LEGACY_SHAPES[$definition], [$row['Null'], $row['Default'], $row['Extra']], $column);
        $this->assertStagingAbsent($unit, $column);
    }

    /**
     * @param string $unit Schema unit a conversion ran over
     * @param string $column Moment column whose staging column must be gone
     */
    private function assertStagingAbsent(string $unit, string $column): void
    {
        $this->assertArrayNotHasKey(self::STAGING_PREFIX . $column, array_column(self::columns($unit), null, 'Field'));
    }

    /**
     * Applies every statement of a delivered UP to the planner's model of the table, as a full restore does.
     *
     * @param string $unit Schema unit whose delivered part is replayed
     * @param TableStructure $structure Model of the table before the part
     * @param int $statementCount Flat statements the part is delivered as
     */
    private function replayDeliveredUp(string $unit, TableStructure $structure, int $statementCount): void
    {
        $statements = MigrationSqlReader::statements(self::deliveredPair($unit)[0]);

        $this->assertCount($statementCount, $statements);
        foreach ($statements as $statement) {
            foreach (MigrationOperationReader::read($statement, self::database()) as $operation) {
                $this->assertNotSame(MigrationOperationKind::UNKNOWN, $operation->kind, $statement);
                if ($operation->kind === MigrationOperationKind::ALTER_TABLE) {
                    $structure->alter($operation);
                }
            }
        }
    }

    /**
     * Raises the part's table as projects hold it before this change.
     *
     * @param string $unit Schema unit of the part
     */
    private function legacy(string $unit): void
    {
        self::fresh($unit);
        $changes = [];
        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $changes[] = 'MODIFY `' . $column . '` ' . $definition;
        }
        Database::sql('ALTER TABLE `' . self::TABLES[$unit] . '` ' . implode(', ', $changes));
    }

    /**
     * Raises the part's table as the current stub defines it.
     *
     * @param string $unit Schema unit of the part
     */
    private static function fresh(string $unit): void
    {
        self::runStub(self::stub($unit));
    }

    /**
     * Writes one row of the part's table with its moments in their current representation.
     *
     * @param string $unit Schema unit of the part
     * @param int $ordinal Position of the row in its case, which keeps its unique key apart from the others
     * @param list<int|string|null> $moments Stored values in stub order of the moment columns
     */
    private static function seed(string $unit, int $ordinal, array $moments): void
    {
        $row = self::keyColumns($unit, $ordinal) + array_combine(array_keys(self::LEGACY_MOMENTS[$unit]), $moments);
        Database::sql(
            'INSERT INTO `' . self::TABLES[$unit] . '` (`' . implode('`, `', array_keys($row)) . '`)'
                . ' VALUES (?' . str_repeat(', ?', count($row) - 1) . ')',
            array_values($row),
        );
    }

    /**
     * @param string $unit Schema unit of the part
     * @param int $ordinal Position of the row in its case
     * @return array<string, int|string> Required columns other than moments, unique per ordinal where the table asks it
     */
    private static function keyColumns(string $unit, int $ordinal): array
    {
        return match ($unit) {
            self::VERIFICATION_UNIT => [
                UserVerification::type => VerificationType::REGISTER_CONFIRM,
                UserVerification::identifier => 'proof' . $ordinal . '@example.test',
            ],
            self::RESERVATION_UNIT => [
                RegistrationReservation::type => IdentityType::PASSWORD,
                RegistrationReservation::identifier => 'proof' . $ordinal . '@example.test',
                RegistrationReservation::session_token => 'proof-session-' . $ordinal,
            ],
            self::STEP_UP_UNIT => [
                StepUp::session_token_hash => hash('sha256', 'proof-session-' . $ordinal),
                StepUp::user_id => self::USER_ID,
                StepUp::operation => StepUpOperationKey::DELETE_ACCOUNT,
            ],
            self::BLOCK_UNIT => [
                AuthBlock::scope => ThrottleScope::IP,
                AuthBlock::identity => '203.0.113.' . $ordinal,
                AuthBlock::action => 'sign_in',
            ],
        };
    }

    /**
     * @param string $unit Schema unit of the part
     * @param int $moment Epoch milliseconds every moment of the row carries
     * @return Entity A new row of the part's table, not yet saved
     */
    private static function entity(string $unit, int $moment): Entity
    {
        $entity = new (self::ENTITIES[$unit])();
        foreach (self::keyColumns($unit, 0) + array_fill_keys(array_keys(self::LEGACY_MOMENTS[$unit]), $moment) as $column => $value) {
            $entity->{$column} = $value;
        }

        return $entity;
    }

    /** Drops every table a case may have raised, the two pointing at a person before the person table. */
    private static function dropFixtureTables(): void
    {
        foreach (self::TABLES as $table) {
            self::runStub('create_' . $table . '_down');
        }
        self::runStub(self::USER_STUB . '_down');
    }

    /**
     * @param string $unit Schema unit of the part
     * @return string File name of the unit's create stub, without extension
     */
    private static function stub(string $unit): string
    {
        return 'create_' . self::TABLES[$unit];
    }

    /** @param string $stub Stub file name without extension */
    private static function runStub(string $stub): void
    {
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $stub . '.sql'));
    }

    /**
     * @param string $unit Schema unit whose delivered part runs
     * @param bool $down Whether to run the part's down direction
     */
    private static function runDelivered(string $unit, bool $down = false): void
    {
        foreach (MigrationSqlReader::statements(self::deliveredPair($unit)[$down ? 1 : 0]) as $statement) {
            Database::sql($statement);
        }
    }

    /**
     * @param string $unit Schema unit of this change
     * @return array{0: string, 1: string} Up and down text of the pair the chat demo received, verified against the catalog
     */
    private static function deliveredPair(string $unit): array
    {
        $reference = '"changeId":"' . self::CHANGE . '","unit":"' . $unit . '"';
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            if (str_ends_with($file, '_down.sql') || !str_contains((string)file_get_contents($file), $reference)) {
                continue;
            }
            $up = (string)file_get_contents($file);
            $down = (string)file_get_contents(substr($file, 0, -4) . '_down.sql');
            new FrameworkMigrationCatalog(new FrameworkSchemaCatalog())->verifyPair($up, $down);

            return [$up, $down];
        }
        self::fail('The chat demo did not receive ' . $unit . ' of ' . self::CHANGE);
    }

    /** @return string Schema the primary connection executes in */
    private static function database(): string
    {
        return Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return TableStructure The part's table as the restore planner models it from its live definition
     */
    private static function structure(string $unit): TableStructure
    {
        $collations = array_column(
            Database::sql('SELECT COLLATION_NAME, CHARACTER_SET_NAME FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY')->rows(),
            'CHARACTER_SET_NAME',
            'COLLATION_NAME',
        );
        $create = Database::sql('SHOW CREATE TABLE `' . self::TABLES[$unit] . '`')->row()['Create Table'];

        return TableStructure::read(
            $create,
            self::database(),
            new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', charsetByCollation: $collations),
        );
    }

    /**
     * @param string $unit Schema unit of the part
     * @return array<string, list<string>> Columns of every index of its table in key order, by index name
     */
    private static function indexes(string $unit): array
    {
        $indexes = [];
        foreach (Database::sql('SHOW INDEX FROM `' . self::TABLES[$unit] . '`')->rows() as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }
        ksort($indexes);

        return $indexes;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<array<string, mixed>> Columns of its table in physical order
     */
    private static function columns(string $unit): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . self::TABLES[$unit] . '`')->rows();
    }

    /**
     * @param string $unit Schema unit of the part
     * @param string $column Column to describe
     * @return array<string, mixed> Its SHOW COLUMNS row
     */
    private static function column(string $unit, string $column): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . self::TABLES[$unit] . '` LIKE ?', [$column])->row();
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<array<string, mixed>> Every row of its table, oldest first
     */
    private static function rows(string $unit): array
    {
        return Database::sql('SELECT * FROM `' . self::TABLES[$unit] . '` ORDER BY `id`')->rows();
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<list<?string>> Its moments as the database prints them, in stub order, oldest row first
     */
    private static function stored(string $unit): array
    {
        $columns = array_keys(self::LEGACY_MOMENTS[$unit]);

        return array_map(
            static fn (array $row): array => array_map(static fn (string $column): ?string => $row[$column], $columns),
            self::rows($unit),
        );
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<list<?int>> Its moments in epoch milliseconds, in stub order, oldest row first
     */
    private static function moments(string $unit): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (?string $value): ?int => $value === null ? null : (int)$value, $row),
            self::stored($unit),
        );
    }
}
