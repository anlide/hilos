<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\Entity\Item\ChangeLogEntry;
use Hilos\Database\Entity\Item\OAuthProvider;
use Hilos\Database\Entity\Item\StatusSilencePeriod;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Exception\InvalidMomentDeclarationException;
use Hilos\Database\MigrationClaim;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\MomentColumn;
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\MomentStorageAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure physical storage checks; real schema bindings are exercised by the later live guard. */
final class MomentStorageAuditTest extends TestCase
{
    /** @return iterable<string, array{string, int|string|null, string}> Signed types and defaults outside the SQL-time writer rule */
    public static function standardColumns(): iterable
    {
        yield 'plain' => ['bigint', null, ''];
        yield 'uppercase' => ['BIGINT', 'NULL', ''];
        yield 'mixed case and display width' => ['BiGiNt(20)', '0', ''];
        yield 'surrounding whitespace' => [' BIGINT(19) ', 0, ''];
        yield 'negative moment' => ['bigint', -1234, ''];
        yield 'numeric millisecond default' => ['bigint(20)', '1794000000123', 'DEFAULT_GENERATED'];
        yield 'numeric DB expression' => ['bigint', '(round(unix_timestamp(current_timestamp(3)) * 1000))', 'DEFAULT_GENERATED'];
        yield 'look-alike default' => ['bigint', 'CURRENT_TIMESTAMP_suffix', ''];
        yield 'look-alike update' => ['bigint', null, 'on update CURRENT_TIMESTAMP_suffix'];
    }

    /**
     * @param string $type Raw SQL type
     * @param int|string|null $default Raw default
     * @param string $extra Raw EXTRA
     */
    #[DataProvider('standardColumns')]
    public function testSignedBigintAndOrdinaryDefaultsPass(string $type, int|string|null $default, string $extra): void
    {
        self::assertSame([], MomentStorageAudit::audit(
            User::class,
            User::_table,
            [User::last_activity => new MomentColumn(User::last_activity, true, true)],
            [User::last_activity => self::columnRow($type, $default, $extra)],
        ));
    }

    /** @return iterable<string, array{string, string}> Invalid physical types and their normalized diagnostic spellings */
    public static function invalidTypes(): iterable
    {
        yield 'integer' => ['int(11)', 'int(11)'];
        yield 'small integer' => ['smallint', 'smallint'];
        yield 'medium integer' => ['mediumint(9)', 'mediumint(9)'];
        yield 'tiny integer' => ['tinyint(1)', 'tinyint(1)'];
        yield 'unsigned' => ['BIGINT(20) UNSIGNED', 'bigint unsigned'];
        yield 'zerofill' => ['bigint(20) zerofill', 'bigint zerofill'];
        yield 'unsigned zerofill' => ['bigint(20) unsigned zerofill', 'bigint unsigned zerofill'];
        yield 'timestamp' => ['TIMESTAMP', 'timestamp'];
        yield 'fractional timestamp' => ['timestamp(6)', 'timestamp(6)'];
        yield 'datetime' => ['datetime', 'datetime'];
        yield 'fractional datetime' => ['DATETIME(3)', 'datetime(3)'];
        yield 'text' => ['varchar(255)', 'varchar(255)'];
        yield 'decimal' => ['decimal(20,0)', 'decimal(20,0)'];
        yield 'date is not a moment representation' => ['date', 'date'];
    }

