<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotFoundException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The two doors the master hands work out through (HIL-618).
 *
 * Project code on the master loop may not do the work it discovers, only say what happened -
 * to a named agent or to every worker of this node. What is checked here is the part a caller
 * cannot see for itself: which route the agent door takes, that nothing is raised back at the
 * loop when delivery fails, and that a failure is not silent.
 *
 * The placement branch is the case with teeth. Delivering locally to an agent placed on
 * another node would START a second copy of it here, and for a singleton agent that is the
 * one outcome placement exists to prevent - so the remote case asserts the local worker
 * server was left alone, not merely that something was logged.
 */
final class DaemonManagerSignalFacadeTest extends TestCase
{
    private const string SIGNAL_NAME = 'project_master_reaction';

    private const string AGENT_TYPE = 'facade_test_agent';

    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    /** Cluster context in place before a case installed its own, restored on the way out */
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-master-signal-facade');
        Logger::setLogFile($this->logFile);
        $this->previousCluster = Hilos::$cluster;
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testASignalToALocalAgentArrivesAsADaemonAgentSignal(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->addWorkerServer();

        $manager->sendToAgent(self::AGENT_TYPE, '7', self::SIGNAL_NAME, new SignalData(['reason' => 'degraded']));

        $delivered = $manager->workerServer?->delivered[0] ?? null;
        $this->assertInstanceOf(DaemonAgentMessageDTO::class, $delivered);
        $this->assertSame(self::AGENT_TYPE . ':7', $delivered->agentId);
        $this->assertSame(SignalSource::DAEMON, $delivered->signal->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $delivered->signal->signalType->getType());
        $this->assertSame(self::SIGNAL_NAME, $delivered->signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $delivered->signal->data);
        $this->assertSame(['reason' => 'degraded'], $delivered->signal->data->data->toArray());
        $this->assertSame('', $this->written());
    }

    /**
     * A singleton agent is addressed without an index, and the id it is delivered under is the
     * bare type - the same neutral element the rest of the framework uses.
     */
    public function testASingletonAgentIsAddressedByItsBareType(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->addWorkerServer();

        $manager->sendToAgent(self::AGENT_TYPE, null, self::SIGNAL_NAME, new SignalData([]));

        $this->assertSame(self::AGENT_TYPE, $manager->workerServer?->delivered[0]->agentId);
    }

    public function testAnAgentPlacedOnAnotherNodeIsNotDeliveredToLocally(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->addWorkerServer();
        $manager->addPeerServer();
        $this->installPlacement([self::AGENT_TYPE . ':7' => AgentLocation::onNode('node-b')]);

        $manager->sendToAgent(self::AGENT_TYPE, '7', self::SIGNAL_NAME, new SignalData([]));

        $this->assertSame([], $manager->workerServer?->delivered);
        $this->assertStringContainsString('no live link to node node-b', $this->written());
        $this->assertStringContainsString(self::SIGNAL_NAME, $this->written());
    }

    /**
     * Placement points off-node and the transport that would carry it there is not running:
     * still not a local delivery, and still a line naming the node nobody could be reached on.
     */
    public function testAForwardWithNoPeerServerIsWrittenAndNotDeliveredLocally(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->addWorkerServer();
        $this->installPlacement([self::AGENT_TYPE => AgentLocation::onNode('node-b')]);

        $manager->sendToAgent(self::AGENT_TYPE, null, self::SIGNAL_NAME, new SignalData([]));

        $this->assertSame([], $manager->workerServer?->delivered);
        $this->assertStringContainsString('no peer server for node node-b', $this->written());
    }

    public function testAnAgentSignalWithNoWorkerServerIsWrittenWithItsAddressee(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();

        $manager->sendToAgent(self::AGENT_TYPE, '7', self::SIGNAL_NAME, new SignalData([]));

        $written = $this->written();
        $this->assertStringContainsString("Master signal '" . self::SIGNAL_NAME . "'", $written);
        $this->assertStringContainsString('agent ' . self::AGENT_TYPE . ':7', $written);
        $this->assertStringContainsString('no worker server', $written);
    }

    /**
     * The whole point of the void return: delivery raising is a normal outcome of a link that
     * died, and letting it out would end run() and take the node down with it.
     */
    public function testADeliveryThatRaisesIsSwallowedAndWritten(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->addWorkerServer()->refuseWith(new AgentNotFoundException(self::AGENT_TYPE));

        $manager->sendToAgent(self::AGENT_TYPE, null, self::SIGNAL_NAME, new SignalData([]));

        $written = $this->written();
        $this->assertStringContainsString(AgentNotFoundException::class, $written);
        $this->assertStringContainsString('agent ' . self::AGENT_TYPE, $written);
    }

    /**
     * A node on its way out has no workers left by design, so the same refusal is news at a
     * lower volume - the distinction the routed path already draws.
     */
    public function testARefusalWhileTheNodeIsLeavingIsWrittenAsInfo(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $manager->startLeaving();

        $manager->sendToWorkers(self::SIGNAL_NAME, new SignalData([]));

        $written = $this->written();
        $this->assertStringContainsString('no worker server', $written);
        // Level shows as an "ERROR:" prefix on the line; info carries none.
        $this->assertStringNotContainsString('ERROR:', $written);
    }

