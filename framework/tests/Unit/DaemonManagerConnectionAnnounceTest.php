<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * How this node tells the mesh which browsers are attached to it (HIL-668).
 *
 * The announcement is a DIFF of the socket set taken once per loop iteration, and every case
 * below is about why it is a diff and not a pair of hooks. A connection ends in the master
 * from three different places — the orderly close, the discard on a read error, the detach at
 * shutdown — so a hook would have to be hung on each, and the one that got missed would leave
 * a ghost in every other node's index: an agent answering that browser would address a node
 * that has nowhere to put the frame, forever. The diff cannot miss a path because it never
 * looks at the paths.
 *
 * The silence cases matter as much as the announcing ones: this runs on every tick of the
 * daemon loop, so a set that did not move must produce no frame at all.
 */
final class DaemonManagerConnectionAnnounceTest extends TestCase
{
    /** @var ?ClusterContext Previous cluster context to restore after the case */
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerClientConnections(new ClusterClientLocation());
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The plain case: a browser attached here is news to every other node, because that is how
     * an agent anywhere in the cluster learns it can be answered.
     */
    public function testAConnectedClientIsAnnouncedAsOpened(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');

        $daemon->announce();

        $this->assertSame([['opened' => ['ak-1'], 'closed' => []]], $daemon->mesh->deltas);
    }

    /**
     * The step runs every tick, so an unchanged set has to cost nothing on the wire.
     */
    public function testAnUnchangedSetIsNotAnnouncedAgain(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');
        $daemon->announce();

        $daemon->announce();

        $this->assertCount(1, $daemon->mesh->deltas);
    }

    /**
     * A connection gone from the set is announced closed whichever way it went — this is the
     * whole reason the local half is a diff.
     */
    public function testADisappearedClientIsAnnouncedAsClosed(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');
        $daemon->announce();

        $daemon->webSocketServer->drop('ak-1');
        $daemon->announce();

        $this->assertSame(['opened' => [], 'closed' => ['ak-1']], $daemon->mesh->deltas[1]);
    }

    /**
     * Both directions of one tick travel as one frame: a reconnect storm after a node restart
     * is a great many of these, and the batching is what keeps it one frame per tick.
     */
    public function testOneTickSAnnouncementCarriesBothDirections(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');
        $daemon->announce();

        $daemon->webSocketServer->drop('ak-1');
        $daemon->webSocketServer->connect('ak-2');
        $daemon->webSocketServer->connect('ak-3');
        $daemon->announce();

        $this->assertSame(['opened' => ['ak-2', 'ak-3'], 'closed' => ['ak-1']], $daemon->mesh->deltas[1]);
    }

    /**
     * A socket that has not finished its handshake has no accept key yet, so there is no
     * address to announce. Announcing it blank would put an entry in every peer's index that
     * nothing can ever be delivered to.
     */
    public function testASocketWithoutAnAcceptKeyIsNotAnnounced(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('');

        $daemon->announce();

        $this->assertSame([], $daemon->mesh->deltas);
    }

    /**
     * Off-cluster there is no peer server, and this whole step is the null it is handed.
     */
    public function testWithoutAPeerMeshNothingIsAnnounced(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');

        $daemon->announceWithoutMesh();

        $this->assertSame([], $daemon->mesh->deltas);
    }

    /**
     * A node that has just linked is behind on everything, so it gets the set whole rather than
     * the changes — and the set it gets is the one the deltas have been describing.
     */
    public function testANodeThatLinkedIsHandedTheWholeAnnouncedSet(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();
        $daemon->webSocketServer->connect('ak-1');
        $daemon->webSocketServer->connect('ak-2');
        $daemon->announce();

        $daemon->handOverTo('node-b');

        $this->assertSame([['node-b', ['ak-1', 'ak-2']]], $daemon->mesh->snapshots);
    }

    /**
     * A node linking to one that holds no browser is told exactly that: the empty set is what
     * makes its index right, and leaving it unsaid would be indistinguishable from a lost frame.
     */
    public function testANodeThatLinkedToAnEmptyOneIsHandedNothing(): void
    {
        $daemon = new DaemonManagerConnectionAnnounceTestManager();

        $daemon->handOverTo('node-b');

        $this->assertSame([['node-b', []]], $daemon->mesh->snapshots);
    }
}

/**
 * A daemon whose only registered server is a WebSocket one, driven a tick at a time.
 */
final class DaemonManagerConnectionAnnounceTestManager extends DaemonManager
{
    /** The stand-in mesh every announcement is written to */
    public readonly DaemonManagerConnectionAnnounceTestMesh $mesh;

