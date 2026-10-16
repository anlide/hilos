<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use mysqli;
use RuntimeException;

/**
 * The seam between a worker and its browser context: a connection of the worker's process that came
 * back after a loss reaches the context as the fact "the database came back" at the next dispatch (HIL-1352).
 *
 * Not a unit test: only a real database moves the count Database keeps. The loss is a server thread
 * killed from a connection of its own, and the return is the statement that succeeds after it.
 */
final class WorkerDatabaseBackIntegrationTest extends FrameworkIntegrationTestCase
{
    private WorkerDatabaseBackTestBrowserContext $browser;

    protected function tearDown(): void
    {
        Hilos::$browser = null;
        Hilos::$sr = null;
        parent::tearDown();
    }

    /**
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testAReturnAfterALossReachesTheBrowserContextOnce(): void
    {
        $manager = $this->startWorker();
        $this->killConnection($this->currentConnectionId());
        Database::sql('SELECT 1');

        self::dispatchSignals($manager);
        $this->assertSame(1, $this->browser->databaseBackCalls);

        self::dispatchSignals($manager);
        $this->assertSame(1, $this->browser->databaseBackCalls, 'A dispatch with no new return hands the context nothing');
    }

    /**
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    public function testQueriesWithoutALossHandTheContextNothing(): void
    {
        $manager = $this->startWorker();
        Database::sql('SELECT 1');

        self::dispatchSignals($manager);
        $this->assertSame(0, $this->browser->databaseBackCalls);
    }

    /**
     * Starts a worker whose mark is in step with this process's count, and gives it a counting context.
     *
     * In a running worker the count and the mark both start at zero with the process. This process
     * has counted the returns of earlier cases, and a loss one of them left unpaid would be paid by
     * this case's first statement: one statement and one dispatch with no context settle both
     * before the context the case reads is put in place.
     *
     * @return WorkerDatabaseBackTestManager Worker connected to a silent daemon client
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    private function startWorker(): WorkerDatabaseBackTestManager
    {
        Hilos::$browser = null;
        $manager = new WorkerDatabaseBackTestManager();
        $manager->attachClient(new WorkerDatabaseBackTestClient());
        Database::sql('SELECT 1');
        self::dispatchSignals($manager);

        $this->browser = new WorkerDatabaseBackTestBrowserContext();
        Hilos::$browser = $this->browser;

        return $manager;
    }

    /**
     * Runs the worker's private dispatch, the step that hands the fact to the context before the flush.
     *
     * @param WorkerManager $manager Worker to dispatch on
     */
    private static function dispatchSignals(WorkerManager $manager): void
    {
        $dispatchSignals = Closure::bind(
            static function (WorkerManager $manager): void {
                $manager->dispatchSignals();
            },
            null,
            WorkerManager::class,
        );
        $dispatchSignals($manager);
    }

    /**
     * @return int Thread id of the connection Database is currently using
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    private function currentConnectionId(): int
    {
        Database::sql('SELECT CONNECTION_ID() AS id');
        $row = Database::row();
        $this->assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * Kills a server thread from a connection of its own, so the worker's link sees the loss as it
     * would see a server that went away.
     *
     * @param int $connectionId Thread id to kill
     * @throws EnvException When DB env variables are missing or invalid.
     */
    private function killConnection(int $connectionId): void
    {
        $killer = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        $killer->query("KILL {$connectionId}");
        $killer->close();
    }
}

/**
 * Browser context that counts the facts "the database came back" it was handed.
 */
final class WorkerDatabaseBackTestBrowserContext extends BrowserContext
{
    /** How many times the worker handed this context the fact. */
    public int $databaseBackCalls = 0;

    /**
     * Counts the fact, then records it as the framework does.
     */
    public function recordDatabaseBack(): void
    {
        $this->databaseBackCalls++;
        parent::recordDatabaseBack();
    }
}

/**
 * Worker manager driven by its dispatch alone, without a daemon socket or agents.
 */
final class WorkerDatabaseBackTestManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Puts a daemon client in place without opening a real connection.
     *
     * @param WorkerDaemonClient $client Client stub reporting itself connected
     */
    public function attachClient(WorkerDaemonClient $client): void
    {
        $this->daemonClient = $client;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerDatabaseBackTestAgentManager();
    }
}

/**
 * Agent manager stub: these cases never start an agent.
 */
final class WorkerDatabaseBackTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('The database-back cases never start an agent.');
    }
}

/**
 * Daemon client stub: connected, silent, with nothing to hand out.
 */
final class WorkerDatabaseBackTestClient extends WorkerDaemonClient
{
    public function __construct()
    {
        $this->state = DaemonConnectionState::CONNECTED;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function getNextMessage(): ?WorkerDTO
    {
        return null;
    }

    public function send(WorkerDTO|array $data): void
    {
    }
}
