<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\LegalAcceptance;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Hilos;
use Hilos\Testing\MomentStorageBaseline;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Throwable;

/** DDL is the subject: every case owns disposable schemas outside any rollback scope. */
final class MomentStorageSchemaIntegrationTest extends FrameworkIntegrationTestCase
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const string BASELINE = __DIR__ . '/../CodeStyle/moment-storage-baseline.json';
    private const int NAMED_SCHEMA = 2;
    private const int PRIMARY_ALIAS = 3;

    /** @var array<string, mixed> Facade and migration bootstrap state saved before setup */
    private array $previous = [];
    /** @var list<string> Schemas created by this case, including a partially prepared pair */
    private array $created = [];

    /** Every declared moment must exist before exact historical allowances are consulted. */
    public function testCanonicalFrameworkMomentsMatchTheHistoricalBaseline(): void
    {
        $subjects = $this->canonicalSubjects();
        $mismatches = EntitySchemaAudit::auditMoments(EntitySchemaAudit::frameworkEntities());
        array_push($mismatches, ...EntitySchemaAudit::auditProviderMoments(
            FrameworkTablesWithoutEntity::class, DatabaseConnectionDefaults::PRIMARY_INDEX,
        ));
        $baseline = MomentStorageBaseline::fromJson(file_get_contents(self::BASELINE));
        $frameworkNamespace = substr(Entity::class, 0, -strlen('Entity'));
        // A complete framework pass also covers old declarations or columns that disappeared.
        foreach ($baseline->subjects() as $subject) {
            [$kind, $declaration] = json_decode($subject, true, flags: JSON_THROW_ON_ERROR);
            if ($kind === 'schema' && ($declaration === FrameworkTablesWithoutEntity::class
                || str_starts_with($declaration, $frameworkNamespace))) {
                $subjects[] = $subject;
            }
        }
        $reported = self::reported($mismatches);
        self::assertSame([], $baseline->reconcile($reported, array_values(array_unique($subjects))),
            'Physical framework moments must match only the closed historical debt.');
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /** @return iterable<string, array{string, ?string}> Real server types and their exact normalized findings */
    public static function physicalTypes(): iterable
    {
        yield 'signed bigint' => ['BIGINT', null];
        yield 'signed display width' => ['BIGINT(19)', null];
        yield 'integer' => ['INT', 'int(11)'];
        yield 'unsigned' => ['BIGINT UNSIGNED', 'bigint unsigned'];
        yield 'timestamp' => ['TIMESTAMP', 'timestamp'];
        yield 'datetime' => ['DATETIME(6)', 'datetime(6)'];
    }

    /**
     * @param string $type Physical definition installed during setup
     * @param ?string $actual Expected normalized debt, absent for signed BIGINT
     */
    #[DataProvider('physicalTypes')]
    public function testInjectedMomentCannotBorrowHistoricalDebt(string $type, ?string $actual): void
    {
        $mismatches = EntitySchemaAudit::auditMoments([MomentSchemaEntity::class]);
        $reported = self::reported($mismatches);
        if ($actual === null) {
            self::assertSame([], $mismatches, $type);
        } else {
            self::assertCount(1, $mismatches);
            self::assertSame(EntitySchemaAxis::MOMENT_TYPE, $mismatches[0]->axis);
            self::assertSame($actual, $mismatches[0]->actual);
            self::assertSame([self::json(['MOMENT-TYPE', self::subject(
                MomentSchemaEntity::class, MomentSchemaEntity::_table, 'occurred_at',
            ), $actual])], array_keys($reported));
        }
        self::assertSame(array_merge(...array_values($reported)),
            MomentStorageBaseline::fromJson(file_get_contents(self::BASELINE))->reconcile(
                $reported, [self::subject(MomentSchemaEntity::class, MomentSchemaEntity::_table, 'occurred_at')],
            ));
    }

    /** DB-only, analytics and bootstrap moments cannot disappear behind a historical type record. */
    public function testMissingUnmappedAndProviderColumnsAreUnconditionallyRejected(): void
    {
        self::assertFalse(MomentColumns::forEntity(AuthBlock::class)['updated_at']->mapped);
        $mismatches = EntitySchemaAudit::auditMoments([AuthBlock::class]);
        array_push($mismatches, ...EntitySchemaAudit::auditProviderMoments(FrameworkTablesWithoutEntity::class));
        $missing = array_values(array_filter($mismatches, static fn (EntitySchemaMismatch $mismatch): bool =>
            $mismatch->axis === EntitySchemaAxis::MOMENT_COLUMN_MISSING));
        self::assertSame([
            ['hilos_auth_block', 'updated_at'],
            ['hilos_migration_claim', 'claimed_at'],
            ['hilos_analytics_user_agent', 'created_ts'],
        ], array_map(static fn (EntitySchemaMismatch $mismatch): array => [$mismatch->table, $mismatch->subject], $missing));
        self::assertSame('missing', $missing[0]->actual);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('moment_column_missing');
        self::reported($missing);
    }

    /** Optional-table provider semantics do not excuse a required canonical table. */
    public function testMissingRequiredTableFailsBeforeBaselineReconciliation(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Required framework table hilos_analytics_loss');
        $this->canonicalSubjects();
    }

    /** A failed discovery is not an empty successful domain. */
    public function testEmptyEntityDiscoveryIsRejected(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Framework Entity discovery must not be empty');
        $this->entitySubjects([]);
    }

    /** Same names on different schemas, alias indices and row connections never rebind an Entity. */
    public function testNamedSchemaIdentityAndProviderConnectionStayIsolated(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        self::assertSame([], EntitySchemaAudit::auditMoments([MomentSchemaEntity::class]));
        self::assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        self::assertSame([], EntitySchemaAudit::auditMoments([MomentNamedSchemaEntity::class], DatabaseConnectionDefaults::PRIMARY_INDEX));
        self::assertSame([], EntitySchemaAudit::auditMoments([MomentSchemaEntity::class], self::PRIMARY_ALIAS));
        $named = EntitySchemaAudit::auditMoments([MomentNamedSchemaEntity::class], ChangeLogDatabase::CONNECTION_INDEX);
        self::assertCount(1, $named);
        self::assertSame('int(10) unsigned', $named[0]->actual);
        self::assertStringContainsString("database 'moment_schema_fixture'", $named[0]->describe());
        self::assertStringContainsString('schema connection 2', $named[0]->describe());
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, MomentNamedSchemaEntity::getDatabase()->connectionIndex);
        self::assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());

        self::assertSame([], EntitySchemaAudit::auditProviderMoments(MomentSchemaProvider::class, self::PRIMARY_ALIAS));
        $explicit = EntitySchemaAudit::auditProviderMoments(MomentSchemaProvider::class, self::NAMED_SCHEMA);
        self::assertCount(1, $explicit);
        self::assertSame('int(10) unsigned', $explicit[0]->actual);
        self::assertStringContainsString(MomentSchemaProvider::class . '::moments()', $explicit[0]->describe());
        self::assertEquals($explicit[0]->actual, EntitySchemaAudit::auditProviderMoments(MomentSchemaProvider::class)[0]->actual);
        self::assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());

        $journal = EntitySchemaAudit::auditMoments([ChangeLogReceipt::class]);
        self::assertSame('datetime(6)', $journal[0]->actual);
        self::assertStringContainsString('schema connection 1', $journal[0]->describe());
    }

    /** Both entrypoints restore the ambient connection when the selected schema cannot be read. */
    public function testAuditErrorRestoresCurrentConnection(): void
    {
        $config = Database::getConnectionConfig(self::NAMED_SCHEMA);
        Database::close(self::NAMED_SCHEMA);
        Database::configure(self::NAMED_SCHEMA, $config->host, 'moment_schema_denied', 'not-a-credential',
            $config->database, $config->port, $config->charset);
        foreach ([
            static fn (): array => EntitySchemaAudit::auditMoments([MomentNamedSchemaEntity::class]),
            static fn (): array => EntitySchemaAudit::auditProviderMoments(MomentSchemaProvider::class, self::NAMED_SCHEMA),
        ] as $audit) {
            try {
                $audit();
                self::fail('An unreadable schema must not become an empty moment pass');
            } catch (DatabaseException $failure) {
                self::assertNotSame('', $failure->getMessage());
            } finally {
                self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
            }
        }
    }

    /** Repairing one real historical column retires precisely its record within partial coverage. */
    public function testPhysicalRepairRequiresDeletingOnlyItsHistoricalRecord(): void
    {
        $subject = self::subject(LegalAcceptance::class, LegalAcceptance::_table, 'accepted_at');
        self::assertSame([], EntitySchemaAudit::auditMoments([LegalAcceptance::class]));
        $json = file_get_contents(self::BASELINE);
        $key = self::json(['MOMENT-TYPE', $subject, 'timestamp']);
        self::assertSame([
            'moment storage baseline record ' . $key . ' (HIL-1925) is paid off — delete the record',
        ], MomentStorageBaseline::fromJson($json)->reconcile([], [$subject]));
        $registry = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $records = $registry['records'];
        $registry['records'] = array_values(array_filter($records, static fn (array $record): bool =>
            !($record['rule'] === 'MOMENT-TYPE' && $record['subject'] === $subject && $record['actual'] === 'timestamp')));
        self::assertCount(count($records) - 1, $registry['records']);
        self::assertSame([], MomentStorageBaseline::fromJson(self::json($registry))->reconcile([], [$subject]));
    }

    /** Prepare real canonical DDL, then install only the refusal fixture needed by this case. */
    protected function setUp(): void
    {
        $this->previous = [
            'db' => Hilos::$db, 'rt' => Hilos::$rt, 'env' => Hilos::$env,
            'initialized' => new ReflectionProperty(Migration::class, 'initialized')->getValue(),
        ];
        try {
            parent::setUp();
            Hilos::$db = null;
            Hilos::$rt = null;
            // The connection-local bootstrap cache has no public test reset.
            new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
            $this->createPair();
            Migration::initialize();
            $catalog = new FrameworkSchemaCatalog();
            $pending = array_column($catalog->allUnits(), null, 'key');
            $applied = [];
            while ($pending !== []) {
                $before = count($pending);
                foreach ($pending as $key => $unit) {
                    if (array_diff($unit->dependencies, $applied) !== []) {
                        continue;
                    }
                    if (!$unit->bootstrapOwned) {
                        Database::sqlRun(str_replace('{{change_log_database}}', ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName()),
                            file_get_contents($unit->upPath)));
                    }
                    $applied[] = $key;
                    unset($pending[$key]);
                }
                self::assertLessThan($before, count($pending), 'Framework schema dependencies must make progress');
            }
            $this->prepareScenario();
        } catch (Throwable $failure) {
            try {
                $this->tearDown();
            } finally {
                throw $failure;
            }
        }
    }

    /** Drop both owned schemas even after a partial setup, then restore process-local state. */
    protected function tearDown(): void
    {
        $failures = [];
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            foreach (array_reverse($this->created) as $database) {
                try {
                    Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($database));
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        } finally {
            $this->created = [];
            try {
                parent::tearDown();
            } finally {
                new ReflectionProperty(Migration::class, 'initialized')->setValue(null, $this->previous['initialized']);
                Hilos::$db = $this->previous['db'];
                Hilos::$rt = $this->previous['rt'];
                Hilos::$env = $this->previous['env'];
            }
        }
        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /** Only schema infrastructure uses root; the ordinary piece database is never changed. */
    private function createPair(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $remoteHost = self::remoteEnvironment('HILOS_TEST_REMOTE_DB_HOST');
        $remotePort = (int) self::remoteEnvironment('HILOS_TEST_REMOTE_DB_PORT');
        $database = 'hilos-moment-it-' . bin2hex(random_bytes(6));
        // Root catalog readers see FK DDL across a whole server; isolate this pair on the remote database service.
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, $remoteHost, 'root',
            Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), 'information_schema', $remotePort, $primary->charset);
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        foreach ([$database, ChangeLogDatabase::name($database)] as $name) {
            Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($name)
                . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
            $this->created[] = $name;
        }
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => $database, ChangeLogDatabase::CONNECTION_INDEX => ChangeLogDatabase::name($database)]
            as $index => $name) {
            Database::configure($index, $remoteHost, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
                $name, $remotePort, $primary->charset);
            Database::connect($index);
        }
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    /** @return string Required remote stand configuration; missing values fail setup */
    private static function remoteEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Missing {$key}");
        self::assertNotSame('', $value, "Empty {$key}");
        return $value;
    }

    /** All destructive variations are setup DDL confined to the case's disposable pair. */
    private function prepareScenario(): void
    {
        if ($this->name() === 'testInjectedMomentCannotBorrowHistoricalDebt') {
            Database::sqlRun('CREATE TABLE moment_schema_fixture (occurred_at ' . $this->providedData()[0] . ' NULL DEFAULT NULL)');
        } elseif ($this->name() === 'testMissingUnmappedAndProviderColumnsAreUnconditionallyRejected') {
            Database::sqlRun('ALTER TABLE hilos_auth_block DROP COLUMN updated_at;'
                . 'ALTER TABLE hilos_analytics_user_agent DROP COLUMN created_ts;'
                . 'ALTER TABLE hilos_migration_claim DROP COLUMN claimed_at;');
        } elseif ($this->name() === 'testMissingRequiredTableFailsBeforeBaselineReconciliation') {
            Database::sqlRun('DROP TABLE hilos_analytics_loss');
        } elseif ($this->name() === 'testPhysicalRepairRequiresDeletingOnlyItsHistoricalRecord') {
            Database::sqlRun('ALTER TABLE hilos_legal_acceptance MODIFY accepted_at BIGINT NOT NULL');
        } elseif (in_array($this->name(), [
            'testNamedSchemaIdentityAndProviderConnectionStayIsolated', 'testAuditErrorRestoresCurrentConnection',
        ], true)) {
            Database::sqlRun('CREATE TABLE moment_schema_fixture (occurred_at BIGINT NULL)');
            Database::sqlRun('CREATE TABLE ' . ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName())
                . '.moment_schema_fixture (occurred_at INT UNSIGNED NULL)');
            foreach ([self::NAMED_SCHEMA => ChangeLogDatabase::CONNECTION_INDEX, self::PRIMARY_ALIAS => DatabaseConnectionDefaults::PRIMARY_INDEX]
                as $index => $source) {
                $config = Database::getConnectionConfig($source);
                Database::configure($index, $config->host, $config->user, $config->password, $config->database, $config->port, $config->charset);
            }
            Database::registerDatabase(MomentNamedSchemaEntity::_database, self::NAMED_SCHEMA, DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /** @return list<string> Full semantic domain, after independently proving required physical tables */
    private function canonicalSubjects(): array
    {
        $tables = [];
        foreach (new FrameworkSchemaCatalog()->allUnits() as $unit) {
            $tables[$unit->connection] = [...($tables[$unit->connection] ?? []), ...$unit->tables];
        }
        foreach ($tables as $index => $required) {
            $live = EntitySchemaAudit::liveTables($index);
            foreach ($required as $table) {
                self::assertContains($table, $live, 'Required framework table ' . $table . ' on schema connection ' . $index);
            }
            sort($required, SORT_STRING);
            sort($live, SORT_STRING);
            self::assertSame($required, $live, 'The canonical schema contains exactly its catalog tables');
        }
        $subjects = $this->entitySubjects(EntitySchemaAudit::frameworkEntities());
        foreach (MomentColumns::forProvider(FrameworkTablesWithoutEntity::class) as $table => $moments) {
            self::assertContains($table, $tables[DatabaseConnectionDefaults::PRIMARY_INDEX], 'Provider table must have a canonical DDL owner');
            foreach ($moments as $moment) {
                $subjects[] = self::subject(FrameworkTablesWithoutEntity::class, (string) $table, $moment->column);
            }
        }
        self::assertNotEmpty($subjects, 'Canonical moment discovery must not be empty');
        self::assertCount(count(array_unique($subjects)), $subjects, 'A physical subject must have one declaration');
        return $subjects;
    }

    /**
     * @param list<class-string<Entity>> $entities Complete framework discovery
     * @return list<string> Canonical moment subjects, including DB-only columns
     */
    private function entitySubjects(array $entities): array
    {
        self::assertNotEmpty($entities, 'Framework Entity discovery must not be empty');
        $subjects = [];
        foreach ($entities as $entity) {
            self::assertSame($entity, EntitySchemaAudit::mountedClassOf($entity), 'Canonical pass has no project mounts');
            self::assertContains($entity::_table, EntitySchemaAudit::liveTables($entity::getDatabase()->schemaIndex));
            foreach (MomentColumns::forEntity($entity) as $moment) {
                $subjects[] = self::subject($entity, $entity::_table, $moment->column);
            }
        }
        return $subjects;
    }

    /**
     * Missing columns are unconditional failures, never keys that a baseline can excuse.
     *
     * @param list<EntitySchemaMismatch> $mismatches Live Entity and framework-provider findings
     * @return array<string, list<string>> Exact canonical finding keys and diagnostic occurrences
     */
    private static function reported(array $mismatches): array
    {
        $reported = [];
        foreach ($mismatches as $mismatch) {
            $rule = match ($mismatch->axis) {
                EntitySchemaAxis::MOMENT_TYPE => 'MOMENT-TYPE',
                EntitySchemaAxis::MOMENT_AUTO_WRITE => 'MOMENT-AUTO-WRITE',
                default => self::fail($mismatch->describe()),
            };
            self::assertNotNull($mismatch->subject);
            $subject = self::subject($mismatch->entityClass ?? FrameworkTablesWithoutEntity::class, $mismatch->table, $mismatch->subject);
            $reported[self::json([$rule, $subject, $mismatch->actual])][] = $mismatch->describe();
        }
        return $reported;
    }

    /**
     * @param string $declaration Canonical framework Entity or provider
     * @param string $table Physical table
     * @param string $column Physical moment
     * @return string Structured schema identity without connection-dependent decoration
     */
    private static function subject(string $declaration, string $table, string $column): string
    {
        return self::json(['schema', $declaration, $table, $column]);
    }

    /**
     * @param array $value Infrastructure tuple or registry
     * @return string Canonical boundary JSON
     */
    private static function json(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}

/** Test-only declarations; production discovery and historical debt cannot include these subjects. */
class MomentSchemaEntity extends Entity
{
    public const string _table = 'moment_schema_fixture';
    public const array _columns = ['occurred_at'];
    public const array _moments = ['occurred_at'];
}

final class MomentNamedSchemaEntity extends MomentSchemaEntity
{
    public const string _database = 'moment_schema_fixture';
}

final class MomentSchemaProvider implements TablesWithoutEntityProvider
{
    /** @return list<string> One test-owned table, bound to the caller's connection */
    public static function tables(): array
    {
        return [MomentSchemaEntity::_table];
    }

    /** @return array<string, list<string>> Test-only moment metadata */
    public static function moments(): array
    {
        return [MomentSchemaEntity::_table => ['occurred_at']];
    }

    /** @return array<string, array<string, string>> No storage exemptions */
    public static function momentStorage(): array
    {
        return [];
    }

    /** @return array<string, array<string, string>> No personal fixture rows */
    public static function pii(): array
    {
        return [MomentSchemaEntity::_table => []];
    }

    /** @return array<string, list<string>> No personal fixture data */
    public static function piiNotPersonal(): array
    {
        return [MomentSchemaEntity::_table => ['occurred_at']];
    }
}
