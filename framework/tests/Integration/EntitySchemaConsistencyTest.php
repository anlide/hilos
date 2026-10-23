<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration;
use ReflectionMethod;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseConnectionPolicy;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Integration test: framework Entity metadata against the live database schema.
 *
 * The only net that catches Entity/schema drift before runtime after the removal
 * of `db:entity:diff` (HIL-478). The reusable auditor lives in framework/backend
 * ({@see EntitySchemaAudit}); this test drives it: it applies the covered
 * migration stubs into the empty framework test database, audits every discovered
 * Entity, and drops the tables afterwards.
 *
 * Schema is raised once for the whole class (guarded by {@see self::$schemaApplied}
 * in setUp) and dropped best-effort in tearDownAfterClass. Run MariaDB first
 * (composer run test:framework:up), then composer run test:framework:integration.
 */
final class EntitySchemaConsistencyTest extends FrameworkIntegrationTestCase
{
    /** Whether the covered stub tables have been created for this test class run. */
    private static bool $schemaApplied = false;

    /**
     * Connects (via parent) and raises the covered stub schema once per class run.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseException When a stub statement fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::configureJournal();

        if (self::$schemaApplied) {
            return;
        }
        self::applyStubSchema();
        self::$schemaApplied = true;
    }

    /**
     * Drops the covered stub tables best-effort, opening a dedicated connection
     * because the last test's tearDown already closed the shared one.
     */
    public static function tearDownAfterClass(): void
    {
        try {
            self::openConnection();
            self::runStubDirection(down: true);
        } catch (Throwable) {
            // Best-effort cleanup; a failure here must not mask test results.
        } finally {
            if (Database::isConnected()) {
                Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
            }
            self::$schemaApplied = false;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Discovered framework Entity classes, keyed by short class name for readable
     * test-case labels.
     *
     * @return iterable<string, array{class-string<Entity>}> Case name => [entity class]
     */
    public static function entityProvider(): iterable
    {
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            $shortName = substr((string) strrchr($entityClass, '\\'), 1);
            yield $shortName => [$entityClass];
        }
    }

    /**
     * Every Entity must ship both migration stub files (create and its down).
     *
     * @param class-string<Entity> $entityClass Entity under test
     */
    #[DataProvider('entityProvider')]
    public function testStubFilesExist(string $entityClass): void
    {
        $table = constant("{$entityClass}::" . Entity::META_TABLE);

        $this->assertFileExists(self::stubPath($entityClass, down: false), "missing create stub for {$table}");
        $this->assertFileExists(self::stubPath($entityClass, down: true), "missing down stub for {$table}");
    }

    /**
     * The Entity metadata must match the live table on every audit axis.
     *
     * @param class-string<Entity> $entityClass Entity under test
     * @throws DatabaseException When an introspection query fails
     */
    #[DataProvider('entityProvider')]
    public function testEntityMatchesSchema(string $entityClass): void
    {
        $mismatches = EntitySchemaAudit::audit([$entityClass]);

        $this->assertSame(
            [],
            $mismatches,
            implode("\n", array_map(static fn($mismatch): string => $mismatch->describe(), $mismatches)),
        );
    }

    /**
     * Applies every covered create stub, dropping first so a partial prior run
     * cannot collide.
     *
     * @throws DatabaseException When a stub statement fails
     */
    private static function applyStubSchema(): void
    {
        self::runStubDirection(down: true);
        self::runStubDirection(down: false);
    }

    /**
     * Runs one direction of every covered entity's stub file.
     *
     * The create follows _foreign dependencies, so hilos_user and each other parent exist
     * before their children. The drop reverses that order.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubDirection(bool $down): void
    {
        $unit = new FrameworkSchemaCatalog()->unit(FrameworkSchemaCatalog::CHANGE_LOG);
        $journalSql = file_get_contents($down ? $unit->downPath : $unit->upPath);
        $journalSql = str_replace('{{change_log_database}}', ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName()), $journalSql);
        $runJournal = new ReflectionMethod(Migration::class, 'runSqlWithDelimiter');
        if ($down && array_intersect($unit->tables, EntitySchemaAudit::liveTables(ChangeLogDatabase::CONNECTION_INDEX)) !== []) {
            // The real down stub checks all six tables and uses DELIMITER for its history guard.
            $runJournal->invoke(null, $journalSql);
        }
        $entities = self::stubEntitiesInDependencyOrder();
        foreach ($down ? array_reverse($entities) : $entities as $entityClass) {
            Database::sqlRun(file_get_contents(self::stubPath($entityClass, $down)));
        }
        if (!$down) {
            $runJournal->invoke(null, $journalSql);
        }
    }

    /**
     * Orders the framework's stub tables by their Entity foreign-key declarations.
     *
     * @return list<class-string<Entity>> Parent tables before their children
     * @throws LogicException When the declarations form a cycle
     */
    private static function stubEntitiesInDependencyOrder(): array
    {
        $byTable = [];
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            if ($entityClass::getDatabaseName() === DatabaseNames::PRIMARY) {
                $byTable[$entityClass::_table] = $entityClass;
            }
        }

        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = static function (string $entityClass) use (&$visit, &$ordered, &$visiting, &$visited, $byTable): void {
            $table = constant("{$entityClass}::" . Entity::META_TABLE);
            if (isset($visited[$table])) {
                return;
            }
            if (isset($visiting[$table])) {
                throw new LogicException("Foreign-key cycle at {$table}");
            }
            $visiting[$table] = true;
            $foreign = defined("{$entityClass}::" . Entity::META_FOREIGN)
                ? constant("{$entityClass}::" . Entity::META_FOREIGN)
                : [];
            foreach ($foreign as $parentTable) {
                if (isset($byTable[$parentTable])) {
                    $visit($byTable[$parentTable]);
                }
            }
            unset($visiting[$table]);
            $visited[$table] = true;
            $ordered[] = $entityClass;
        };
        foreach ($byTable as $entityClass) {
            $visit($entityClass);
        }

        return $ordered;
    }

    /**
     * Opens connection index 0 from environment (mirrors the base setUp) for the
     * static teardown path.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     */
    private static function openConnection(): void
    {
        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__));
        }

        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        Database::connect(
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            retryOnConnectionError: true,
            maxRetries: DatabaseConnectionPolicy::CONNECT_RETRY_MAX_ATTEMPTS,
            retryDelaySeconds: DatabaseConnectionPolicy::CONNECT_RETRY_DELAY_SECONDS,
        );
        self::configureJournal();
    }

    /** Configure the derived schema for metadata reads; all stub SQL still runs on primary. */
    private static function configureJournal(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            index: ChangeLogDatabase::CONNECTION_INDEX,
            host: $primary->host,
            user: $primary->user,
            password: $primary->password,
            database: ChangeLogDatabase::configuredName(),
            port: $primary->port,
            charset: $primary->charset,
        );
    }

    /**
     * @param class-string<Entity> $entityClass Entity whose schema unit supplies the stub
     * @param bool $down True for the down (drop) stub, false for the create stub
     * @return string Absolute path of the migration stub file
     */
    private static function stubPath(string $entityClass, bool $down): string
    {
        if ($entityClass::getDatabaseName() === DatabaseNames::CHANGE_LOG) {
            $unit = new FrameworkSchemaCatalog()->unit(FrameworkSchemaCatalog::CHANGE_LOG);
            return $down ? $unit->downPath : $unit->upPath;
        }
        $table = $entityClass::_table;
        // external-boundary: the neutral element of the name being built — the up file carries no suffix
        $suffix = $down ? '_down' : '';
        return dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
    }
}
