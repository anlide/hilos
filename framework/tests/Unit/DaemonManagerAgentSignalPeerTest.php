<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\AgentDeliveryOutcome;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Tests\Unit\Cluster\Peer\PeerSignalDTOTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The one door a node hands an agent signal out of, watched through its own port (HIL-851).
 *
 * {@see PeerServer::sendSignalToNode()} is how a signal reaches an agent on another node, and
 * until this file nothing pinned it beyond the shape of the frame it wraps
 * ({@see PeerSignalDTOTest}): the class is final, its constructor wants a socket, and the
 * addressee is picked by walking live links. {@see AgentSignalMesh} is the seam that makes the
 * send observable without opening any of that — the outbound mirror of {@see AgentSignalSink},
 * and the tenth port of the same family.
 *
 * The cases drive the ORDINARY pass, {@see DaemonManager::dispatchSignals()}, rather than the
 * delivery on its own: calling the delivery directly would prove it can send, while what is
 * owed here is that exactly one frame leaves during normal work. The outcome is read off
 * {@see DaemonManagerAgentSignalPeerTestManager::$outcomes} and never inferred from what the
 * pass does next — those reactions are a behavior of their own, held to account by
 * {@see DaemonManagerPlacedFanOutTest}, and reading through them would go red on its edits.
 *
 * What stays out of reach is the origin stamp: the local node id goes into the frame inside
 * PeerServer, behind the seam, so only a live multi-node run can show it.
 */
final class DaemonManagerAgentSignalPeerTest extends TestCase
{
    private const string AGENT_TYPE = DaemonManagerAgentSignalPeerTestRouter::AGENT_TYPE;

    private const string AGENT_INDEX = DaemonManagerAgentSignalPeerTestRouter::AGENT_INDEX;

    private const string REMOTE_NODE = 'node-B';

    private const string SIGNAL_NAME = 'agent_signal_peer_noop';

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$cluster = null;