    /**
     * @param string $type Invalid raw SQL type
     * @param string $actual Expected normalized physical type
     */
    #[DataProvider('invalidTypes')]
    public function testWrongPhysicalTypesHaveExactFindings(string $type, string $actual): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_TYPE,
                User::class,
                User::_table,
                User::last_activity,
                'signed BIGINT (epoch milliseconds)',
                $actual,
            ),
        ], MomentStorageAudit::audit(
            User::class,
            User::_table,
            [User::last_activity => new MomentColumn(User::last_activity, true, true)],
            [User::last_activity => self::columnRow($type)],
        ));
    }

    /** Presence is mandatory for mapped, DB-only and explicitly opted-out project moments. */
    public function testEveryDeclaredMomentMustExist(): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_COLUMN_MISSING, null, 'raw_events', 'mapped', 'column present', 'missing'),
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_COLUMN_MISSING, null, 'raw_events', 'db_only', 'column present', 'missing'),
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_COLUMN_MISSING, null, 'raw_events', 'custom', 'column present', 'missing'),
        ], MomentStorageAudit::audit(null, 'raw_events', [
            'mapped' => new MomentColumn('mapped', false, true),
            'db_only' => new MomentColumn('db_only', true, false),
            'custom' => new MomentColumn('custom', false, false, 'SQL UTC datetime; external archive contract'),
        ], []));
    }

    /** Existing DB-only declarations are audited without consulting _columns or hydrating an Entity. */
    public function testRealDatabaseOnlyMomentsAreChecked(): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_TYPE,
                OAuthProvider::class,
                OAuthProvider::_table,
                OAuthProvider::created_at,
                'signed BIGINT (epoch milliseconds)',
                'timestamp',
            ),
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_COLUMN_MISSING,
                OAuthProvider::class,
                OAuthProvider::_table,
                OAuthProvider::updated_at,
                'column present',
                'missing',
            ),
        ], MomentStorageAudit::audit(
            OAuthProvider::class,
            OAuthProvider::_table,
            MomentColumns::forEntity(OAuthProvider::class),
            [OAuthProvider::created_at => self::columnRow('timestamp')],
        ));
    }

    /** @return iterable<string, array{string, string}> Actual bootstrap tables and their moment columns */
    public static function bootstrapColumns(): iterable
    {
        yield 'migration claim' => [MigrationClaim::TABLE, 'claimed_at'];
        yield 'admin latch' => [AdminViewModeLatchTable::TABLE, 'closed_at'];
        yield 'database marker' => [DatabaseMarker::TABLE, 'written_at'];
    }

    /**
     * @param string $table Bootstrap table without an Entity
     * @param string $column Its declared physical moment
     */
    #[DataProvider('bootstrapColumns')]
    public function testProviderBootstrapMomentsHaveNoEntityExemption(string $table, string $column): void
    {
        $moments = MomentColumns::forProvider(FrameworkTablesWithoutEntity::class)[$table];
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_COLUMN_MISSING, null, $table, $column, 'column present', 'missing'),
        ], MomentStorageAudit::audit(null, $table, $moments, []));
        $mismatches = MomentStorageAudit::audit(null, $table, $moments, [$column => self::columnRow('BIGINT(20) UNSIGNED')]);
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_TYPE, null, $table, $column, 'signed BIGINT (epoch milliseconds)', 'bigint unsigned'),
        ], $mismatches);
        self::assertSame(
            "[moment_type] {$table}.{$column}: expected <signed BIGINT (epoch milliseconds)>, got <bigint unsigned>",
            $mismatches[0]->describe(),
        );
    }

    /** @return iterable<string, array{string}> Current-time default spellings returned by SQL engines */
    public static function currentTimeDefaults(): iterable
    {
        foreach (['CURRENT_TIMESTAMP', 'NOW', 'LOCALTIME', 'LOCALTIMESTAMP'] as $function) {
            yield $function => [$function];
            yield $function . ' parentheses' => [$function . '()'];
            yield $function . ' precision' => [$function . '(6)'];
        }
        yield 'wrapped expression and mixed case' => [' ( current_TimeStamp(3) ) '];
        yield 'nested expression and whitespace' => [' ( ( now(6) ) ) '];
        yield 'zero precision' => ['now(0)'];
    }

    /** @param string $default Current-time default to refuse independently of the type */
    #[DataProvider('currentTimeDefaults')]
    public function testCurrentTimeDefaultsHaveExactFindings(string $default): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_AUTO_WRITE,
                null,
                'events',
                'at',
                'no SQL current-time DEFAULT or ON UPDATE',
                'DEFAULT ' . $default,
            ),
        ], MomentStorageAudit::audit(
            null,
            'events',
            ['at' => new MomentColumn('at', false, false)],
            ['at' => self::columnRow('bigint', $default)],
        ));
    }

    /** @return iterable<string, array{string}> Current-time ON UPDATE spellings with optional engine markers */
    public static function currentTimeUpdates(): iterable
    {
        foreach (['CURRENT_TIMESTAMP', 'NOW', 'LOCALTIME', 'LOCALTIMESTAMP'] as $function) {
            yield $function => ['on update ' . $function];
            yield $function . ' parentheses' => ['on update ' . $function . '()'];
            yield $function . ' precision' => ['DEFAULT_GENERATED ON UPDATE ' . $function . '(6)'];
        }
        yield 'whitespace and case' => ['on  UpDaTe current_timestamp ( 3 )'];
    }

    /** @param string $extra Current-time ON UPDATE to refuse independently of the type */
    #[DataProvider('currentTimeUpdates')]
    public function testCurrentTimeUpdatesHaveExactFindings(string $extra): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_AUTO_WRITE,
                null,
                'events',
                'at',
                'no SQL current-time DEFAULT or ON UPDATE',
                'EXTRA ' . $extra,
            ),
        ], MomentStorageAudit::audit(
            null,
            'events',
            ['at' => new MomentColumn('at', false, false)],
            ['at' => self::columnRow('bigint', null, $extra)],
        ));
    }

    /** Neither a wrong type nor one automatic writer hides another axis or the other writer. */
    public function testTypeAndAutomaticWritersAreIndependentFindings(): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_TYPE, null, 'events', 'at', 'signed BIGINT (epoch milliseconds)', 'datetime(6)'),
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_AUTO_WRITE,
                null,
                'events',
                'at',
                'no SQL current-time DEFAULT or ON UPDATE',
                'DEFAULT CURRENT_TIMESTAMP(6); EXTRA on update current_timestamp(6)',
            ),
        ], MomentStorageAudit::audit(
            null,
            'events',
            ['at' => new MomentColumn('at', true, false)],
            ['at' => self::columnRow('datetime(6)', 'CURRENT_TIMESTAMP(6)', 'on update current_timestamp(6)')],
        ));
    }

    /** A project's explicit representation changes only the checks of its own column. */
    public function testProjectStorageExceptionIsLocalToOneColumn(): void
    {
        $legacyColumn = self::columnRow('datetime', 'CURRENT_TIMESTAMP', 'on update CURRENT_TIMESTAMP');
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_TYPE, null, 'events', 'standard', 'signed BIGINT (epoch milliseconds)', 'datetime'),
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_AUTO_WRITE,
                null,
                'events',
                'standard',
                'no SQL current-time DEFAULT or ON UPDATE',
                'DEFAULT CURRENT_TIMESTAMP; EXTRA on update CURRENT_TIMESTAMP',
            ),
        ], MomentStorageAudit::audit(null, 'events', [
            'custom' => new MomentColumn('custom', false, true, 'SQL UTC datetime; external archive contract'),
            'standard' => new MomentColumn('standard', false, true),
        ], ['custom' => $legacyColumn, 'standard' => $legacyColumn]));
    }

    /** The metadata reader refuses a framework override before it can silence the physical check. */
    public function testFrameworkStorageCannotBeOptedOut(): void
    {
        $override = new class extends User {
            public const array _momentStorage = [self::last_activity => 'seconds; project convention'];
        };
        $this->expectException(InvalidMomentDeclarationException::class);
        $this->expectExceptionMessage('overrides framework moment [last_activity]');
        MomentStorageAudit::audit($override::class, User::_table, MomentColumns::forEntity($override::class), []);
    }

    /** Numeric millisecond database writers are allowed; the journal has no SQL-time exemption. */
    public function testJournalDatabaseWriterStillOwesStandardPhysicalStorage(): void
    {
        $moments = MomentColumns::forEntity(ChangeLogEntry::class);
        self::assertSame([], MomentStorageAudit::audit(
            ChangeLogEntry::class,
            ChangeLogEntry::_table,
            $moments,
            [ChangeLogEntry::created_at => self::columnRow('bigint', '(unix_timestamp(current_timestamp(3)) * 1000)', 'DEFAULT_GENERATED')],
        ));
        self::assertEquals([
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_TYPE,
                ChangeLogEntry::class,
                ChangeLogEntry::_table,
                ChangeLogEntry::created_at,
                'signed BIGINT (epoch milliseconds)',
                'timestamp(3)',
            ),
            new EntitySchemaMismatch(
                EntitySchemaAxis::MOMENT_AUTO_WRITE,
                ChangeLogEntry::class,
                ChangeLogEntry::_table,
                ChangeLogEntry::created_at,
                'no SQL current-time DEFAULT or ON UPDATE',
                'DEFAULT current_timestamp(3)',
            ),
        ], MomentStorageAudit::audit(
            ChangeLogEntry::class,
            ChangeLogEntry::_table,
            $moments,
            [ChangeLogEntry::created_at => self::columnRow('timestamp(3)', 'current_timestamp(3)')],
        ));
    }

    /** Both closed-gap moments must reject unsigned storage through the actual framework Entity metadata. */
    public function testStatusSilenceMomentsCannotEscapePhysicalStorageAudit(): void
    {
        $moments = MomentColumns::forEntity(StatusSilencePeriod::class);
        $liveColumns = [
            StatusSilencePeriod::started_at => self::columnRow('bigint'),
            StatusSilencePeriod::ended_at => self::columnRow('bigint'),
        ];
        foreach ([StatusSilencePeriod::started_at, StatusSilencePeriod::ended_at] as $column) {
            $invalidColumns = $liveColumns;
            $invalidColumns[$column] = self::columnRow('bigint unsigned');
            self::assertEquals([
                new EntitySchemaMismatch(
                    EntitySchemaAxis::MOMENT_TYPE,
                    StatusSilencePeriod::class,
                    StatusSilencePeriod::_table,
                    $column,
                    'signed BIGINT (epoch milliseconds)',
                    'bigint unsigned',
                ),
            ], MomentStorageAudit::audit(StatusSilencePeriod::class, StatusSilencePeriod::_table, $moments, $invalidColumns));
        }
        self::assertSame([], MomentStorageAudit::audit(StatusSilencePeriod::class, StatusSilencePeriod::_table, $moments, $liveColumns));
        self::assertEquals([
            StatusSilencePeriod::started_at => new MomentColumn(StatusSilencePeriod::started_at, true, true),
            StatusSilencePeriod::ended_at => new MomentColumn(StatusSilencePeriod::ended_at, true, true),
        ], $moments);
    }

    /** Names, integer-looking values, dates and durations do not declare semantics. */
    public function testUndeclaredColumnsDoNotBecomeMoments(): void
    {
        $liveColumns = [
            'created_at' => self::columnRow('timestamp', 'CURRENT_TIMESTAMP'),
            'duration_ms' => self::columnRow('bigint unsigned'),
            'calendar_day' => self::columnRow('date'),
            'id' => self::columnRow('int'),
        ];
        self::assertSame([], MomentStorageAudit::audit(null, 'events', [], $liveColumns));
        $liveColumns['at'] = self::columnRow('bigint');
        self::assertSame([], MomentStorageAudit::audit(null, 'events', ['at' => new MomentColumn('at', false, false)], $liveColumns));
    }

    /** Numeric string identities remain strings in findings rather than PHP's coerced array keys. */
    public function testNumericStringColumnNamesRemainDistinct(): void
    {
        self::assertEquals([
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_COLUMN_MISSING, null, '0', '0', 'column present', 'missing'),
            new EntitySchemaMismatch(EntitySchemaAxis::MOMENT_TYPE, null, '0', '00', 'signed BIGINT (epoch milliseconds)', 'bigint unsigned'),
        ], MomentStorageAudit::audit(null, '0', [
            '0' => new MomentColumn('0', false, false),
            '00' => new MomentColumn('00', false, false),
        ], ['00' => self::columnRow('bigint unsigned')]));
    }

    /**
     * @param string $type Raw physical type
     * @param int|string|null $default Raw default
     * @param string $extra Raw automatic-writer markers
     * @return array<string, mixed> Raw information_schema.COLUMNS fields consumed by the pure axis
     */
    private static function columnRow(string $type, int|string|null $default = null, string $extra = ''): array
    {
        return [
            EntitySchemaAudit::COL_TYPE => $type,
            EntitySchemaAudit::COL_DEFAULT => $default,
            EntitySchemaAudit::COL_EXTRA => $extra,
        ];
    }
}
