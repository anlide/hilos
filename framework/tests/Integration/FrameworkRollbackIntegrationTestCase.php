<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseConnectionPolicy;
use Hilos\Database\Schema\Schema;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Testing\RollbackIntegrationTestCase;
use ReflectionProperty;
use Throwable;

/** Unmarked environment owner: unchanged migration stubs stand outside every case's rollback scope. */
abstract class FrameworkRollbackIntegrationTestCase extends RollbackIntegrationTestCase
{
    private const array TABLES = ['hilos_user', 'hilos_setting', 'hilos_second_factor_trust'];
    private const array COLLECTIONS = [HilosDbContext::users, HilosDbContext::settings, HilosDbContext::secondFactorTrusts];

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousSr = null;
    private ?EnvAccessor $previousEnv = null;
    private ?int $executionToken = null;
    private ?string $claimId = null;
    /** @var list<string> Successfully created tables, including partial setup */
    private array $createdTables = [];

    /** Opens the test connection, creates the three stubs, and mounts the real framework context. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousSr = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        self::releaseConnections();
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
        foreach (self::TABLES as $table) {
            self::runStub($table, false);
            $this->createdTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();

        $this->claimId = 'framework-rollback-test-' . spl_object_id($this);
        foreach (self::COLLECTIONS as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->claimId);
        }
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->claimId));
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$db = new FrameworkRollbackDbContext();
        Hilos::$db->configure();
    }

    /** Drops tables after rollback/reset and restores facades and ownership even when release itself fails. */
    protected function releaseIntegrationEnvironment(): void
    {
        $failures = [];
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            foreach (array_reverse($this->createdTables) as $table) {
                try {
                    self::runStub($table, true);
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        } finally {
            $this->createdTables = [];
            try {
                self::releaseConnections();
            } finally {
                Hilos::$db = $this->previousDb;
                Hilos::$rt = $this->previousRt;
                Hilos::$sr = $this->previousSr;
                Hilos::$env = $this->previousEnv;
                if ($this->claimId !== null) {
                    TruthSourceRegistry::unregisterAgent($this->claimId);
                    $this->claimId = null;
                }
                if ($this->executionToken !== null) {
                    ExecutionContext::pop($this->executionToken);
                    $this->executionToken = null;
                }
            }
        }
        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /**
     * @param string $table Existing framework stub name
     * @param bool $down Whether to execute its existing drop statement
     */
    private static function runStub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }

    /** Clears every configured connection, including extra indices introduced by a case. */
    private static function releaseConnections(): void
    {
        foreach (Database::getConfiguredIndices() as $index) {
            Database::close($index);
            Schema::reset($index);
        }
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, []);
        new ReflectionProperty(Database::class, 'databaseBindings')->setValue(null, []);
        new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, null);
        new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
    }
}

/** Framework collections only; no fake row models or project mappings. */
final class FrameworkRollbackDbContext extends HilosDbContext
{
}