        parent::tearDown();
    }

    /**
     * The whole point of the port in one case: the agent runs elsewhere, so one frame leaves and
     * this node's workers are left alone.
     */
    public function testAnAgentOnAnotherNodeIsReachedWithExactlyOneFrameAndNoLocalDelivery(): void
    {
        $manager = $this->clusteredManager();
        $this->placeTheAgent(AgentLocation::onNode(self::REMOTE_NODE));

        $this->queueSignalForTheAgent();
        $manager->drainQueue();

        $this->assertCount(1, $manager->mesh->frames);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([AgentDeliveryOutcome::Delivered], $manager->outcomes);
    }

    /**
     * The frame names the node the placement picked and carries the address the router resolved,
     * signal and all - a send that reached the port with anything else in it would be a frame
     * arriving at the wrong agent, which no assertion on the count can see.
     */
    public function testTheFrameCarriesTheTargetNodeTheAgentAddressAndTheSignal(): void
    {
        $manager = $this->clusteredManager();
        $this->placeTheAgent(AgentLocation::onNode(self::REMOTE_NODE));
        $payload = $this->queueSignalForTheAgent();

        $manager->drainQueue();

        $frame = $manager->mesh->frames[0] ?? null;
        $this->assertNotNull($frame);
        $this->assertSame(self::REMOTE_NODE, $frame['targetNodeId']);
        $this->assertSame(self::AGENT_TYPE, $frame['agentType']);
        $this->assertSame(self::AGENT_INDEX, $frame['agentIndex']);
        $this->assertSame(self::SIGNAL_NAME, $frame['signal']->signalName->getName());
        // The pass builds the DTO itself, so the payload is what identity can be asked about -
        // and it is the half a wrong frame would carry from another signal.
        $this->assertSame($payload, $frame['signal']->data);
    }

    /**
     * A dead link is the port's own answer, not a missing one: the frame was built and addressed,
     * and only then found nobody to carry it. Standing a real peer server with no links in the
     * port's place would show the absence of a delivery and nothing about the frame.
     */
    public function testADeadLinkIsReportedUnreachableWithTheFrameStillBuilt(): void
    {
        $manager = $this->clusteredManager();
        $manager->mesh->linkIsDead = true;
        $this->placeTheAgent(AgentLocation::onNode(self::REMOTE_NODE));

        $this->queueSignalForTheAgent();
        $manager->drainQueue();

        $this->assertSame([AgentDeliveryOutcome::RemoteUnreachable], $manager->outcomes);
        $this->assertCount(1, $manager->mesh->frames);
    }

    /**
     * Cluster mode off, or a master that has not registered a peer server: the same verdict as a
     * dead link, and this time nothing is built at all.
     */
    public function testAnUnregisteredPeerServerIsReportedUnreachableWithNoFrameBuilt(): void
    {
        $manager = new DaemonManagerAgentSignalPeerTestManager();
        $this->placeTheAgent(AgentLocation::onNode(self::REMOTE_NODE));

        $this->queueSignalForTheAgent();
        $manager->drainQueue();

        $this->assertSame([AgentDeliveryOutcome::RemoteUnreachable], $manager->outcomes);
        $this->assertSame([], $manager->mesh->frames);
    }

    /**
     * The control the four above are worth nothing without: an agent running here goes to the
     * workers and touches no port. Without it "exactly one frame left" stays green on a placement
     * broken the other way, which would send everything out.
     */
    public function testAnAgentPlacedHereIsDeliveredLocallyAndReachesNoPort(): void
    {
        $manager = $this->clusteredManager();
        $this->placeTheAgent(AgentLocation::here());

        $this->queueSignalForTheAgent();
        $manager->drainQueue();

        $this->assertSame([], $manager->mesh->frames);
        $this->assertSame(
            [self::AGENT_TYPE . ':' . self::AGENT_INDEX],
            $manager->workerServer->deliveries,
        );
        $this->assertSame([AgentDeliveryOutcome::Delivered], $manager->outcomes);
    }

    /**
     * Builds a master with a real peer server among its servers, which is what the pass looks for
     * before it hands the delivery a port at all.
     *
     * @return DaemonManagerAgentSignalPeerTestManager Master on a cluster, with its recording port
     */
    private function clusteredManager(): DaemonManagerAgentSignalPeerTestManager
    {
        $manager = new DaemonManagerAgentSignalPeerTestManager();
        $manager->registerServer(new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            [],
        ));

        return $manager;
    }

    /**
     * Registers a placement lookup answering one location for this file's agent.
     *
     * @param AgentLocation $location Where the lookup reports the agent running
     */
    private function placeTheAgent(AgentLocation $location): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new DaemonManagerAgentSignalPeerTestPlacement(
            self::AGENT_TYPE . ':' . self::AGENT_INDEX,
            $location,
        ));
        Hilos::$cluster = $context;
    }

    /**
     * Queues the one signal every case dispatches, and hands back the payload it was queued with.
     *
     * @return SignalData Payload the frame is expected to carry out of this node
     */
    private function queueSignalForTheAgent(): SignalData
    {
        $payload = new SignalData([]);
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT),
            new SignalType(self::SIGNAL_NAME),
            new SignalName(self::SIGNAL_NAME),
            $payload,
        );

        return $payload;
    }
}

/**
 * Master that routes one signal to one agent and records what the delivery answered.
 *
 * The port is substituted only where the pass found a real one: passing the fake on for a pass
 * that found nothing would let the substitution answer the "no peer server" case instead of the
 * code. The same reasoning, and the same line, as
 * {@see DaemonManagerRtSyncPeerTestManager::broadcastRtSyncToPeers()}.
 */
final class DaemonManagerAgentSignalPeerTestManager extends DaemonManager
{
    /** The stand-in port every cross-node frame is written to */
    public readonly DaemonManagerAgentSignalPeerTestMesh $mesh;

    /** The stand-in worker pool a locally placed agent is reached through */
    public readonly DaemonManagerAgentSignalPeerTestWorkerServer $workerServer;

    /** @var list<AgentDeliveryOutcome> What the delivery answered, once per call */
    public array $outcomes = [];

