<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
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

/**
 * Integration test: framework Entity metadata against the live database schema.
 *
 * The only net that catches Entity/schema drift before runtime after the removal
 * of `db:entity:diff` (HIL-478). The reusable auditor lives in framework/backend
 * ({@see EntitySchemaAudit}); this test drives it: it applies the 10 covered
 * migration stubs into the empty framework test database, audits every discovered
 * Entity, and drops the tables afterwards.
 *
 * Schema is raised once for the whole class (guarded by {@see self::$schemaApplied}
 * in setUp) and dropped best-effort in tearDownAfterClass. Run MariaDB first
 * (composer run test:framework:up), then composer run test:framework:integration.
 */
final class EntitySchemaConsistencyTest extends FrameworkIntegrationTestCase
{
    /** PSR-4 namespace of the audited Entity directory. */
    private const string ENTITY_NAMESPACE = 'Hilos\\Database\\Entity\\Item\\';

    /** Lower bound so an empty discovery cannot masquerade as "no drift". */
    private const int MIN_ENTITY_COUNT = 10;

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
        } catch (\Throwable) {
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
        foreach (EntitySchemaAudit::discoverEntities(self::entityDir(), self::ENTITY_NAMESPACE) as $entityClass) {
            $shortName = substr((string) strrchr($entityClass, '\\'), 1);
            yield $shortName => [$entityClass];
        }
    }

    /**
     * Discovery must find at least the known framework Entities, so a broken scan
     * cannot silently turn the audit into a no-op.
     */
    public function testDiscoveryFindsFrameworkEntities(): void
    {
        $entities = EntitySchemaAudit::discoverEntities(self::entityDir(), self::ENTITY_NAMESPACE);

        $this->assertGreaterThanOrEqual(
            self::MIN_ENTITY_COUNT,
            count($entities),
            'Entity discovery found fewer classes than expected; PSR-4 mapping or directory changed',
        );
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

        $this->assertFileExists(self::stubPath($table, down: false), "missing create stub for {$table}");
        $this->assertFileExists(self::stubPath($table, down: true), "missing down stub for {$table}");
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
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubDirection(bool $down): void
    {
        foreach (EntitySchemaAudit::discoverEntities(self::entityDir(), self::ENTITY_NAMESPACE) as $entityClass) {
            $table = constant("{$entityClass}::" . Entity::META_TABLE);
            Database::sqlRun(file_get_contents(self::stubPath($table, $down)));
        }
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
            Hilos::initEnv(dirname(__DIR__), copyExample: false);
        }

        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        Database::connect(
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            retryOnConnectionError: true,
            maxRetries: DatabaseConnectionPolicy::CONNECT_RETRY_MAX_ATTEMPTS,
            retryDelaySeconds: DatabaseConnectionPolicy::CONNECT_RETRY_DELAY_SECONDS,
        );
    }

    /**
     * @return string Absolute path of the audited Entity directory
     */
    private static function entityDir(): string
    {
        return dirname(__DIR__, 2) . '/backend/Database/Entity/Item';
    }

    /**
     * @param string $table Table name
     * @param bool $down True for the down (drop) stub, false for the create stub
     * @return string Absolute path of the migration stub file
     */
    private static function stubPath(string $table, bool $down): string
    {
        // external-boundary: the neutral element of the name being built — the up file carries no suffix
        $suffix = $down ? '_down' : '';
        return dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
    }
}
