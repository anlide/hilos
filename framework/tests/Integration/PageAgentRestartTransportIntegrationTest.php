<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Tests\Unit\Fixtures\AdminViewModeTestNode;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestManager;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestPages;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestWorkerManager;
use Socket;
use ReflectionProperty;

/**
 * Real typed IPC over a socket pair, from the page dispatcher through the registered worker
 * entrance and master bridge to the existing restart mechanism, then back to the action ack.
 * The sockets exercise framing and registration without another process. The fixture uses the
 * prepared primary connection, with the journal feature outside this transport scenario.
 */
final class PageAgentRestartTransportIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var array<int, DatabaseConnectionConfig> Configuration restored after this transport-only fixture */
    private array $previousConfigurations = [];
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRt;
    private ?BrowserContext $previousBrowser;
    private ?EnvAccessor $previousEnv;
    /** @var array<string, string|false> */
    private array $previousClusterEnv = [];
    /** @var list<Socket> */
    private array $sockets = [];
    private PageAgentRestartTestPages $pages;
    private PageAgentRestartTestManager $master;
    private WorkerClient $receiver;
    private PageAgentRestartTestWorkerManager $worker;
    private PageAgentRestartSocketWriter $writer;
    private SignalRouter $workerRouter;
    private SignalRouter $masterRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $configurationProperty = new ReflectionProperty(Database::class, 'configurations');
        $this->previousConfigurations = $configurationProperty->getValue();
        $transportConfiguration = $this->previousConfigurations;
        // The journal integration leaves its secondary configuration after dropping its tables.
        // This transport fixture runs without that feature and restores the configuration afterward.
        unset($transportConfiguration[ChangeLogDatabase::CONNECTION_INDEX]);
        $configurationProperty->setValue(null, $transportConfiguration);
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        $this->previousBrowser = Hilos::$browser;
        $this->previousEnv = Hilos::$env;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            $this->previousClusterEnv[$key] = getenv($key);
        }
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        Hilos::$browser = new IdentityTestBrowser(7, true);
        Hilos::$sr = $this->workerRouter = new SignalRouter();
        AdminViewModeTestNode::mount(false);
        $this->pages = new PageAgentRestartTestPages();
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_DAEMON_AGENTS, new WebSocketPageSubscribeSignalDTO(
            'accept-1', HilosPageConstants::HILOS_DAEMON_AGENTS, [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'node-a'],
        ));
        $this->master = new PageAgentRestartTestManager(runRestart: true);
        $this->masterRouter = Hilos::$sr;
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        foreach ($this->sockets as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
        }
        $this->writer = new PageAgentRestartSocketWriter($this->sockets[0]);
        $this->worker = new PageAgentRestartTestWorkerManager($this->writer);
        $this->receiver = new WorkerClient($this->sockets[1], $this->createMock(AgentManagerDaemon::class), $this->master->worker);
        $this->writer->send(new WorkerRegisterDTO(3, false));
        $this->writer->write();
        $this->receiver->read();
        $this->assertTrue($this->receiver->isRegistered());
        Hilos::$sr = $this->workerRouter;
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        AdminViewModeTestNode::unmount();
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$env = $this->previousEnv;
        foreach ($this->previousClusterEnv as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, $this->previousConfigurations);
        parent::tearDown();
    }

    public function testAdmittedPageRequestTraversesRealWorkerFramingAndExistingRestartRefusal(): void
    {
        $this->dispatch();
        $this->worker->drain();
        $this->writer->write();
        Hilos::$sr = $this->masterRouter;
        $this->receiver->read();
        $this->assertCount(1, $this->master->requests);
        $request = $this->master->requests[0];
        $this->assertSame('hilos_1397_unknown_agent', $request->payload[CommandConstants::FIELD_AGENT_TYPE]);
        $this->assertNull($request->originNodeId);
        $this->assertNull($request->payload[CommandConstants::FIELD_NODE_ID]);
        $this->master->drain();
        $this->assertCount(1, $this->master->answers);
        $done = $this->master->answers[0];
        $this->assertSame('No agent type hilos_1397_unknown_agent in this installation', $done->error);
        $this->deliverDoneThroughWorkerWire($done);
        $ack = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalConstants::ACTION_ERROR, $ack->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $ack->data);
        $this->assertSame('accept-1', $ack->data->targetAcceptKey);
        $this->assertSame('submit-1', $ack->data->data->requestId);
        $this->assertSame($done->error, $ack->data->data->reason);
        $this->assertNull($ack->data->data->errorType);
        $this->assertNull($ack->data->data->errorDetail);
    }

    public function testViewerCannotPutARestartOnTheWorkerLink(): void
    {
        AdminViewModeTestNode::mount(true);
        Hilos::$browser = new IdentityTestBrowser(5, false);
        $this->dispatch();
        $ack = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalConstants::ACTION_ERROR, $ack->signalName->getName());
        $this->assertSame('view_mode', $ack->data->data->errorCode);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->worker->drain();
        $this->writer->write();
        Hilos::$sr = $this->masterRouter;
        $this->receiver->read();
        $this->assertSame([], $this->master->requests);
        $this->assertSame([], $this->master->answers);
    }

    public function testWorkerControlStampsTheAcceptingMasterNodeAndKeepsTheRemoteTarget(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-origin');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $this->dispatch();
        $this->worker->drain();
        $this->writer->write();
        Hilos::$sr = $this->masterRouter;
        $this->receiver->read();
        $this->assertCount(1, $this->master->requests);
        $this->assertSame('node-origin', $this->master->requests[0]->originNodeId);
        $this->assertSame('node-a', $this->master->requests[0]->payload[CommandConstants::FIELD_NODE_ID]);
    }

    private function dispatch(): void
    {
        $this->pages->router->dispatchAction(new WebSocketActionSignalDTO(
            'accept-1', HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART,
            ['agentId' => 'hilos_1397_unknown_agent', 'nodeId' => 'node-a'], 'submit-1',
        ), 'websocket');
    }

    private function deliverDoneThroughWorkerWire(HandoverAnswerSignalData $done): void
    {
        // The same inner-envelope serialization the master sends to an agent's worker.
        $wrapped = AgentSignalData::fromJson(new AgentSignalData($done)->toJson());
        $this->assertInstanceOf(HandoverAnswerSignalData::class, $wrapped->data);
        Hilos::$sr = $this->workerRouter;
        $this->pages->router->dispatchAgentSignal($wrapped, 'daemon', HilosSignalConstants::DAEMON_AGENT_RESTART_DONE);
    }
}

/** WorkerDaemonClient's real buffer and writes over the already connected socket pair. */
final class PageAgentRestartSocketWriter extends WorkerDaemonClient
{
    /** @param Socket $socket Connected worker side of the pair */
    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}
