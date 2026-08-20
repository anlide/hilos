<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\Worker\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\TestCase;
use Closure;
use RuntimeException;

/**
 * Unit tests for WorkerManager browser context hooks.
 */
final class WorkerManagerBrowserContextTest extends TestCase
{
    /** Temporary main log file the flush-failure case reads its line back from */
    private string $logFile = '';

    public function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-browser-context');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();

        parent::setUp();
    }

    public function tearDown(): void
    {
        Hilos::$browser = null;
        Hilos::$sr = null;
        Logger::resetLogFile();
        WorkerTickFailureLog::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testWorkerRecordsDbRtSourceChangesInBrowserContext(): void
    {
        $browser = new WorkerManagerBrowserContextTestBrowserContext();
        Hilos::$browser = $browser;

        $manager = new WorkerManagerBrowserContextTestManager(new WorkerManagerBrowserContextTestAgent());

        $recordSourceChange = Closure::bind(
            static function (WorkerManager $manager, SyncSignalDataInterface $signalData): void {
                $manager->recordBrowserSourceChange($signalData);
            },
            null,
            WorkerManager::class,
        );

        foreach ([
            new DbSyncCreatedSignalData('users', '1', ['name' => 'Ada']),
            new DbSyncUpdatedSignalData('users', '1', ['name' => 'Grace']),
            new DbSyncDeletedSignalData('users', '1', ['name' => 'Grace']),
            new RtSyncCreatedSignalData('connections', 'ak-1', ['userId' => 1]),
            new RtSyncUpdatedSignalData('connections', 'ak-1', ['presence' => 'online']),
            new RtSyncDeletedSignalData('connections', 'ak-1', ['presence' => 'online']),
        ] as $signalData) {
            $recordSourceChange($manager, $signalData);
        }

        $this->assertTrue($browser->hasChanges());

        $browser->flushToSignalRouter();

        $this->assertFalse($browser->hasChanges());
        $this->assertCount(1, $browser->emittedChangeSets);

        $changes = $browser->emittedChangeSets[0]->all();
        $this->assertCount(2, $changes);
        $this->assertSame(
            [
                SourceChange::KIND_DB,
                SourceChange::KIND_RT,
            ],
            array_map(static fn(SourceChange $change): string => $change->kind, $changes),
        );
        $this->assertSame(
            [
                TableMutationType::Delete,
                TableMutationType::Delete,
            ],
            array_map(static fn(SourceChange $change): TableMutationType => $change->mutationType, $changes),
        );
        $this->assertSame(['name' => 'Grace'], $changes[0]->row);
        $this->assertSame(['userId' => 1, 'presence' => 'online'], $changes[1]->row);
    }

    /**
     * The fan-out contains a failed subscription but writes nothing: the record belongs
     * to the tick that asked for the flush, together with the project's chance to answer.
     */
    public function testASubscriptionThatFailedInTheFlushReachesTheWorkerLineAndTheHook(): void
    {
        $browser = new WorkerManagerBrowserContextTestBrowserContext();
        $failure = new RuntimeException('the row refused to be built');
        $browser->containedByFanout = [
            new ContainedFailure(WorkerTickUnit::BROWSER_SUBSCRIPTION, 'page=chat acceptKey=ak-1', $failure),
        ];
        $browser->record(SourceChange::dbUpdated('users', '1', ['name' => 'Ada']));
        Hilos::$browser = $browser;

        $manager = new WorkerManagerBrowserContextTestManager(new WorkerManagerBrowserContextTestAgent());
        $manager->attachClient(new WorkerManagerBrowserContextTestClient());

        $dispatchSignals = Closure::bind(
            static function (WorkerManager $manager): void {
                $manager->dispatchSignals();
            },
            null,
            WorkerManager::class,
        );
        $dispatchSignals($manager);

        $this->assertCount(1, $manager->containedFailures);
        $this->assertSame(WorkerTickUnit::BROWSER_SUBSCRIPTION, $manager->containedFailures[0]->unit);
        $this->assertSame($failure, $manager->containedFailures[0]->failure);
        $this->assertStringContainsString(
            'contained a failure in browser subscription (page=chat acceptKey=ak-1)',
            (string)file_get_contents($this->logFile),
        );
    }
}

final class WorkerManagerBrowserContextTestBrowserContext extends BrowserContext
{
    /** @var list<SourceChangeSet> */
    public array $emittedChangeSets = [];

    /** @var list<ContainedFailure> Subscriptions the scripted fan-out reports as failed */
    public array $containedByFanout = [];

    /**
     * Records the changes that reached the final browser emit hook.
     *
     * @return list<ContainedFailure> Subscriptions this flush is scripted to fail on
     */
    protected function emitBrowserSignals(): array
    {
        $this->emittedChangeSets[] = $this->changes;

        return $this->containedByFanout;
    }
}

final class WorkerManagerBrowserContextTestManager extends WorkerManager
{
    /** @var list<ContainedFailure> What the guard handed to the project, in order. */
    public array $containedFailures = [];

    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
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
        return new WorkerManagerBrowserContextTestAgentManager($this->testAgent);
    }

    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->containedFailures[] = $failure;
    }
}

/**
 * Daemon client stub: connected, silent, with nothing to hand out.
 */
final class WorkerManagerBrowserContextTestClient extends WorkerDaemonClient
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

final class WorkerManagerBrowserContextTestAgentManager extends AgentManager
{
    public function __construct(
        private readonly WorkerManagerBrowserContextTestAgent $testAgent,
    ) {
    }

    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return $this->testAgent;
    }
}

final class WorkerManagerBrowserContextTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_browser_context';

    public function onStop(): void
    {
    }
}
