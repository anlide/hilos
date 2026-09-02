<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDbReHydratedDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Database\ReHydrateBarrierSink;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The mesh half of the re-hydrate barrier (HIL-436, HIL-694).
 *
 * The nodes of a cluster share one database, so a restore run on the leader leaves the others
 * answering out of caches of a database that no longer exists. The announcement therefore crosses
 * the mesh, and each node answers for itself and its own workers with one frame.
 *
 * What is pinned here is who that frame is allowed to speak for. A node's answer clears its slot
 * in the barrier, and a barrier whose slots can be cleared by somebody else reports "everybody
 * re-read" over a node that did not - which is exactly the fiction the barrier was built to
 * prevent. So the sender is the link, not the payload, the way every other frame on this mesh is
 * identified.
 *
 * What the payload does carry is the announcing node's round number, and it travels as an opaque
 * token: the follower keeps it beside the node to answer and hands it back untouched, so an answer
 * to a restore the leader has since superseded is refused there. Both mesh frames are strict about
 * it - an announcement nobody can answer, or an answer that names no question, is refused on
 * arrival, which is how this transport already treats a frame with no node on it.
 */
final class PeerDbReHydrateBarrierTest extends TestCase
{
    /** Round the leader announced under in these cases. */
    private const int ROUND = 5;

    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Sockets kept alive for the links under test */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        // Long thresholds so no link closes or pings on its own during the test.
        putenv('CLUSTER_LINK_KEEPALIVE_INTERVAL_MS=60000');
        putenv('CLUSTER_LINK_TIMEOUT_MS=60000');

        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$sr = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE',
            'CLUSTER_ENABLED',
            'CLUSTER_NODE_ID',
            'CLUSTER_NODE_ROLE',
            'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS',
            'CLUSTER_LINK_TIMEOUT_MS',
        ] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testANodesAnswerClearsItsOwnSlot(): void
    {
        $server = $this->makeServer();
        $barrier = new PeerDbReHydrateBarrierTestSink();
        $server->registerReHydrateBarrier($barrier);

        $server->onDbReHydratedReceived(
            $this->handshakedLink($server, 'node-b'),
            new PeerDbReHydratedDTO('node-b', self::ROUND, true, []),
        );

        $this->assertSame([[self::ROUND, 'node-b', true, null]], $barrier->acks);
    }

    public function testANodeCannotAnswerForAnotherNode(): void
    {
        // The slot it would clear belongs to a node that may still be reading its collections,
        // and the barrier closing over it is the whole failure this mechanism exists to stop.
        $server = $this->makeServer();
        $barrier = new PeerDbReHydrateBarrierTestSink();
        $server->registerReHydrateBarrier($barrier);

        $server->onDbReHydratedReceived(
            $this->handshakedLink($server, 'node-b'),
            new PeerDbReHydratedDTO('node-c', self::ROUND, true, []),
        );

        $this->assertSame([], $barrier->acks);
    }

    public function testANegativeAnswerCarriesTheNodesOwnProblemLines(): void
    {
        // Only that node knows its own roster, so its lines are quoted whole: the operator has to
        // learn which process on which node did not come back.
        $server = $this->makeServer();
        $barrier = new PeerDbReHydrateBarrierTestSink();
        $server->registerReHydrateBarrier($barrier);

        $server->onDbReHydratedReceived(
            $this->handshakedLink($server, 'node-b'),
            new PeerDbReHydratedDTO(
                'node-b',
                self::ROUND,
                false,
                ['worker #1: read failed: gone', 'worker #2: timeout'],
            ),
        );

        $this->assertSame(
            [[self::ROUND, 'node-b', false, 'worker #1: read failed: gone; worker #2: timeout']],
            $barrier->acks,
        );
    }

    public function testAnAnnouncementFromTheMeshIsQueuedForThisNodeToAnswer(): void
    {
        $server = $this->makeServer();

        $server->onDbReHydrateReceived(
            $this->handshakedLink($server, 'node-b'),
            new PeerDbReHydrateDTO(self::ROUND),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal, 'The announcement enters this node the way a local one does');
        $this->assertSame(SignalTypeConstants::DB_REHYDRATE, $signal->signalType->getType());
        $this->assertInstanceOf(DbReHydrateSignalData::class, $signal->data);
        $this->assertNull($signal->data->agentId, 'No agent here announced this swap');
        $this->assertSame(
            'node-b',
            $signal->data->replyToNodeId,
            'This node answers for itself to whoever told it, not to an agent of its own',
        );
        $this->assertSame(
            self::ROUND,
            $signal->data->replyToRound,
            'The announcing node is waiting under this number, and only it knows what the number means',
        );
    }

    public function testTheRoundNumberSurvivesTheRoundTripOnBothMeshFrames(): void
    {
        $announcement = PeerDbReHydrateDTO::fromArray(new PeerDbReHydrateDTO(self::ROUND)->toArray());
        $this->assertSame(self::ROUND, $announcement->round);

        $answer = PeerDbReHydratedDTO::fromArray(new PeerDbReHydratedDTO('node-b', self::ROUND, true, [])->toArray());
        $this->assertSame(self::ROUND, $answer->round);
    }

    public function testAnAnnouncementWithNoRoundOnItIsRefused(): void
    {
        // Strict where the worker link is tolerant: an announcement whose number was lost would
        // open a round on this node whose answer has nowhere to return to.
        $this->expectException(PeerTransportException::class);

        PeerDbReHydrateDTO::fromArray([]);
    }

    public function testAnAnswerWithNoRoundOnItIsRefused(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDbReHydratedDTO::fromArray([PeerDbReHydratedDTO::FIELD_NODE_ID => 'node-b']);
    }

    /**
     * @return PeerServer Peer server for the local node
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, $this->localIdentity(), []);
    }

    /**
     * Builds a link whose far end has completed the handshake under the given node id.
     *
     * @param PeerServer $server Server the link belongs to
     * @param string $nodeId Node id the remote end identifies itself as
     * @return PeerLink Handshaked link
     */
    private function handshakedLink(PeerServer $server, string $nodeId): PeerLink
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        $this->sockets = [...$this->sockets, $pair[0], $pair[1]];

        $link = new PeerLink($pair[0], $server, $this->localIdentity(), dialer: true);
        socket_write($pair[1], new PeerWelcomeDTO(PeerProtocol::VERSION, $nodeId, NodeRole::Master, [])->toJson() . "\n");
        $link->read();

        $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId, 'The welcome must complete the handshake');

        return $link;
    }

    /**
     * @return NodeIdentity Local node identity for the links under test
     */
    private function localIdentity(): NodeIdentity
    {
        return NodeIdentity::of('node-a', NodeRole::Master, []);
    }
}

/**
 * Barrier sink that records what it was credited with instead of holding a round.
 */
final class PeerDbReHydrateBarrierTestSink implements ReHydrateBarrierSink
{
    /** @var list<array{0: int, 1: string, 2: bool, 3: ?string}> Round, participant, outcome and text of each ack */
    public array $acks = [];

    /** @var list<string> Participants taken off the count */
    public array $drops = [];

    /**
     * @param int $round Round the answering participant named
     * @param string $participant Participant label
     * @param bool $ok Whether that participant re-read its collections successfully
     * @param ?string $error Failure text when it did not
     */
    public function ackReHydrateParticipant(int $round, string $participant, bool $ok, ?string $error): void
    {
        $this->acks[] = [$round, $participant, $ok, $error];
    }

    /**
     * @param string $participant Participant label
     */
    public function dropReHydrateParticipant(string $participant): void
    {
        $this->drops[] = $participant;
    }
}
