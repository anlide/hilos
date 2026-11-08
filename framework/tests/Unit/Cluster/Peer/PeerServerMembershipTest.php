<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\NullLeadershipObserver;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use ReflectionMethod;
use ReflectionProperty;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerDrainDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerDial;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Source\SourceChange;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Socket;

/**
 * Unit tests that a node's liveness comes from its own link and a neighbour's gossip carries
 * membership only (HIL-338, HIL-1059).
 *
 * Three events move liveness and each one reaches the membership observer: this node's own
 * handshake (a join), the close of its last link to a node and the leave frame the node sends
 * about itself (a leave). A roster or an announcement changes what a node is made of and never
 * whether it is alive, so the observer hears gossip in one case only: a node it holds online
 * announcing its own new make-up. The first case is HIL-1034 replayed exactly - a roster that
 * named a node in the window between its link dropping and its new handshake used to put it back
 * online silently, and the handshake was then taken for no change.
 *
 * Every link here is handshaked for real over a socket pair, because the identity a handshake
 * sets is what the gossip filters ask a link about; what a case asserts on the wire is read back
 * off the far end of a pair.
 */
final class PeerServerMembershipTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Every socket opened for the links under test, closed together */
    private array $sockets = [];

    private ?EventLoop $nativeLoop = null;

    /** @var list<PeerServer> Native owners released before closing the fixture sockets */
    private array $nativeServers = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_NAME=Local node');
        putenv('CLUSTER_NODE_ROLE=master');
        // Long thresholds so no link closes or pings on its own during the test.
        putenv('CLUSTER_LINK_KEEPALIVE_INTERVAL_MS=60000');
        putenv('CLUSTER_LINK_TIMEOUT_MS=60000');

        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        foreach ($this->nativeServers as $server) {
            $server->detachEventLoop();
        }
        $this->nativeLoop?->close();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE',
            'CLUSTER_ENABLED',
            'CLUSTER_NODE_ID',
            'CLUSTER_NODE_NAME',
            'CLUSTER_NODE_ROLE',
            'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS',
            'CLUSTER_LINK_TIMEOUT_MS',
        ] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAHandshakeIsAJoinAndTheLastLinkClosingIsALeave(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();

        [$linkB] = $this->linkTo($server, 'node-b');
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame([], $observer->left);

        $this->closeLink($server, $linkB);
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame(['node-b'], $observer->left);
    }

    /**
     * When the local node's dial wins the tie-break, a new accepted duplicate is refused
     * after its welcome and never becomes a second route or a departure.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAnAcceptedDuplicateThatLosesLeavesTheOldRouteAlone(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$old, $oldFar] = $this->linkTo($server, 'node-b');
        $this->drain($old, $oldFar);

        [$candidate, $candidateFar] = $this->candidateTo($server, 'node-b');
        $this->assertNull($candidate->remoteIdentity());
        $this->assertSame(['node-b'], $server->linkedNodeIds());
        $this->feed($candidate, $candidateFar, new PeerReadyDTO(true));

        $candidateFrames = $this->framesOf($this->drain($candidate, $candidateFar));
        $this->assertInstanceOf(PeerReadyAckDTO::class, $candidateFrames[1]);
        $this->assertFalse($candidateFrames[1]->keepNew);
        $this->assertSame(['node-b'], $server->linkedNodeIds());
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame([], $observer->left);

        $this->assertTrue($server->sendToNode('node-b', new PeerSourceInterestDTO('node-a', [], [])));
        $this->assertSame([], $this->framesOf($this->drain($candidate, $candidateFar)));
        $this->assertCount(1, $this->framesOf($this->drain($old, $oldFar)));
    }

    /**
     * When the remote node's dial wins, old queued frames precede the drain marker and
     * only the new route carries later frames. Closing the old route is not a leave.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAnAcceptedDuplicateThatWinsDrainsTheOldRoute(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        $server->announceSourceInterest(['readRt'], ['readDb']);
        [$old, $oldFar] = $this->linkTo($server, 'node-0');
        $this->drain($old, $oldFar);
        $server->onSourceInterestReceived($old, new PeerSourceInterestDTO('node-0', ['unitRows'], []));
        $this->drain($old, $oldFar);
        [$candidate, $candidateFar] = $this->candidateTo($server, 'node-0');

        $this->assertTrue($server->sendToNode('node-0', new PeerSourceInterestDTO('node-a', [], [])));
        $this->feed($candidate, $candidateFar, new PeerReadyDTO(true));
        $this->assertTrue($server->sendToNode('node-0', new PeerSourceInterestDTO('node-a', [], [])));

        $oldFrames = $this->framesOf($this->drain($old, $oldFar));
        $newFrames = $this->framesOf($this->drain($candidate, $candidateFar));
        $this->assertInstanceOf(PeerSourceInterestDTO::class, $oldFrames[0]);
        $this->assertInstanceOf(PeerDrainDTO::class, $oldFrames[1]);
        $this->assertInstanceOf(PeerReadyAckDTO::class, $newFrames[1]);
        $this->assertTrue($newFrames[1]->keepNew);
        $this->assertEquals(new PeerSourceInterestDTO('node-a', ['readRt'], ['readDb']), $newFrames[2]);
        $this->assertEquals(new PeerSourceInterestDTO('node-a', [], []), $newFrames[3]);
        $this->assertCount(4, $newFrames, 'An online duplicate sends one targeted reader repair without a full hand-over');
        $this->assertSame([], array_filter($newFrames, static fn(PeerDTO $frame): bool => $frame instanceof PeerRosterDTO));

        $this->feed($old, $oldFar, new PeerDrainDTO());
        $this->closeLink($server, $old);
        $this->assertSame(['node-0'], $server->linkedNodeIds());
        $this->assertSame(['node-0'], $observer->joined);
        $this->assertSame([], $observer->left);
        $online = array_values(array_filter(
            Hilos::$cluster->registry()->snapshot(),
            static fn(ClusterNode $node): bool => $node->nodeId === 'node-0',
        ));
        $this->assertTrue($online[0]->online);
        $server->broadcastToNodesHolding(
            new PeerSourceInterestDTO('node-a', [], []),
            SourceChange::KIND_RT,
            'unitRows',
        );
        $this->assertCount(1, $this->framesOf($this->drain($candidate, $candidateFar)));

        $this->closeLink($server, $candidate);
        $this->assertSame(['node-0'], $observer->left);
    }

    /**
     * A restarted peer has no old route, so ready(false) can replace the old generation's
     * still-open socket after its leave. The new handshake must restore membership.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testANewGenerationReplacingALeavingLinkRestoresMembership(): void
    {
        $this->assertLeavingLinkReplacementRestoresMembership(false);
    }

    /**
     * The dialing side must restore the same first-entry transfers when its ready ack wins
     * before the leaving generation's queued EOF is read.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testADialedReplacementOfALeavingLinkRestoresMembership(): void
    {
        $this->assertLeavingLinkReplacementRestoresMembership(true);
    }

    /**
     * A candidate refused by the duplicate tie-break cannot undo a real graceful leave.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testALosingDuplicateDoesNotReviveALeavingNode(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        $server->announceSourceInterest(['readRt'], ['readDb']);
        [$old, $oldFar] = $this->linkTo($server, 'node-b');
        $this->drain($old, $oldFar);
        $this->feed($old, $oldFar, new PeerNodeLeavingDTO('node-b', false, null));

        [$candidate, $candidateFar] = $this->candidateTo($server, 'node-b');
        $this->feed($candidate, $candidateFar, new PeerReadyDTO(true));
        $candidateFrames = $this->framesOf($this->drain($candidate, $candidateFar));
        $this->assertCount(2, $candidateFrames);
        $this->assertInstanceOf(PeerWelcomeDTO::class, $candidateFrames[0]);
        $this->assertInstanceOf(PeerReadyAckDTO::class, $candidateFrames[1]);
        $this->assertFalse($candidateFrames[1]->keepNew);
        $this->assertNull($candidate->remoteIdentity());
        $this->closeLink($server, $candidate);

        $this->assertFalse(Hilos::$cluster->registry()->isOnline('node-b'));
        $this->assertSame(['node-b'], $server->linkedNodeIds());
        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame(['node-b'], $observer->left);
        $this->assertTrue($server->sendToNode('node-b', new PeerSourceInterestDTO('node-a', [], [])));
        $this->assertCount(1, $this->framesOf($this->drain($old, $oldFar)));
    }

    /**
     * The acceptor can put its drain on the old socket before the dialer reads the ack.
     * The dialer keeps reading the old socket, then writes its own marker after switching.
     *
     * @throws EnvException When a link cannot read its settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair refuses a frame
     */
    public function testDialerReadsAnEarlyOldDrainBeforeItsReadyAck(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$old, $oldFar] = $this->linkTo($server, 'node-b');
        $this->drain($old, $oldFar);
        [$candidate, $candidateFar] = $this->dialedCandidateTo($server, 'node-b');

        $this->feed($old, $oldFar, new PeerDrainDTO());
        $this->assertFalse($old->shouldClose());
        $this->feed($candidate, $candidateFar, new PeerReadyAckDTO(true));
        $this->assertSame(['node-b'], $server->linkedNodeIds());
        $this->assertSame([PeerDrainDTO::MESSAGE_TYPE], array_map(
            static fn(PeerDTO $frame): string => $frame->getType(),
            $this->framesOf($this->drain($old, $oldFar)),
        ));
        $this->assertTrue($old->shouldClose());
        $this->closeLink($server, $old);
        $this->assertSame([], $observer->left);
        $this->closeLink($server, $candidate);
        $this->assertSame(['node-b'], $observer->left);
    }

    /**
     * A drain before the candidate reaches ready is a protocol error on that candidate;
     * the old working link is still the node's route and cannot be called a departure.
     *
     * @throws EnvException When a link cannot read its settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair refuses a frame
     */
    public function testOutOfOrderDrainRejectsTheCandidateWithoutDroppingTheOldRoute(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$old, $oldFar] = $this->linkTo($server, 'node-b');
        $this->drain($old, $oldFar);
        [$candidate, $candidateFar] = $this->candidateTo($server, 'node-b');

        $this->feed($old, $oldFar, new PeerDrainDTO());
        $this->drain($candidate, $candidateFar);

        $this->assertFalse($old->shouldClose());
        $this->assertTrue($candidate->shouldClose());
        $this->assertSame(['node-b'], $server->linkedNodeIds());
        $this->assertSame([], $observer->left);
    }

    /**
     * A seed's DNS name need not resemble its advertised address. Only the seed that
     * reached an already linked certified node stops dialing; an unknown seed still tries.
     *
     * @throws EnvException When a link cannot read its settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair refuses a frame
     */
    public function testASeedWithAnotherWorkingRouteStopsDialingWithoutSuppressingOtherSeeds(): void
    {
        $server = $this->makeServer();
        $this->linkTo($server, 'node-0');
        $known = new PeerDial(new PeerAddress('localhost', 1));
        $unknown = new PeerDial(new PeerAddress('127.0.0.1', 1));
        $install = Closure::bind(
            static function (PeerServer $server, PeerDial $known, PeerDial $unknown): void {
                $server->seedDials = [$known, $unknown];
            },
            null,
            PeerServer::class,
        );
        $install($server, $known, $unknown);

        [$candidate, $far] = $this->dialedCandidateTo($server, 'node-0', $known);
        $this->feed($candidate, $far, new PeerReadyAckDTO(false));
        $this->drain($candidate, $far);

        $drive = Closure::bind(
            static function (PeerServer $server, PeerDial $dial, float $now): void {
                $server->driveDial($dial, $now);
            },
            null,
            PeerServer::class,
        );
        $now = microtime(true);
        $drive($server, $known, $now);
        $known->nextAttemptAt = 0.0;
        $drive($server, $known, $now + 10.0);
        $drive($server, $unknown, $now);

        $this->assertSame('node-0', $known->remoteNodeId);
        $this->assertFalse($known->connecting);
        $this->assertSame(0.0, $known->nextAttemptAt);
        $this->assertTrue($unknown->connecting || $unknown->nextAttemptAt > $now);
        if ($unknown->socket !== null) {
            socket_close($unknown->socket);
        }
    }

    /**
     * HIL-1034 replayed: the link to B drops, a roster from C names B in the window before B's new
     * handshake, and B's return must still be the join the placement hears.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testARosterInTheWindowBeforeAHandshakeLeavesTheReturnToTheHandshake(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];

        $this->closeLink($server, $linkB);
        $this->assertSame(['node-b'], $observer->left);

        // C still links to B and says so in the roster its next handshake hands over.
        $this->feed($linkC, $farC, new PeerRosterDTO([
            $this->entry('node-a'),
            $this->entry('node-b'),
            $this->entry('node-c'),
        ]));

        $this->assertSame([], $observer->joined, 'A neighbour\'s word is not a return');
        $this->assertSame(['node-b'], $observer->left);
        $this->assertNotContains('node-b', $server->onlineNodeIds(), 'B stays offline until this node sees it');

        $this->linkTo($server, 'node-b');

        $this->assertSame(['node-b'], $observer->joined, 'The handshake is the return, and it is reported');
        $this->assertContains('node-b', $server->onlineNodeIds());
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testAnAnnouncedUnknownNodeIsKnownOfflineAndGoesNoFurther(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerAnnounceDTO($this->entry('node-d')));

        $this->assertTrue($server->nodeHasLeftTheMesh('node-d'), 'Known, not yet seen');
        $this->assertNotContains('node-d', $server->onlineNodeIds());
        $this->assertSame([], $observer->joined);
        $this->assertSame('', $this->drain($linkC, $farC), 'An announcement is never relayed onward');
    }

    /**
     * How a newcomer that came in through one seed becomes known to everyone else.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws PeerTransportException When a frame read back is not one this protocol knows
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testARosterSpreadsAnUnknownNodesMembershipButNotItsLiveness(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerRosterDTO([$this->entry('node-b'), $this->entry('node-d')]));

        $this->assertTrue($server->nodeHasLeftTheMesh('node-d'), 'Known, not yet seen');
        $this->assertNotContains('node-d', $server->onlineNodeIds());
        $this->assertSame([], $observer->joined);
        $frames = $this->framesOf($this->drain($linkC, $farC));
        $this->assertCount(1, $frames, 'C hears of D, and of nothing that did not change');
        $this->assertInstanceOf(PeerAnnounceDTO::class, $frames[0]);
        $this->assertSame('node-d', $frames[0]->node->nodeId);
    }

    public function testANodeAnnouncingItsOwnNewNameUpdatesMembership(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$link, $far] = $this->linkTo($server, 'node-b');
        $observer->joined = [];
        $renamed = new PeerNodeEntry('node-b', 'Renamed peer', NodeRole::Master, [], null);
        $version = Hilos::$cluster->registry()->version();

        $this->feed($link, $far, new PeerAnnounceDTO($renamed));

        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame($version + 1, Hilos::$cluster->registry()->version());
        $nodes = array_column(Hilos::$cluster->registry()->snapshot(), null, 'nodeId');
        $this->assertSame('Renamed peer', $nodes['node-b']->name);
        $this->assertTrue($nodes['node-b']->online);
        $observer->joined = [];
        $this->feed($link, $far, new PeerAnnounceDTO($renamed));
        $this->assertSame([], $observer->joined);
        $this->assertSame($version + 1, Hilos::$cluster->registry()->version());
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testANodeAnnouncingItsOwnNewCapabilityIsAJoin(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        $observer->joined = [];

        $this->feed($linkB, $farB, new PeerAnnounceDTO($this->entry('node-b', ['gpu-local'])));

        $this->assertSame(['node-b'], $observer->joined, 'The leader retries what it could not place');
        $this->assertSame(['gpu-local'], $server->nodeCapabilities('node-b'));
    }

    /**
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testANeighboursWordAboutALinkedNodeIsDropped(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        $observer->joined = [];

        $this->feed($linkC, $farC, new PeerAnnounceDTO($this->entry('node-b', ['gpu-local'])));

        $this->assertSame([], $observer->joined);
        $this->assertSame([], $server->nodeCapabilities('node-b'), 'B told its make-up itself, on its handshake');
    }

    /**
     * Each neighbour hears a leave from the node that leaves, or sees its own link drop - nobody
     * passes a leave on.
     *
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    public function testNeitherALeaveFrameNorALinkDroppingIsPassedOn(): void
    {
        $observer = $this->registerObserver();
        $server = $this->makeServer();
        [$linkB, $farB] = $this->linkTo($server, 'node-b');
        [$linkC, $farC] = $this->linkTo($server, 'node-c');
        [$linkD] = $this->linkTo($server, 'node-d');
        $this->drain($linkC, $farC);

        $this->feed($linkB, $farB, new PeerNodeLeavingDTO('node-b', false, null));
        $this->closeLink($server, $linkB);
        $this->closeLink($server, $linkD);

        $this->assertSame(['node-b', 'node-d'], $observer->left);
        $this->assertSame('', $this->drain($linkC, $farC));
    }

    public function testNativeMembershipLossReturnKeepsTheSameLeaderAndTermButPublishesBothEdges(): void
    {
        $server = $this->makeServer();
        [$old, $far] = $this->linkTo($server, 'node-b');
        $coordinator = $this->installNativeConsensus($server);
        $server->onHeartbeat(new PeerHeartbeatDTO(5, 'node-b', true));
        $coordinator->dispatchReady();
        $revision = $coordinator->epochRevision();
        $deadline = $this->roleTimer($coordinator)->getDeadline();

        $this->feed($old, $far, new PeerNodeLeavingDTO('node-b', false, null));
        $this->assertFalse($coordinator->hasQuorum(), 'the actual leave observes loss before the next owner pass');
        $this->linkTo($server, 'node-b');
        $this->assertTrue($coordinator->hasQuorum(), 'the actual replacement handshake observes return');
        $this->closeLink($server, $old);
        $this->assertSame($revision + 2, $coordinator->epochRevision());
        $this->assertSame('node-b', $coordinator->leaderId());
        $this->assertSame(5, $coordinator->term());
        $this->nativeLoop->tick();
        $coordinator->dispatchReady();
        $this->assertSame($deadline, $this->roleTimer($coordinator)->getDeadline());
        $this->assertFalse($coordinator->hasReadyWork());
    }

    public function testNativeConsensusUsesOwnRoleAnnouncementsAndIgnoresUnrelatedMembership(): void
    {
        $server = $this->makeServer();
        [$link, $far] = $this->linkTo($server, 'node-b');
        $coordinator = $this->installNativeConsensus($server);
        $coordinator->dispatchReady();
        $deadline = $this->roleTimer($coordinator)->getDeadline();
        $revision = $coordinator->epochRevision();
        $this->feed($link, $far, new PeerAnnounceDTO($this->entry('node-c')));
        $this->feed($link, $far, new PeerAnnounceDTO(new PeerNodeEntry('node-b', 'Renamed', NodeRole::Master, ['gpu'], null)));
        $this->assertSame(2, $coordinator->onlineMasterCount(), 'third-party gossip does not make a node online');
        $this->assertSame($revision, $coordinator->epochRevision());
        $this->assertSame($deadline, $this->roleTimer($coordinator)->getDeadline());
        $this->assertFalse($coordinator->hasReadyWork(), 'name and capacity edits are not election resets');
        $this->feed($link, $far, new PeerAnnounceDTO(new PeerNodeEntry('node-b', 'Renamed', NodeRole::Slave, ['gpu'], null)));
        $this->assertFalse($coordinator->hasQuorum());
        $this->feed($link, $far, new PeerAnnounceDTO($this->entry('node-b')));
        $this->assertTrue($coordinator->hasQuorum());
        $this->assertSame($revision + 2, $coordinator->epochRevision());
        $coordinator->dispatchReady();
        $this->assertSame($deadline, $this->roleTimer($coordinator)->getDeadline());

        Hilos::$cluster->registry()->merge(NodeIdentity::of('node-a', 'Local', NodeRole::Slave, []), true, microtime(true));
        $server->announceLocalNode();
        $this->assertFalse($coordinator->hasQuorum(), 'local reload is a membership source too');
    }

    public function testNativeQuorumObserversPrecedeMembershipHooksWithoutADaemonParentCall(): void
    {
        $server = $this->makeServer();
        [$link, $far] = $this->linkTo($server, 'node-b');
        $events = [];
        $observer = $this->createMock(LeadershipObserver::class);
        $observer->method('onQuorumLost')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'quorumLost';
        });
        $observer->method('onQuorumGained')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'quorumGained';
        });
        $coordinator = $this->installNativeConsensus($server, $observer);
        $coordinator->dispatchReady();
        $events = [];
        $membership = $this->createMock(MembershipObserver::class);
        $membership->method('onNodeJoined')->willReturnCallback(static function (ClusterNode $node) use ($coordinator, &$events): void {
            $events[] = $coordinator->hasQuorum() ? 'membershipWithQuorum' : 'membershipWithoutQuorum';
        });
        Hilos::$cluster->registerMembershipObserver($membership);
        $this->feed($link, $far, new PeerAnnounceDTO(new PeerNodeEntry('node-b', 'Remote', NodeRole::Slave, [], null)));
        $this->feed($link, $far, new PeerAnnounceDTO($this->entry('node-b')));
        $this->assertSame(['quorumLost', 'membershipWithoutQuorum', 'quorumGained', 'membershipWithQuorum'], $events);
    }

    public function testMissingRegistryRetiresNativeLeadershipWithoutWaitingForItsHeartbeat(): void
    {
        $server = $this->makeServer();
        $this->linkTo($server, 'node-b');
        $coordinator = $this->installNativeConsensus($server);
        $coordinator->dispatchReady();
        $coordinator->triggerDesignatedElection();
        $coordinator->dispatchReady();
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'node-b'));
        $coordinator->dispatchReady();
        $this->assertTrue($coordinator->amLeader());
        Hilos::$cluster = null;
        $server->onTick();
        $this->assertFalse($coordinator->hasQuorum());
        $this->assertFalse($coordinator->amLeader());
        $this->assertSame(0, $coordinator->onlineMasterCount());
        $this->assertFalse($coordinator->hasReadyWork());
    }

    public function testNativeHeartbeatThenLastActiveCloseUsesTheFastElectionPath(): void
    {
        $server = $this->makeServer();
        [$leader, $far] = $this->linkTo($server, 'node-b');
        $this->linkTo($server, 'node-c');
        $coordinator = $this->installNativeConsensus($server);
        $coordinator->dispatchReady();
        $this->feed($leader, $far, new PeerHeartbeatDTO(1, 'node-b', false));
        $this->assertTrue(socket_shutdown($far, 1));
        $leader->read();
        $this->assertTrue($leader->shouldClose());
        $this->closeLink($server, $leader);
        $server->onTick();
        $this->assertSame(2, $coordinator->term());
        $this->assertNull($coordinator->leaderId());
        $this->assertFalse($server->hasConsensusReadyWork(), 'pending votes alone are not runnable');
    }

    public function testSameBaseAttachBindsLateAndReplacedPrivateOwnersIndependentlyOfTheFacade(): void
    {
        $server = $this->makeServer();
        $this->nativeLoop = new EventLoop();
        $server->attachEventLoop($this->nativeLoop);
        $old = $this->installNativeConsensus($server);
        $old->dispatchReady();
        $timer = $this->roleTimer($old);
        $cue = new ReflectionProperty($timer, 'callback')->getValue($timer);
        $replacement = new ClusterCoordinator(
            new ClusterConsensusConfig('node-a', ['node-a', 'node-b', 'node-c'], 2, 1500, 3000, 500),
            $server, new NullLeadershipObserver(),
        );
        Hilos::$cluster->registerLeadership($replacement);
        $server->attachEventLoop($this->nativeLoop);
        $this->assertSame($timer, $this->roleTimer($old), 'facade replacement does not replace the actual peer owner');
        new ReflectionProperty($server, 'coordinator')->setValue($server, $replacement);
        $server->attachEventLoop($this->nativeLoop);
        $this->assertNull($timer->getDeadline());
        $this->assertFalse($old->hasReadyWork());
        $replacement->dispatchReady();
        $cue();
        $this->assertFalse($replacement->hasReadyWork());
        $server->detachEventLoop();
        $this->assertFalse($replacement->hasReadyWork());
        $this->assertNull(new ReflectionProperty($replacement, 'roleTimer')->getValue($replacement));
    }

    public function testStartupAfterLoopAttachBindsItsActualCoordinator(): void
    {
        $server = $this->makeServer();
        $this->nativeLoop = new EventLoop();
        $this->nativeServers[] = $server;
        $server->attachEventLoop($this->nativeLoop);
        $previousSet = getenv('CLUSTER_MASTER_SET');
        putenv('CLUSTER_MASTER_SET=node-a,node-b,node-c');
        try {
            new ReflectionMethod($server, 'onStart')->invoke($server);
            $this->assertTrue($server->hasConsensusReadyWork());
            $coordinator = new ReflectionProperty($server, 'coordinator')->getValue($server);
            $this->assertSame($coordinator, Hilos::$cluster->leadership());
            $coordinator->dispatchReady();
            $this->assertGreaterThan(hrtime(true) / 1e9, $this->roleTimer($coordinator)->getDeadline());
        } finally {
            putenv($previousSet === false ? 'CLUSTER_MASTER_SET' : 'CLUSTER_MASTER_SET=' . $previousSet);
        }
    }

    public function testPrepareShutdownRetainsConsensusUntilQueuedOutputDrainsAndStopRetiresIt(): void
    {
        $server = $this->makeServer();
        [$link, $far] = $this->linkTo($server, 'node-b');
        $coordinator = $this->installNativeConsensus($server);
        $coordinator->dispatchReady();
        $coordinator->triggerDesignatedElection();
        $coordinator->dispatchReady();
        $server->onVoteReply(new PeerVoteReplyDTO(1, true, 'node-b'));
        $coordinator->dispatchReady();
        $this->drain($link, $far);
        $timer = $this->roleTimer($coordinator);
        $server->prepareShutdown();
        $this->assertFalse($server->isReadyToShutdown(), 'graceful leave is still queued');
        $deadline = hrtime(true) / 1e9 - 1.0;
        new ReflectionProperty($coordinator, 'heartbeatDueAt')->setValue($coordinator, $deadline);
        $timer->armAt($deadline);
        $server->attachEventLoop($this->nativeLoop);
        $this->nativeLoop->runOnce();
        $this->assertTrue($server->hasConsensusReadyWork(), 'preparation must retain the live consensus duty');
        $server->onTick();
        $frames = $this->framesOf($this->drain($link, $far));
        $this->assertCount(1, array_filter($frames, static fn(PeerDTO $frame): bool => $frame instanceof PeerNodeLeavingDTO));
        $this->assertCount(1, array_filter($frames, static fn(PeerDTO $frame): bool => $frame instanceof PeerHeartbeatDTO));
        $this->assertTrue($server->isReadyToShutdown());
        $this->closeLink($server, $link);
        $server->stop();
        $this->assertNull($timer->getDeadline());
        $this->assertFalse($coordinator->hasReadyWork());
        $this->assertNull(new ReflectionProperty($server, 'dialLoop')->getValue($server));
        $this->assertFalse($this->nativeLoop->hasReadyWork());
    }

    /**
     * @param PeerServer $server Actual registry-backed consensus mesh
     * @param ?LeadershipObserver $observer Transition-order observer, null for no project effects
     * @return ClusterCoordinator Concrete owner on the same existing base
     */
    private function installNativeConsensus(PeerServer $server, ?LeadershipObserver $observer = null): ClusterCoordinator
    {
        $this->nativeLoop ??= new EventLoop();
        $this->nativeServers[] = $server;
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('node-a', ['node-a', 'node-b', 'node-c'], 2, 1500, 3000, 500),
            $server, $observer ?? new NullLeadershipObserver(),
        );
        new ReflectionProperty($server, 'coordinator')->setValue($server, $coordinator);
        Hilos::$cluster->registerLeadership($coordinator);
        $server->attachEventLoop($this->nativeLoop);
        return $coordinator;
    }

    /**
     * @param ClusterCoordinator $coordinator Exact peer owner
     * @return EventLoopTimer Bound role timer
     */
    private function roleTimer(ClusterCoordinator $coordinator): EventLoopTimer
    {
        return new ReflectionProperty($coordinator, 'roleTimer')->getValue($coordinator);
    }

    /**
     * Drives either handshake road while the old generation's EOF waits on its socket.
     *
     * @param bool $dialer Whether this node dials the replacement
     * @throws EnvException When a link cannot read its socket and keepalive settings
     * @throws HilosException When a frame refuses to become wire input
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When a pair under test refuses a frame
     */
    private function assertLeavingLinkReplacementRestoresMembership(bool $dialer): void
    {
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-peer-rejoin-');
        Logger::setLogFile($logFile);

        try {
            $observer = $this->registerObserver();
            $server = $this->makeServer();
            $server->announceSourceInterest(['readRt'], ['readDb']);
            [$old, $oldFar] = $this->linkTo($server, 'node-0');
            $this->drain($old, $oldFar);

            $this->feed($old, $oldFar, new PeerNodeLeavingDTO('node-0', false, null));
            $this->assertFalse(Hilos::$cluster->registry()->isOnline('node-0'));
            $this->assertSame(['node-0'], $observer->left);
            $this->assertSame(['node-0'], $server->linkedNodeIds());
            $this->assertFalse($old->shouldClose());

            // The old generation's EOF is queued, but the new socket is handled first.
            $this->assertTrue(socket_shutdown($oldFar, 1));
            if ($dialer) {
                [$candidate, $candidateFar] = $this->dialedCandidateTo($server, 'node-0');
                $readyFrames = $this->framesOf($this->drain($candidate, $candidateFar));
                $this->assertCount(1, $readyFrames);
                $this->assertInstanceOf(PeerReadyDTO::class, $readyFrames[0]);
                $this->assertTrue($readyFrames[0]->hasActiveLink);
                $this->feed($candidate, $candidateFar, new PeerReadyAckDTO(true));
            } else {
                [$candidate, $candidateFar] = $this->candidateTo($server, 'node-0');
                $welcomeFrames = $this->framesOf($this->drain($candidate, $candidateFar));
                $this->assertCount(1, $welcomeFrames);
                $this->assertInstanceOf(PeerWelcomeDTO::class, $welcomeFrames[0]);
                $this->feed($candidate, $candidateFar, new PeerReadyDTO(false));
            }

            $newFrames = $this->framesOf($this->drain($candidate, $candidateFar));
            if (!$dialer) {
                $this->assertInstanceOf(PeerReadyAckDTO::class, $newFrames[0]);
                $this->assertTrue($newFrames[0]->keepNew);
                array_shift($newFrames);
            }
            $this->assertSame([PeerRosterDTO::MESSAGE_TYPE, PeerSourceInterestDTO::MESSAGE_TYPE], array_map(
                static fn(PeerDTO $frame): string => $frame->getType(),
                $newFrames,
            ));
            $this->assertInstanceOf(PeerSourceInterestDTO::class, $newFrames[1]);
            $this->assertSame('node-a', $newFrames[1]->nodeId);
            $this->assertSame(['readRt'], $newFrames[1]->rtCollections);
            $this->assertSame(['readDb'], $newFrames[1]->dbCollections);
            $this->assertSame('node-0', $candidate->remoteIdentity()?->nodeId);
            $this->assertTrue(Hilos::$cluster->registry()->isOnline('node-0'));
            $this->assertSame(['node-0', 'node-0'], $observer->joined);
            $this->assertSame(['node-0'], $observer->left);
            $this->assertSame([PeerDrainDTO::MESSAGE_TYPE], array_map(
                static fn(PeerDTO $frame): string => $frame->getType(),
                $this->framesOf($this->drain($old, $oldFar)),
            ));

            $old->read();
            $this->assertTrue($old->shouldClose());
            $this->closeLink($server, $old);
            $this->assertTrue(Hilos::$cluster->registry()->isOnline('node-0'));
            $this->assertSame(['node-0'], $server->linkedNodeIds());
            $this->assertTrue($server->sendToNode('node-0', new PeerSourceInterestDTO('node-a', [], [])));
            $routedFrames = $this->framesOf($this->drain($candidate, $candidateFar));
            $this->assertCount(1, $routedFrames);
            $this->assertInstanceOf(PeerSourceInterestDTO::class, $routedFrames[0]);
            $this->assertSame(['node-0', 'node-0'], $observer->joined);
            $this->assertSame(['node-0'], $observer->left);
            $log = (string)file_get_contents($logFile);
            $this->assertSame(2, substr_count($log, 'Peer joined: node-0 role=master'));
            $this->assertStringNotContainsString('Peer left:', $log);
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
        }
    }

    /**
     * Registers the observer the cases read membership transitions from.
     *
     * @return RecordingMembershipObserver Observer recording every join and leave
     */
    private function registerObserver(): RecordingMembershipObserver
    {
        $observer = new RecordingMembershipObserver();
        Hilos::$cluster->registerMembershipObserver($observer);

        return $observer;
    }

    /**
     * Builds the server under test, with no seeds to dial and no port to listen on.
     *
     * @return PeerServer Server the cases drive
     */
    private function makeServer(): PeerServer
    {
        return new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []),
            [],
            PeerTestTls::unread(),
            PeerTestMarkers::shared(),
        );
    }

    /**
     * Raises one handshaked link to a named master and registers it on the server.
     *
     * @param PeerServer $server Server the link belongs to
     * @param string $nodeId Node id the far end introduces itself as
     * @return array{PeerLink, Socket} The link and the far end of its pair
     * @throws EnvException When the link cannot read its socket and keepalive settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When the pair refuses the hello
     */
    private function linkTo(PeerServer $server, string $nodeId): array
    {
        [$link, $far] = $this->candidateTo($server, $nodeId);
        $this->feed($link, $far, new PeerReadyDTO(false));
        $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId, 'Ready must complete the handshake');

        return [$link, $far];
    }

    /**
     * Opens an accepted candidate through hello, before the dialer's ready arrives.
     *
     * @param PeerServer $server Server the link belongs to
     * @param string $nodeId Certified remote node id
     * @return array{PeerLink, Socket} Candidate and far socket
     * @throws EnvException When the link cannot read its settings
     * @throws HilosException When the hello refuses to become a frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When the pair refuses the hello
     */
    private function candidateTo(PeerServer $server, string $nodeId): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->sockets[] = $near;
        $this->sockets[] = $far;

        $link = new PeerLink(
            $near,
            $server,
            NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []),
            dialer: false,
            transport: new NamedPeerTestTransport($near, $nodeId),
        );
        $this->attach($server, $link);
        $this->feed($link, $far, new PeerHelloDTO(
            PeerProtocol::VERSION,
            $nodeId,
            'Node ' . $nodeId,
            NodeRole::Master,
            [],
            PeerTestMarkers::onWire(),
            null,
        ));

        return [$link, $far];
    }

    /**
     * Opens a dialing candidate through its certified welcome, before the ready ack.
     *
     * @param PeerServer $server Owning server
     * @param string $nodeId Certified remote node id
     * @param ?PeerDial $dial Configured seed dial, when this link belongs to one
     * @return array{PeerLink, Socket} Candidate and far socket
     * @throws EnvException When a link cannot read its settings
     * @throws HilosException When the welcome refuses to become a frame
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws SocketException When the pair refuses the welcome
     */
    private function dialedCandidateTo(PeerServer $server, string $nodeId, ?PeerDial $dial = null): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->sockets[] = $near;
        $this->sockets[] = $far;
        $link = new PeerLink(
            $near,
            $server,
            NodeIdentity::of('node-a', 'Node node-a', NodeRole::Master, []),
            dialer: true,
            transport: new NamedPeerTestTransport($near, $nodeId),
        );
        $this->attach($server, $link);
        if ($dial !== null) {
            $dial->link = $link;
        }
        $this->feed($link, $far, new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            $nodeId,
            'Node ' . $nodeId,
            NodeRole::Master,
            [],
            PeerTestMarkers::onWire(),
            PeerAddress::fromString('10.0.0.2:8095'),
        ));

        return [$link, $far];
    }

    /**
     * Puts one link on the server's list, as the accept loop would have.
     *
     * Through a bound closure rather than a subclass or Reflection: {@see PeerServer} is final, and
     * a bound closure is how the tests next door reach the same list.
     *
     * @param PeerServer $server Server the link belongs to
     * @param PeerLink $link Link to register as if it had been accepted
     */
    private function attach(PeerServer $server, PeerLink $link): void
    {
        $attach = Closure::bind(
            static function (PeerServer $server, PeerLink $link): void {
                $server->addClient($link);
                $server->attachClient($link);
            },
            null,
            PeerServer::class,
        );

        $attach($server, $link);
    }

    /**
     * Takes one link down the way the server does: the close is announced, then the link is gone.
     *
     * The socket itself is left open for the teardown to close, which is all the real close adds.
     *
     * @param PeerServer $server Server the link belongs to
     * @param PeerLink $link Handshaked link that closes
     */
    private function closeLink(PeerServer $server, PeerLink $link): void
    {
        $server->onLinkClosed($link);
        $server->removeClient($link);
    }

    /**
     * Delivers one frame to a link the way the node on the other end would.
     *
     * @param PeerLink $link Link the frame arrives on
     * @param Socket $far Far end, standing in for the sending node
     * @param PeerDTO $frame Frame that node sends
     * @throws HilosException When the frame refuses to become wire input
     * @throws SocketException When the pair refuses the frame
     */
    private function feed(PeerLink $link, Socket $far, PeerDTO $frame): void
    {
        socket_write($far, $frame->toJson() . "\n");
        $link->read();
    }

    /**
     * Flushes one link and hands back the bytes that reached the far end.
     *
     * @param PeerLink $link Link to flush
     * @param Socket $far Far end of its pair
     * @return string Bytes written since the last read, empty when none
     * @throws HilosException When a queued frame refuses to become wire input
     * @throws SocketException When the pair refuses the queued frames
     */
    private function drain(PeerLink $link, Socket $far): string
    {
        $link->write();
        $read = socket_read($far, 65536);

        return $read === false ? '' : $read;
    }

    /**
     * Parses back the frames inside a run of bytes, in arrival order.
     *
     * @param string $bytes Newline-delimited frames read off a far end
     * @return list<PeerDTO> Frames as the receiving node would parse them, oldest first
     * @throws PeerTransportException When a frame is not one this protocol knows
     */
    private function framesOf(string $bytes): array
    {
        $frames = [];
        foreach (explode("\n", $bytes) as $line) {
            if ($line !== '') {
                $frames[] = PeerDTO::fromWire($line);
            }
        }

        return $frames;
    }

    /**
     * @param string $nodeId Node id the entry describes
     * @param list<string> $capabilities Capability tags the entry carries
     * @return PeerNodeEntry Gossip entry for a master node with no address
     */
    private function entry(string $nodeId, array $capabilities = []): PeerNodeEntry
    {
        return PeerNodeEntry::fromIdentity(NodeIdentity::of($nodeId, 'Node ' . $nodeId, NodeRole::Master, $capabilities));
    }
}
