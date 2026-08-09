<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\TestCase;

/**
 * A signal whose source names no agent type must still reach the daemon.
 *
 * This is the browser flush: {@see BrowserContext} queues its deliveries with
 * SignalSource::WORKER and no type, and {@see AgentManagerDaemon::handleAgentMessage()}
 * never reads the agent id — it re-queues the inner signal and lets the daemon route it
 * by signal type and WebSocket target metadata. Skipping the send instead of passing an
 * unaddressed id silently drops every server-to-browser delivery a worker makes, which
 * is what HIL-468 did on its first pass: the chat e2e suite went red everywhere except
 * the one spec that needs no backend at all.
 */
final class WorkerManagerAgentIdDispatchTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testBrowserFlushWithoutASourceAgentTypeStillReachesTheDaemon(): void
    {
        $manager = new WorkerManagerAgentIdTestManager();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('ws_user_signal'),
            new SignalData(),
        );

        $this->dispatchQueuedSignals($manager);

        $this->assertCount(1, $manager->daemonClientDouble->sent);
        $this->assertInstanceOf(WorkerAgentMessageDTO::class, $manager->daemonClientDouble->sent[0]);
    }

    public function testASourceAgentTypeBecomesTheAgentId(): void
    {
        $manager = new WorkerManagerAgentIdTestManager();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT, WorkerManagerAgentIdTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('ws_user_signal'),
            new SignalData(),
        );

        $this->dispatchQueuedSignals($manager);

        $sent = $manager->daemonClientDouble->sent[0];
        $this->assertInstanceOf(WorkerAgentMessageDTO::class, $sent);
        $this->assertSame(WorkerManagerAgentIdTestAgent::AGENT_TYPE, $sent->agentId);
    }

    /**
     * Drains the worker queue into the daemon client double.
     *
     * @param WorkerManager $manager Manager under test
     */
    private function dispatchQueuedSignals(WorkerManager $manager): void
    {
        $dispatch = Closure::bind(
            static function (WorkerManager $manager): void {
                $manager->dispatchQueuedSignalsToDaemon();
            },
            null,
            WorkerManager::class,
        );

        $dispatch($manager);
    }
}

final class WorkerManagerAgentIdTestManager extends WorkerManager
{
    public readonly WorkerManagerAgentIdTestDaemonClient $daemonClientDouble;

    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClientDouble = new WorkerManagerAgentIdTestDaemonClient();
        $this->daemonClient = $this->daemonClientDouble;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerAgentIdTestAgentManager();
    }
}

final class WorkerManagerAgentIdTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new WorkerManagerAgentIdTestAgent();
    }
}

final class WorkerManagerAgentIdTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_agent_id_dispatch';

    public function onStop(): void
    {
    }
}

final class WorkerManagerAgentIdTestDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Everything the dispatch handed to the daemon */
    public array $sent = [];

    public function __construct()
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the dispatch wants delivered
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }
}