    public function testASignalToWorkersReachesEveryWorkerLink(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();

        $manager->sendToWorkers(self::SIGNAL_NAME, new SignalData(['reason' => 'degraded']));

        $frames = $workerServer->framesPerWorker();
        $this->assertCount(2, $frames);
        foreach ($frames as $ofOneWorker) {
            $this->assertCount(1, $ofOneWorker);
            $restored = WorkerDTO::factoryWorkerDTO($ofOneWorker[0]);
            $this->assertInstanceOf(DaemonWorkerSignalDTO::class, $restored);
            $this->assertSame(self::SIGNAL_NAME, $restored->signalName);
            $this->assertSame(['reason' => 'degraded'], $restored->data->toArray());
        }
        $this->assertSame('', $this->written());
    }

    /**
     * An unnamed signal is a caller's mistake, and both doors answer it the same way: nothing
     * is sent, and the line says which door was knocked on.
     */
    public function testAnEmptySignalNameSendsNothingAndIsWritten(): void
    {
        $manager = new DaemonManagerSignalFacadeTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();

        $manager->sendToAgent(self::AGENT_TYPE, null, '', new SignalData([]));
        $manager->sendToWorkers('', new SignalData([]));

        $this->assertSame([], $workerServer->delivered);
        $this->assertSame([[]], $workerServer->framesPerWorker());
        $written = $this->written();
        $this->assertStringContainsString('to agent ' . self::AGENT_TYPE . ' dropped: empty signal name', $written);
        $this->assertStringContainsString('to workers dropped: empty signal name', $written);
    }

    /**
     * Registers a fake placement lookup mapping "type:index" (or "type") to a location.
     *
     * An agent the map does not name is answered "here", so the cases that assert local
     * delivery need no entry of their own.
     *
     * @param array<string, AgentLocation> $placements Agent id to the location it is at
     */
    private function installPlacement(array $placements): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($placements) implements WorkerPlacement {
            /**
             * @param array<string, AgentLocation> $placements Agent id to the location it is at
             */
            public function __construct(private readonly array $placements)
            {
            }

            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

                return $this->placements[$agentId] ?? AgentLocation::here();
            }
        });

        Hilos::$cluster = $context;
    }

    /**
     * Reads back whatever the facade wrote to the temporary log.
     *
     * @return string Log contents, empty when the facade stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager whose servers are registered per case, because half of what is asserted here
 * is what the facade does when the server it wants is not there.
 */
final class DaemonManagerSignalFacadeTestManager extends DaemonManager
{
    /** Stand-in worker server, absent until a case registers one */
    public ?DaemonManagerSignalFacadeTestWorkerServer $workerServer = null;

    /**
     * Registers the stand-in worker server both doors look for.
     *
     * @return DaemonManagerSignalFacadeTestWorkerServer The registered stand-in, for arranging the case
     */
    public function addWorkerServer(): DaemonManagerSignalFacadeTestWorkerServer
    {
        $this->workerServer = new DaemonManagerSignalFacadeTestWorkerServer();
        $this->registerServer($this->workerServer);

        return $this->workerServer;
    }

    /**
     * Registers a real peer server with no live links - the shape a forward finds when the
     * hosting node is unreachable. The class is final, so there is no stand-in to put here.
     */
    public function addPeerServer(): void
    {
        $this->registerServer(new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            [],
        ));
    }

    /**
     * Puts the node into the shutdown state that lowers the level of a dropped-signal line.
     */
    public function startLeaving(): void
    {
        $this->shouldExit = true;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerSignalFacadeTestAgentManagerDaemon();
    }
}

final class DaemonManagerSignalFacadeTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that records both doors instead of owning processes: the per-agent handoff
 * and the frames written to each worker link.
 */
final class DaemonManagerSignalFacadeTestWorkerServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> Per-agent frames the facade handed over, in order */
    public array $delivered = [];

    /** What the delivery path raises for this case, or null when it accepts */
    private ?AgentNotFoundException $refusal = null;

    public function __construct()
    {
    }

    /**
     * Makes the delivery path raise, the way a dead link or a missing agent makes it raise.
     *
     * @param AgentNotFoundException $refusal Failure the delivery path answers with
     */
    public function refuseWith(AgentNotFoundException $refusal): void
    {
        $this->refusal = $refusal;
    }

    /**
     * Adds one more worker link for the broadcast door to write to.
     */
    public function addWorker(): void
    {
        $this->clients[] = new DaemonManagerSignalFacadeTestWorkerClient();
    }

    /**
     * @return list<list<string>> Raw frames each worker link was written, one entry per link
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DaemonManagerSignalFacadeTestWorkerClient) {
                $frames[] = $client->frames;
            }
        }

        return $frames;
    }

    /**
     * @param string $agentType Agent type the signal was addressed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     * @throws AgentNotFoundException When the case arranged a refusal
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $this->delivered[] = $messageDto;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that keeps what was written to it instead of owning a socket.
 */
final class DaemonManagerSignalFacadeTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the master wrote to this link, in order */
    public array $frames = [];

    public function __construct()
    {
    }

    /**
     * @param string $message Frame the master wants written to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }
}
