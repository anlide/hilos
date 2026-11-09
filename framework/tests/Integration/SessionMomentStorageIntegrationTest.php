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
use Hilos\Database\Entity\Item\Session;
use Hilos\Database\Entity\Item\VerifierCircleMember;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real DDL over the delivered session-moment parts, so outside rollback scopes: every case changes
 * a column's physical type. The SQL run here is the generated pair a project receives, verified
 * against the immutable catalog, and the helpers take the table and the column they judge.
 */
final class SessionMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261109120150360881_HIL-1919_session_moments';
    private const string STAGING_PREFIX = '__hil1919_';
    private const string LEGACY_REQUIRED = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

    private const string CIRCLE_UNIT = 'verifier_circle';
    private const string CIRCLE_STUB = 'create_hilos_verifier_circle';
    private const string CIRCLE_TABLE = VerifierCircleMember::_table;
    private const string CIRCLE_MOMENT = VerifierCircleMember::created_at;
    private const string ANN_EMAIL = 'ann@example.test';
    private const string BOB_EMAIL = 'bob@example.test';
    private const string CARL_EMAIL = 'carl@example.test';

    private const string LEGACY_OPTIONAL = 'TIMESTAMP NULL DEFAULT NULL';
    private const string SESSION_UNIT = 'session';
    private const string SESSION_STUB = 'create_hilos_session';
    private const string USER_STUB = 'create_hilos_user';
    private const string SESSION_TABLE = Session::_table;
    private const array SESSION_REQUIRED_MOMENTS = [Session::created_at, Session::last_seen_at];
    private const array SESSION_OPTIONAL_MOMENTS = [Session::expires_at, Session::pending_registration_since, Session::pending_second_factor_until];
    private const array SESSION_MOMENTS = [...self::SESSION_REQUIRED_MOMENTS, ...self::SESSION_OPTIONAL_MOMENTS];
    private const string TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** Starts from absent tables: neither the circle nor the session is part of the shared test schema. */
    protected function setUp(): void
    {
        parent::setUp();
        self::dropFixtureTables();
    }

    /** Leaves the shared test database as it was found. */
    protected function tearDown(): void
    {
        self::dropFixtureTables();
        parent::tearDown();
    }

    public function testFreshCircleIsSignedBigintWithoutSqlWriters(): void
    {
        self::runStub(self::CIRCLE_STUB);

        $this->assertMoment(self::CIRCLE_TABLE, self::CIRCLE_MOMENT);
    }

    public function testDeliveredUpConvertsLegacyRowsToExactMillisecondsInAnyConnectionZone(): void
    {
        $this->legacyCircle();
        self::seedCircle(self::ANN_EMAIL, '2030-06-15 12:34:56');
        self::seedCircle(self::BOB_EMAIL, '2001-09-09 01:46:40');
        self::seedCircle(self::CARL_EMAIL, '1970-01-01 00:00:01');

        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered(self::CIRCLE_UNIT);
        Database::sql("SET time_zone = '+00:00'");

        $this->assertSame([1907757296000, 1000000000000, 1000], self::moments(self::CIRCLE_TABLE, self::CIRCLE_MOMENT));
        $this->assertMoment(self::CIRCLE_TABLE, self::CIRCLE_MOMENT);
    }

    public function testDeliveredUpEndsWithTheColumnsOfTheCurrentStub(): void
    {
        self::runStub(self::CIRCLE_STUB);
        $fresh = self::columns(self::CIRCLE_TABLE);
        self::runStub(self::CIRCLE_STUB . '_down');
        $this->legacyCircle();

        self::runDelivered(self::CIRCLE_UNIT);

        $this->assertSame($fresh, self::columns(self::CIRCLE_TABLE));
    }

    public function testRestorePlannerReplaysEveryDeliveredUpStatement(): void
    {
        $this->legacyCircle();
        $structure = self::structure(self::CIRCLE_TABLE);
        $this->replayDeliveredUp(self::CIRCLE_UNIT, $structure, 3);

        self::runStub(self::CIRCLE_STUB . '_down');
        self::runStub(self::CIRCLE_STUB);
        $this->assertEquals(self::structure(self::CIRCLE_TABLE)->columns, $structure->columns);
    }

    public function testDownRestoresTheLegacyColumnAndUpReadsItBackToTheSecond(): void
    {
        self::runStub(self::CIRCLE_STUB);
        self::seedCircle(self::ANN_EMAIL, 1000000000123);
        self::seedCircle(self::BOB_EMAIL, 1000);
        self::seedCircle(self::CARL_EMAIL, 2147483647999);

        self::runDelivered(self::CIRCLE_UNIT, down: true);

        $this->assertLegacyRequired(self::CIRCLE_TABLE, self::CIRCLE_MOMENT);
        $this->assertSame(
            ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'],
            self::stored(self::CIRCLE_TABLE, self::CIRCLE_MOMENT),
        );

        self::runDelivered(self::CIRCLE_UNIT);

        $this->assertSame([1000000000000, 1000, 2147483647000], self::moments(self::CIRCLE_TABLE, self::CIRCLE_MOMENT));
        $this->assertMoment(self::CIRCLE_TABLE, self::CIRCLE_MOMENT);
    }

    /** @param int $moment Stored value the legacy TIMESTAMP cannot hold */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeReplacingAnUnrepresentableMoment(int $moment): void
    {
        self::runStub(self::CIRCLE_STUB);
        self::seedCircle(self::ANN_EMAIL, $moment);
        $before = self::rows(self::CIRCLE_TABLE);

        try {
            self::runDelivered(self::CIRCLE_UNIT, down: true);
            $this->fail('A value outside the TIMESTAMP range must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }

        $this->assertSame($before, self::rows(self::CIRCLE_TABLE));
        $this->assertMoment(self::CIRCLE_TABLE, self::CIRCLE_MOMENT);
    }

    /** @return iterable<string, array{int}> Moments outside what TIMESTAMP(0) stores */
    public static function unrepresentableMoments(): iterable
    {
        yield 'the epoch' => [0];
        yield 'under the first second' => [999];
        yield 'before the epoch' => [-1];
        yield 'after 2038' => [2147483648000];
    }

    /** @param int $moment Epoch milliseconds written and read through the Entity */
    #[DataProvider('roundTripMoments')]
    public function testEntityRoundTripsExactMoments(int $moment): void
    {
        self::runStub(self::CIRCLE_STUB);
        $member = new VerifierCircleMember();
        $member->identity_type = IdentityType::PASSWORD;
        $member->identifier = self::ANN_EMAIL;
        $member->created_at = $moment;
        $member->save();

        $this->assertSame([$moment], self::moments(self::CIRCLE_TABLE, self::CIRCLE_MOMENT));
        $this->assertSame($moment, VerifierCircleMember::getById($member->id)?->created_at);
    }

    /** @return iterable<string, array{int}> Signed moments a BIGINT must keep exactly */
    public static function roundTripMoments(): iterable
    {
        yield 'the epoch' => [0];
        yield 'before the epoch with milliseconds' => [-1234567890123];
        yield 'after 2038 with milliseconds' => [2147483648001];
    }

    public function testFreshSessionMomentsAreSignedBigintWithoutSqlWriters(): void
    {
        self::freshSession();

        $this->assertSessionMoments();
    }

    public function testDeliveredUpConvertsLegacySessionRowsAndKeepsAbsenceAndTheFirstHandshakeProof(): void
    {
        self::legacySession();
        self::seedSession(self::TOKEN_A, [Session::created_at => '2030-06-15 12:34:56', Session::last_seen_at => '2030-06-15 12:34:56']);
        self::seedSession(self::TOKEN_B, [
            Session::created_at => '2001-09-09 01:46:40',
            Session::last_seen_at => '2030-06-15 12:34:56',
            Session::expires_at => '1970-01-01 00:00:01',
            Session::pending_registration_since => '2001-09-09 01:46:41',
            Session::pending_second_factor_until => '2001-09-09 01:46:42',
        ]);

        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered(self::SESSION_UNIT);
        Database::sql("SET time_zone = '+00:00'");

        $this->assertSame([
            [1907757296000, 1907757296000, null, null, null],
            [1000000000000, 1907757296000, 1000, 1000000001000, 1000000002000],
        ], self::sessionMoments());
        $this->assertSessionMoments();
    }

    public function testDeliveredSessionUpEndsWithTheColumnsAndIndexesOfTheCurrentStub(): void
    {
        self::freshSession();
        $columns = self::columns(self::SESSION_TABLE);
        $indexes = self::indexes(self::SESSION_TABLE);
        self::dropFixtureTables();
        self::legacySession();

        self::runDelivered(self::SESSION_UNIT);

        $this->assertSame($columns, self::columns(self::SESSION_TABLE));
        $this->assertSame($indexes, self::indexes(self::SESSION_TABLE));
    }

    public function testRestorePlannerReplaysEverySessionUpStatement(): void
    {
        self::legacySession();
        $structure = self::structure(self::SESSION_TABLE);

        $this->replayDeliveredUp(self::SESSION_UNIT, $structure, 5);

        self::dropFixtureTables();
        self::freshSession();
        $fresh = self::structure(self::SESSION_TABLE);
        $this->assertEquals($fresh->columns, $structure->columns);
        $this->assertEquals($fresh->keys, $structure->keys);
    }

    public function testSessionDownRestoresTheLegacyColumnsAndIndexesAndUpReadsThemBackToTheSecond(): void
    {
        self::freshSession();
        self::seedSession(self::TOKEN_A, [
            Session::created_at => 1000000000123,
            Session::last_seen_at => 1000,
            Session::expires_at => 2147483647999,
            Session::pending_registration_since => 1000000001999,
            Session::pending_second_factor_until => 1000000002000,
        ]);
        self::seedSession(self::TOKEN_B, [Session::created_at => 1000, Session::last_seen_at => 1000]);
        $indexes = self::indexes(self::SESSION_TABLE);

        self::runDelivered(self::SESSION_UNIT, down: true);

        foreach (self::SESSION_REQUIRED_MOMENTS as $column) {
            $this->assertLegacyRequired(self::SESSION_TABLE, $column);
        }
        foreach (self::SESSION_OPTIONAL_MOMENTS as $column) {
            $this->assertLegacyOptional(self::SESSION_TABLE, $column);
        }
        $this->assertSame($indexes, self::indexes(self::SESSION_TABLE));
        $this->assertSame([
            ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07', '2001-09-09 01:46:41', '2001-09-09 01:46:42'],
            ['1970-01-01 00:00:01', '1970-01-01 00:00:01', null, null, null],
        ], self::sessionStored());

        self::runDelivered(self::SESSION_UNIT);

        $this->assertSame([
            [1000000000000, 1000, 2147483647000, 1000000001000, 1000000002000],
            [1000, 1000, null, null, null],
        ], self::sessionMoments());
        $this->assertSessionMoments();
    }

    /**
     * @param string $column Session moment column holding the value
     * @param int $moment Stored value the legacy TIMESTAMP cannot hold
     */
    #[DataProvider('unrepresentableSessionMoments')]
    public function testSessionDownRefusesBeforeReplacingAnUnrepresentableMoment(string $column, int $moment): void
    {
        self::freshSession();
        self::seedSession(self::TOKEN_A, [Session::created_at => 1000, Session::last_seen_at => 1000, $column => $moment]);
        $before = self::rows(self::SESSION_TABLE);

        try {
            self::runDelivered(self::SESSION_UNIT, down: true);
            $this->fail('A value outside the TIMESTAMP range must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }

        $this->assertSame($before, self::rows(self::SESSION_TABLE));
        $this->assertSessionMoments();
    }

    /** @return iterable<string, array{string, int}> Each session moment column with each value outside TIMESTAMP(0) */
    public static function unrepresentableSessionMoments(): iterable
    {
        foreach (self::SESSION_MOMENTS as $column) {
            yield $column . ' at the epoch' => [$column, 0];
            yield $column . ' under the first second' => [$column, 999];
            yield $column . ' before the epoch' => [$column, -1];
            yield $column . ' after 2038' => [$column, 2147483648000];
        }
    }

    /** @param int $moment Epoch milliseconds written and read through the Entity */
    #[DataProvider('roundTripMoments')]
    public function testSessionEntityRoundTripsExactMoments(int $moment): void
    {
        self::freshSession();
        $session = new Session();
        $session->token = self::TOKEN_A;
        $session->created_at = $moment;
        $session->last_seen_at = $moment;
        $session->expires_at = $moment;
        $session->pending_registration_since = $moment;
        $session->pending_second_factor_until = $moment;
        $session->save();

        $this->assertSame([[$moment, $moment, $moment, $moment, $moment]], self::sessionMoments());
        $read = Session::getById($session->id);
        $this->assertSame($moment, $read?->created_at);
        $this->assertSame($moment, $read?->last_seen_at);
        $this->assertSame($moment, $read?->expires_at);
        $this->assertSame($moment, $read?->pending_registration_since);
        $this->assertSame($moment, $read?->pending_second_factor_until);
    }

    /** Every session moment is signed BIGINT with no SQL writer, in the nullability its column carries. */
    private function assertSessionMoments(): void
    {
        foreach (self::SESSION_REQUIRED_MOMENTS as $column) {
            $this->assertMoment(self::SESSION_TABLE, $column);
        }
        foreach (self::SESSION_OPTIONAL_MOMENTS as $column) {
            $this->assertOptionalMoment(self::SESSION_TABLE, $column);
        }
    }

    /**
     * @param string $table Table holding the moment
     * @param string $column Moment column that must be required signed BIGINT with no SQL writer
     */
    private function assertMoment(string $table, string $column): void
    {
        $row = self::column($table, $column);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
        $this->assertSame('NO', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertStagingAbsent($table, $column);
    }

    /**
     * @param string $table Table holding the moment
     * @param string $column Moment column that must be nullable signed BIGINT with no SQL writer
     */
    private function assertOptionalMoment(string $table, string $column): void
    {
        $row = self::column($table, $column);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
        $this->assertSame('YES', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertStagingAbsent($table, $column);
    }

    /**
     * @param string $table Table holding the moment
     * @param string $column Moment column that must be back in its legacy nullable form
     */
    private function assertLegacyOptional(string $table, string $column): void
    {
        $row = self::column($table, $column);
        $this->assertSame('timestamp', $row['Type']);
        $this->assertSame('YES', $row['Null']);
        $this->assertNull($row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertStagingAbsent($table, $column);
    }

    /**
     * @param string $table Table holding the moment
     * @param string $column Moment column that must be back in its legacy required form
     */
    private function assertLegacyRequired(string $table, string $column): void
    {
        $row = self::column($table, $column);
        $this->assertSame('timestamp', $row['Type']);
        $this->assertSame('NO', $row['Null']);
        $this->assertSame('current_timestamp()', $row['Default']);
        $this->assertSame('', $row['Extra']);
        $this->assertStagingAbsent($table, $column);
    }

    /**
     * @param string $table Table a conversion ran over
     * @param string $column Moment column whose staging column must be gone
     */
    private function assertStagingAbsent(string $table, string $column): void
    {
        $this->assertArrayNotHasKey(self::STAGING_PREFIX . $column, array_column(self::columns($table), null, 'Field'));
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

    /** Raises the circle as projects hold it before this change. */
    private function legacyCircle(): void
    {
        self::runStub(self::CIRCLE_STUB);
        Database::sql('ALTER TABLE `' . self::CIRCLE_TABLE . '` MODIFY `' . self::CIRCLE_MOMENT . '` ' . self::LEGACY_REQUIRED);
    }

    /**
     * @param string $identifier Named address
     * @param int|string $moment Stored value in the column's current representation
     */
    private static function seedCircle(string $identifier, int|string $moment): void
    {
        Database::sql(
            'INSERT INTO `' . self::CIRCLE_TABLE . '` (`identity_type`, `identifier`, `' . self::CIRCLE_MOMENT . '`) VALUES (?, ?, ?)',
            [IdentityType::PASSWORD, $identifier, $moment],
        );
    }

    /** Drops every table a case may have raised, the session before the person it points at. */
    private static function dropFixtureTables(): void
    {
        self::runStub(self::CIRCLE_STUB . '_down');
        self::runStub(self::SESSION_STUB . '_down');
        self::runStub(self::USER_STUB . '_down');
    }

    /** Raises the session table as the current stub defines it. */
    private static function freshSession(): void
    {
        self::runStub(self::USER_STUB);
        self::runStub(self::SESSION_STUB);
    }

    /** Raises the session table as projects hold it before this change. */
    private static function legacySession(): void
    {
        self::freshSession();
        $changes = array_merge(
            array_map(static fn (string $column): string => 'MODIFY `' . $column . '` ' . self::LEGACY_REQUIRED, self::SESSION_REQUIRED_MOMENTS),
            array_map(static fn (string $column): string => 'MODIFY `' . $column . '` ' . self::LEGACY_OPTIONAL, self::SESSION_OPTIONAL_MOMENTS),
        );
        Database::sql('ALTER TABLE `' . self::SESSION_TABLE . '` ' . implode(', ', $changes));
    }

    /**
     * @param string $token Session token of the row
     * @param array<string, int|string> $moments Stored values by moment column, in the column's current representation
     */
    private static function seedSession(string $token, array $moments): void
    {
        Database::sql(
            'INSERT INTO `' . self::SESSION_TABLE . '` (`' . Session::token . '`, `' . implode('`, `', array_keys($moments)) . '`)'
                . ' VALUES (?' . str_repeat(', ?', count($moments)) . ')',
            [$token, ...array_values($moments)],
        );
    }

    /** @return list<list<?int>> The five session moments of every row, oldest row first */
    private static function sessionMoments(): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): ?int => $value === null ? null : (int)$value, $row),
            self::sessionStored(),
        );
    }

    /** @return list<list<?string>> The five session moments as the database prints them, oldest row first */
    private static function sessionStored(): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (string $column): ?string => $row[$column], self::SESSION_MOMENTS),
            self::rows(self::SESSION_TABLE),
        );
    }

    /**
     * @param string $table Live table
     * @return array<string, list<string>> Columns of every index in key order, by index name
     */
    private static function indexes(string $table): array
    {
        $indexes = [];
        foreach (Database::sql('SHOW INDEX FROM `' . $table . '`')->rows() as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }
        ksort($indexes);

        return $indexes;
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
     * @param string $table Live table
     * @return TableStructure The table as the restore planner models it from its live definition
     */
    private static function structure(string $table): TableStructure
    {
        $collations = array_column(
            Database::sql('SELECT COLLATION_NAME, CHARACTER_SET_NAME FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY')->rows(),
            'CHARACTER_SET_NAME',
            'COLLATION_NAME',
        );
        $create = Database::sql('SHOW CREATE TABLE `' . $table . '`')->row()['Create Table'];

        return TableStructure::read(
            $create,
            self::database(),
            new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', charsetByCollation: $collations),
        );
    }

    /**
     * @param string $table Live table
     * @return list<array<string, mixed>> Its columns in physical order
     */
    private static function columns(string $table): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . $table . '`')->rows();
    }

    /**
     * @param string $table Live table
     * @param string $column Column to describe
     * @return array<string, mixed> Its SHOW COLUMNS row
     */
    private static function column(string $table, string $column): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . $table . '` LIKE ?', [$column])->row();
    }

    /**
     * @param string $table Live table
     * @return list<array<string, mixed>> Every row, oldest first
     */
    private static function rows(string $table): array
    {
        return Database::sql('SELECT * FROM `' . $table . '` ORDER BY `id`')->rows();
    }

    /**
     * @param string $table Live table
     * @param string $column Column holding the raw stored value
     * @return list<string> Its values as the database prints them, oldest row first
     */
    private static function stored(string $table, string $column): array
    {
        return array_map(static fn (array $row): string => (string)$row[$column], self::rows($table));
    }

    /**
     * @param string $table Live table
     * @param string $column Moment column in epoch milliseconds
     * @return list<int> Its values, oldest row first
     */
    private static function moments(string $table, string $column): array
    {
        return array_map(static fn (array $row): int => (int)$row[$column], self::rows($table));
    }
}