    public function __construct()
    {
        parent::__construct();

        $this->mesh = new DaemonManagerAgentSignalPeerTestMesh();
        $this->workerServer = new DaemonManagerAgentSignalPeerTestWorkerServer();
        $this->registerServer($this->workerServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * Records the outcome and sends the delivery on through the recording port.
     *
     * @param WorkerServer $workerServer Worker server hosting the agents of this node
     * @param ?AgentSignalMesh $mesh Port the pass found, or null when no peer server is registered
     * @param AgentAddressedDestination $destination Agent to reach, already placed
     * @param SignalDTO $signal Signal to deliver
     * @return AgentDeliveryOutcome What the delivery answered
     * @throws AgentException When a local agent cannot be reached and the daemon is not shutting down
     * @throws HilosException Whatever the project's agent-daemon factory raises while the local agent starts
     */
    protected function deliverToAgentDestination(
        WorkerServer $workerServer,
        ?AgentSignalMesh $mesh,
        AgentAddressedDestination $destination,
        SignalDTO $signal,
    ): AgentDeliveryOutcome {
        $outcome = parent::deliverToAgentDestination(
            $workerServer,
            $mesh === null ? null : $this->mesh,
            $destination,
            $signal,
        );
        $this->outcomes[] = $outcome;

        return $outcome;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new DaemonManagerAgentSignalPeerTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerAgentSignalPeerTestAgentManagerDaemon();
    }
}

/**
 * The outgoing port, standing in for the peer server and remembering every frame it was handed.
 */
final class DaemonManagerAgentSignalPeerTestMesh implements AgentSignalMesh
{
    /**
     * @var list<array{targetNodeId: string, agentType: string, agentIndex: ?string, signal: SignalDTO}>
     *     Frames handed to this port, in order
     */
    public array $frames = [];

    /** Whether the link this port stands for is gone, so a frame is built and then not carried */
    public bool $linkIsDead = false;

    /**
     * @param string $targetNodeId Id of the node hosting the target agent
     * @param string $agentType Resolved target agent type
     * @param ?string $agentIndex Resolved target agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver on the target node
     * @return bool True unless this case declared the link dead
     */
    public function sendSignalToNode(string $targetNodeId, string $agentType, ?string $agentIndex, SignalDTO $signal): bool
    {
        $this->frames[] = [
            'targetNodeId' => $targetNodeId,
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
            'signal' => $signal,
        ];

        return !$this->linkIsDead;
    }
}

/**
 * A worker server that records the handoff instead of starting a process.
 */
final class DaemonManagerAgentSignalPeerTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agents this node delivered to, as `<agent type>[:<index>]`, in order */
    public array $deliveries = [];

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for an indexed agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->deliveries[] = $agentIndex === null ? $agentType : "{$agentType}:{$agentIndex}";
    }

    protected function onStart(): void
    {
    }
}

/**
 * Router contributing the one agent destination every case is about, so the pass has something
 * to place and nothing else to carry.
 */
final class DaemonManagerAgentSignalPeerTestRouter extends SignalRouter
{
    public const string AGENT_TYPE = 'agent_signal_peer_agent';

    public const string AGENT_INDEX = '7';

    /**
     * @param SignalDTO $signal Signal being routed
     * @return list<Destination> The one agent this file addresses
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return [new AgentDestination(self::AGENT_TYPE, self::AGENT_INDEX)];
    }
}

/**
 * Placement lookup answering one location for one agent id, and "here" for anything else.
 */
final class DaemonManagerAgentSignalPeerTestPlacement implements WorkerPlacement
{
    /**
     * @param string $placedAgentId Agent id this lookup has an answer for
     * @param AgentLocation $location Where that agent runs
     */
    public function __construct(private readonly string $placedAgentId, private readonly AgentLocation $location)
    {
    }

    public function locate(string $agentType, ?string $agentIndex): AgentLocation
    {
        $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

        return $agentId === $this->placedAgentId ? $this->location : AgentLocation::here();
    }
}

final class DaemonManagerAgentSignalPeerTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}