    /** The stand-in WebSocket server whose connections the diff reads */
    public readonly DaemonManagerConnectionAnnounceTestWebSocketServer $webSocketServer;

    public function __construct()
    {
        parent::__construct();

        $this->mesh = new DaemonManagerConnectionAnnounceTestMesh();
        $this->webSocketServer = new DaemonManagerConnectionAnnounceTestWebSocketServer();
        $this->registerServer($this->webSocketServer);
    }

    /**
     * Runs the announcement step of one loop iteration.
     */
    public function announce(): void
    {
        $this->announceConnectionChanges($this->mesh);
    }

    /**
     * Runs the same step as a node that has no peer transport at all.
     */
    public function announceWithoutMesh(): void
    {
        $this->announceConnectionChanges(null);
    }

    /**
     * Hands this node's connections to a node that has just linked.
     *
     * @param string $nodeId Node the set goes to
     */
    public function handOverTo(string $nodeId): void
    {
        $this->sendConnectionsToNode($this->mesh, $nodeId);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerConnectionAnnounceTestAgentManagerDaemon();
    }
}

/**
 * A mesh that keeps what it was told instead of sending it.
 */
final class DaemonManagerConnectionAnnounceTestMesh implements ClientMesh
{
    /** @var list<array{0: string, 1: list<string>}> Node and key set of each hand-over, in order */
    public array $snapshots = [];

    /** @var list<array{opened: list<string>, closed: list<string>}> Each delta announced, in order */
    public array $deltas = [];

    /**
     * @param string $nodeId Id of the node holding the connection
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Signal to deliver on that node
     * @return bool True always; these cases forward no signal
     */
    public function sendSignalToClientNode(string $nodeId, string $acceptKey, SignalDTO $signal): bool
    {
        return true;
    }

    /**
     * @param SignalDTO $signal Signal every node expands against its own subscription registry
     */
    public function broadcastClientFanout(SignalDTO $signal): void
    {
    }

    /**
     * @param string $nodeId Node this one can now reach
     * @param list<string> $acceptKeys Every accept key this node holds right now
     */
    public function sendConnectionsSnapshotToNode(string $nodeId, array $acceptKeys): void
    {
        $this->snapshots[] = [$nodeId, $acceptKeys];
    }

    /**
     * @param list<string> $opened Accept keys this node has gained
     * @param list<string> $closed Accept keys this node has lost
     */
    public function broadcastConnectionsDelta(array $opened, array $closed): void
    {
        $this->deltas[] = ['opened' => $opened, 'closed' => $closed];
    }
}

/**
 * A WebSocket server whose client list the test writes directly.
 */
final class DaemonManagerConnectionAnnounceTestWebSocketServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a connection in front of the diff as an accepted socket would.
     *
     * @param string $acceptKey Accept key the connection carries; blank stands for one still handshaking
     */
    public function connect(string $acceptKey): void
    {
        $this->clients[] = new DaemonManagerConnectionAnnounceTestClient($acceptKey);
    }

    /**
     * Takes a connection out of the list the way any of the three close paths would.
     *
     * @param string $acceptKey Accept key of the connection that ended
     */
    public function drop(string $acceptKey): void
    {
        $this->clients = array_values(array_filter(
            $this->clients,
            static fn($client) => !$client instanceof DaemonManagerConnectionAnnounceTestClient
                || $client->acceptKey !== $acceptKey,
        ));
    }

    /**
     * @return string Server name the failure card names
     */
    public function getServerName(): string
    {
        return 'connection-announce-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Never returned; this server accepts nothing
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new AgentDaemonCreationFailedException('the connection announce test accepts no connection');
    }
}

/**
 * A handshaked browser connection with no socket behind it.
 *
 * The real constructor wants a live socket and the environment, and the diff reads neither -
 * only the accept key, which is the whole of what a connection is to the index.
 */
final class DaemonManagerConnectionAnnounceTestClient extends WebSocketClient
{
    /**
     * @param string $acceptKey Accept key this connection carries
     */
    public function __construct(string $acceptKey)
    {
        $this->acceptKey = $acceptKey;
    }

    /**
     * @param array<string, string> $headers Handshake headers
     * @param string $acceptKey Accept key minted for the connection
     * @param array<string, string> $cookies Handshake cookies
     * @param ?string $clientIp Client address
     * @param RequestQueryParams $queryParams Handshake query params
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }
}

/**
 * An agent manager the cases never reach.
 */
final class DaemonManagerConnectionAnnounceTestAgentManagerDaemon extends AgentManagerDaemon
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
