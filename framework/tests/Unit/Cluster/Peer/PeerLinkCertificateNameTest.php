<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerDial;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/**
 * The name in a peer's certificate binds the hello/welcome handshake (HIL-1034).
 *
 * The transport vouches for a name; the link accepts a handshake only when the node id it
 * introduces is exactly that name, and refuses it before the peer is remembered or the server
 * hears of it. A transport that vouches for nobody refuses every handshake. The dialing side
 * also tells a link the peer closed after TLS but before any welcome - not one it dropped itself -
 * and the server names a series of those once.
 */
final class PeerLinkCertificateNameTest extends TestCase
{
    /** Node id of this side of every link */
    private const string LOCAL_NODE = 'node-a';

    /** Node id the far end introduces itself with */
    private const string REMOTE_NODE = 'node-b';

    /** Line the server writes when a dialed target closes before its welcome */
    private const string UNWELCOMED_LINE = 'Peer 10.0.0.2:8095 closed before welcome; check the peer log';

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    /** @var list<Socket> Sockets closed after each test */
    private array $sockets = [];

    private string $logFile;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=' . self::LOCAL_NODE);
        putenv('CLUSTER_NODE_NAME=Node ' . self::LOCAL_NODE);
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$cluster = new ClusterContext();

        $file = tempnam(sys_get_temp_dir(), 'hilos-peer-certificate-name');
        $this->assertIsString($file);
        $this->logFile = $file;
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        foreach ($this->sockets as $socket) {
            @socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }
    }

    public function testOpeningHelloCarriesTheLocalName(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: true, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE));

        $link->startHandshake();
        $link->write();
        $wire = socket_read($far, 65536, PHP_BINARY_READ);
        $this->assertIsString($wire);
        $hello = PeerDTO::fromWire(trim($wire));
        $this->assertInstanceOf(PeerHelloDTO::class, $hello);
        $this->assertSame(self::LOCAL_NODE, $hello->nodeId);
        $this->assertSame('Node ' . self::LOCAL_NODE, $hello->name);
    }

    /** @return iterable<string, array{bool, bool}> Hello/welcome on new and already pending links */
    public static function renameHandshakes(): iterable
    {
        yield 'new hello' => [true, false];
        yield 'pending hello' => [true, true];
        yield 'new welcome' => [false, false];
        yield 'pending welcome' => [false, true];
    }

    /**
     * No roster follows the renamed node's handshake, so only that frame can preserve its name.
     *
     * @param bool $dialer Whether this node sends hello rather than welcome
     * @param bool $pending Whether the link exists before reload accepts the new name
     */
    #[DataProvider('renameHandshakes')]
    public function testHandshakeUsesTheAcceptedRenameWithoutRollingBackThePeer(bool $dialer, bool $pending): void
    {
        $server = $this->server();
        Hilos::$cluster->registry();
        [$near, $far] = $this->makeSocketPair();
        $link = $pending
            ? $this->link($near, $dialer, new NamedPeerTestTransport($near, self::REMOTE_NODE), $server)
            : null;

        putenv('CLUSTER_NODE_NAME=Renamed node');
        $this->assertTrue(Hilos::$cluster->reload());
        $this->assertSame('Renamed node', Hilos::$cluster->identity()->name);
        $link ??= $this->link($near, $dialer, new NamedPeerTestTransport($near, self::REMOTE_NODE), $server);

        $frame = $this->readLocalHandshake($link, $far, $dialer);
        $this->assertSame('Renamed node', $frame->name);
        $this->assertPeerRetainsNameWithoutRoster($frame);
    }

    /** @return iterable<string, array{bool, string}> Invalid names on both handshake directions */
    public static function refusedHandshakeReloads(): iterable
    {
        foreach (['hello' => true, 'welcome' => false] as $direction => $dialer) {
            yield $direction . ' control' => [$dialer, "Bad\nname"];
            yield $direction . ' Unicode line separator' => [$dialer, "Bad\u{2028}name"];
            yield $direction . ' Unicode paragraph separator' => [$dialer, "Bad\u{2029}name"];
        }
    }

    /**
     * @param bool $dialer Whether this node sends hello rather than welcome
     * @param string $invalidName Name refused after an earlier rename was accepted
     */
    #[DataProvider('refusedHandshakeReloads')]
    public function testRefusedReloadCannotLeakIntoAPendingHandshake(bool $dialer, string $invalidName): void
    {
        $server = $this->server();
        Hilos::$cluster->registry();
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, $dialer, new NamedPeerTestTransport($near, self::REMOTE_NODE), $server);
        putenv('CLUSTER_NODE_NAME=Renamed node');
        $this->assertTrue(Hilos::$cluster->reload());
        $acceptedIdentity = Hilos::$cluster->identity();
        $acceptedRegistry = Hilos::$cluster->registry()->snapshot();
        putenv('CLUSTER_NODE_NAME=' . $invalidName);

        try {
            Hilos::$cluster->reload();
            $this->fail('An invalid name must refuse reload');
        } catch (ClusterConfigurationException $exception) {
            $this->assertSame('CLUSTER_NODE_NAME must be one line of at most 64 characters', $exception->getMessage());
        }

        $this->assertSame($acceptedIdentity, Hilos::$cluster->identity());
        $this->assertSame($acceptedRegistry, Hilos::$cluster->registry()->snapshot());
        $this->assertSame('Renamed node', $this->readLocalHandshake($link, $far, $dialer)->name);
    }

    /** The explicit test-server identity still works without a cluster facade. */
    public function testHandshakeWithoutClusterContextKeepsTheSuppliedName(): void
    {
        $server = $this->server();
        Hilos::$cluster = null;

        foreach ([true, false] as $dialer) {
            [$near, $far] = $this->makeSocketPair();
            $link = $this->link($near, $dialer, new NamedPeerTestTransport($near, self::REMOTE_NODE), $server);

            $this->assertSame('Node ' . self::LOCAL_NODE, $this->readLocalHandshake($link, $far, $dialer)->name);
        }
    }

    public function testPreviousProtocolHelloAndWelcomeAreRefusedBeforeRegistration(): void
    {
        foreach ([false, true] as $dialer) {
            [$near, $far] = $this->makeSocketPair();
            $link = $this->link($near, dialer: $dialer, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE));
            $type = $dialer ? PeerWelcomeDTO::class : PeerHelloDTO::class;
            $this->deliver($far, new $type(25, self::REMOTE_NODE, 'Remote node', NodeRole::Master, [], PeerTestMarkers::onWire()));
            $link->read();

            $this->assertTrue($link->shouldClose());
            $this->assertNull($link->candidateIdentity());
            $this->assertNull($link->remoteIdentity());
            $this->assertNotContains(self::REMOTE_NODE, $this->registeredNodeIds());
        }
        $this->assertSame(2, substr_count($this->log(), 'Incompatible peer protocol version 25, expected 27'));
    }

    public function testAHelloNamingTheCertifiedNodeCompletesTheHandshake(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE));

        $this->deliver($far, $this->hello(self::REMOTE_NODE));
        $link->read();
        $this->assertSame('Node ' . self::REMOTE_NODE, $link->candidateIdentity()?->name);
        $link->write();
        $wire = socket_read($far, 65536, PHP_BINARY_READ);
        $this->assertIsString($wire);
        $welcome = PeerDTO::fromWire(trim($wire));
        $this->assertInstanceOf(PeerWelcomeDTO::class, $welcome);
        $this->assertSame('Node ' . self::LOCAL_NODE, $welcome->name);
        $this->deliver($far, new PeerReadyDTO(false));
        $link->read();

        $this->assertFalse($link->shouldClose());
        $this->assertSame(self::REMOTE_NODE, $link->remoteIdentity()?->nodeId);
        $this->assertSame('Node ' . self::REMOTE_NODE, $link->remoteIdentity()?->name);
        $this->assertContains(self::REMOTE_NODE, $this->registeredNodeIds());
    }

    public function testAHelloNamingAnotherNodeThanTheCertificateIsRefused(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE));

        $this->deliver($far, $this->hello('node-m'));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a hello naming another node than its certificate must drop the link');
        $this->assertNull($link->remoteIdentity());
        $this->assertNotContains('node-m', $this->registeredNodeIds());
        $this->assertStringContainsString(
            "Peer link dropped: Peer handshake names node 'node-m' but its certificate names 'node-b'",
            $this->log(),
        );
    }

    public function testAWelcomeNamingAnotherNodeThanTheCertificateIsRefusedOnTheDialingSide(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: true, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE));

        $this->deliver($far, new PeerWelcomeDTO(PeerProtocol::VERSION, 'node-m', 'Node node-m', NodeRole::Master, [], PeerTestMarkers::onWire()));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a welcome naming another node than its certificate must drop the link');
        $this->assertNull($link->remoteIdentity());
        $this->assertNotContains('node-m', $this->registeredNodeIds());
        $this->assertFalse($link->closedUnwelcomed(), 'this side refused the welcome; the peer did not close before it');
    }

    public function testATransportThatVouchesForNobodyRefusesEveryHandshake(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false, transport: new PlainSocketTransport($near));

        $this->deliver($far, $this->hello(self::REMOTE_NODE));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a link that vouches for no name must refuse the hello');
        $this->assertNull($link->remoteIdentity());
        $this->assertNotContains(self::REMOTE_NODE, $this->registeredNodeIds());
        $this->assertStringContainsString(
            "Peer handshake names node 'node-b' but the connection vouches for no certificate name",
            $this->log(),
        );
    }

    public function testOnlyADialedLinkThatNeverGotAWelcomeReadsAsClosedUnwelcomed(): void
    {
        [$dialedNear] = $this->makeSocketPair();
        $unwelcomed = $this->link($dialedNear, dialer: true, transport: new NamedPeerTestTransport($dialedNear, self::REMOTE_NODE));
        $this->assertTrue($unwelcomed->closedUnwelcomed());

        [$welcomedNear, $welcomedFar] = $this->makeSocketPair();
        $welcomed = $this->link($welcomedNear, dialer: true, transport: new NamedPeerTestTransport($welcomedNear, self::REMOTE_NODE));
        $this->deliver($welcomedFar, new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            self::REMOTE_NODE,
            'Node ' . self::REMOTE_NODE,
            NodeRole::Master,
            [],
            PeerTestMarkers::onWire(),
        ));
        $welcomed->read();
        $this->deliver($welcomedFar, new PeerReadyAckDTO(true));
        $welcomed->read();
        $this->assertNotNull($welcomed->remoteIdentity());
        $this->assertSame('Node ' . self::REMOTE_NODE, $welcomed->remoteIdentity()->name);
        $welcomed->discardAsDuplicate();
        $this->assertFalse($welcomed->closedUnwelcomed(), 'a welcomed link lost to the duplicate collapse was welcomed all the same');

        [$acceptedNear] = $this->makeSocketPair();
        $accepted = $this->link($acceptedNear, dialer: false, transport: new NamedPeerTestTransport($acceptedNear, self::REMOTE_NODE));
        $this->assertFalse($accepted->closedUnwelcomed(), 'only the dialing side waits for a welcome');
    }

    /**
     * A series of closes before welcome to one target is one line, until a handshake with that
     * target is taken to its end - then the next series is named again.
     */
    public function testTheServerNamesASeriesOfClosesBeforeWelcomeOnce(): void
    {
        $server = $this->server();
        $dial = new PeerDial(new PeerAddress('10.0.0.2', 8095));
        new ReflectionProperty($server, 'seedDials')->setValue($server, [$dial]);

        $this->dropUnwelcomedLink($server, $dial);
        $this->dropUnwelcomedLink($server, $dial);
        $this->assertSame(1, substr_count($this->log(), self::UNWELCOMED_LINE), 'one series is one line');

        // A handshake with the target taken to its end closes the series.
        [$near] = $this->makeSocketPair();
        $dial->link = $this->link($near, dialer: true, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE), server: $server);
        new ReflectionMethod($server, 'stampDialRemote')->invoke($server, $dial->link, self::REMOTE_NODE);

        $this->dropUnwelcomedLink($server, $dial);
        $this->assertSame(2, substr_count($this->log(), self::UNWELCOMED_LINE), 'a new series is named again');
    }

    /**
     * A dialed link this side drops on its own before any welcome - here after a silence timeout -
     * was not closed by the peer, and the server blames the peer for nothing.
     */
    public function testADialedLinkThatTimedOutBeforeAWelcomeIsNotBlamedOnThePeer(): void
    {
        $server = $this->server();
        $dial = new PeerDial(new PeerAddress('10.0.0.2', 8095));
        new ReflectionProperty($server, 'seedDials')->setValue($server, [$dial]);

        [$near] = $this->makeSocketPair();
        $link = $this->link($near, dialer: true, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE), server: $server);
        $dial->link = $link;
        // Last heard from at the epoch: the next tick finds the link silent past any timeout.
        new ReflectionProperty($link, 'lastHeardAt')->setValue($link, 0.0);
        $link->onTick();

        $this->assertTrue($link->shouldClose(), 'a link silent past the timeout must close');
        $this->assertFalse($link->closedUnwelcomed(), 'this side timed the link out; the peer did not close it');

        $dial->nextAttemptAt = PHP_FLOAT_MAX;
        new ReflectionMethod($server, 'driveDial')->invoke($server, $dial, microtime(true));

        $this->assertNull($dial->link, 'the dropped link must leave the dial');
        $this->assertStringContainsString('Peer link timed out', $this->log());
        $this->assertStringNotContainsString(self::UNWELCOMED_LINE, $this->log());
    }

    /**
     * Hands the dial a dialed link that TLS brought up and the peer closed before its welcome,
     * and lets the server notice the drop.
     *
     * @param PeerServer $server Server driving the dial
     * @param PeerDial $dial Dial the link belongs to
     */
    private function dropUnwelcomedLink(PeerServer $server, PeerDial $dial): void
    {
        [$near] = $this->makeSocketPair();
        $dial->link = $this->link($near, dialer: true, transport: new NamedPeerTestTransport($near, self::REMOTE_NODE), server: $server);
        $dial->link->markShouldClose();
        $dial->nextAttemptAt = PHP_FLOAT_MAX;

        new ReflectionMethod($server, 'driveDial')->invoke($server, $dial, microtime(true));

        $this->assertNull($dial->link, 'the dropped link must leave the dial');
    }

    /**
     * @param PeerLink $link Link of the node whose environment was reloaded
     * @param Socket $far Far end receiving its serialized handshake
     * @param bool $dialer Whether to start hello or deliver the peer's hello
     * @return PeerHelloDTO|PeerWelcomeDTO Handshake decoded from the socket
     */
    private function readLocalHandshake(PeerLink $link, Socket $far, bool $dialer): PeerHelloDTO|PeerWelcomeDTO
    {
        if ($dialer) {
            $link->startHandshake();
        } else {
            $this->deliver($far, $this->hello(self::REMOTE_NODE));
            $link->read();
        }
        $link->write();
        $wire = socket_read($far, 65536, PHP_BINARY_READ);
        $this->assertIsString($wire);
        $frame = PeerDTO::fromWire(trim($wire));
        $this->assertInstanceOf($dialer ? PeerHelloDTO::class : PeerWelcomeDTO::class, $frame);
        $this->assertSame(PeerProtocol::VERSION, $frame->protocolVersion);
        $this->assertSame(self::LOCAL_NODE, $frame->nodeId);
        $this->assertSame(NodeRole::Master, $frame->role);
        $this->assertSame([], $frame->capabilities);
        $this->assertNull($frame->address);
        $this->assertSame(PeerTestMarkers::onWire(), $frame->markers);

        return $frame;
    }

    /**
     * Delivers only the handshake and ready verdict to a neighbor that already learned the rename.
     *
     * @param PeerHelloDTO|PeerWelcomeDTO $frame Actual serialized handshake of the renamed node
     */
    private function assertPeerRetainsNameWithoutRoster(PeerHelloDTO|PeerWelcomeDTO $frame): void
    {
        $peerIdentity = NodeIdentity::of(self::REMOTE_NODE, 'Remote node', NodeRole::Master, []);
        $peerContext = new ClusterContext();
        new ReflectionProperty($peerContext, 'identity')->setValue($peerContext, $peerIdentity);
        Hilos::$cluster = $peerContext;
        $peerContext->registry()->mergeMembership(
            NodeIdentity::of(self::LOCAL_NODE, 'Renamed node', NodeRole::Master, []),
            microtime(true),
        );
        $peerServer = new PeerServer('127.0.0.1', 0, $peerIdentity, [], PeerTestTls::unread(), PeerTestMarkers::shared());
        [$near, $far] = $this->makeSocketPair();
        $link = new PeerLink($near, $peerServer, $peerIdentity, $frame instanceof PeerWelcomeDTO,
            new NamedPeerTestTransport($near, self::LOCAL_NODE));
        $this->deliver($far, $frame);
        $link->read();
        $this->assertSame('Renamed node', $link->candidateIdentity()?->name);
        $this->deliver($far, $frame instanceof PeerHelloDTO ? new PeerReadyDTO(false) : new PeerReadyAckDTO(true));
        $link->read();

        $this->assertFalse($link->shouldClose());
        $this->assertSame('Renamed node', $link->remoteIdentity()?->name);
        $this->assertSame('Renamed node', $peerContext->registry()->snapshot()[1]->name);
        $this->assertTrue($peerContext->registry()->isOnline(self::LOCAL_NODE));
        $peerServer->onLinkClosed($link);
        $this->assertFalse($peerContext->registry()->isOnline(self::LOCAL_NODE));
        $this->assertSame('Renamed node', $peerContext->registry()->snapshot()[1]->name);
    }

    /**
     * @param Socket $socket Near end of a pair
     * @param bool $dialer Whether the link dialed
     * @param PlainSocketTransport|NamedPeerTestTransport $transport Transport of the link
     * @param ?PeerServer $server Server of the link, a fresh one when null
     * @return PeerLink Link under test
     */
    private function link(
        Socket $socket,
        bool $dialer,
        PlainSocketTransport|NamedPeerTestTransport $transport,
        ?PeerServer $server = null,
    ): PeerLink {
        return new PeerLink($socket, $server ?? $this->server(), $this->localIdentity(), $dialer, $transport);
    }

    /**
     * @return PeerServer Server the links belong to
     */
    private function server(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, $this->localIdentity(), [], PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @return NodeIdentity This side of every link
     */
    private function localIdentity(): NodeIdentity
    {
        return NodeIdentity::of(self::LOCAL_NODE, 'Node ' . self::LOCAL_NODE, NodeRole::Master, []);
    }

    /**
     * @param string $nodeId Node id the hello introduces
     * @return PeerHelloDTO Hello of that node
     */
    private function hello(string $nodeId): PeerHelloDTO
    {
        return new PeerHelloDTO(PeerProtocol::VERSION, $nodeId, 'Node ' . $nodeId, NodeRole::Master, [], PeerTestMarkers::onWire(), null);
    }

    /**
     * Writes one frame into the far end, the way the node on the other side sends it.
     *
     * @param Socket $far Far end of a pair
     * @param PeerDTO $frame Frame to send
     */
    private function deliver(Socket $far, PeerDTO $frame): void
    {
        $this->assertNotFalse(socket_write($far, $frame->toJson() . "\n"));
    }

    /**
     * @return list<string> Node ids the cluster registry holds
     */
    private function registeredNodeIds(): array
    {
        $registry = Hilos::$cluster->registry();
        $this->assertNotNull($registry);

        return array_map(static fn(ClusterNode $node): string => $node->nodeId, $registry->snapshot());
    }

    /**
     * @return string Everything the logger wrote during the test
     */
    private function log(): string
    {
        return (string)file_get_contents($this->logFile);
    }

    /**
     * @return array{0: Socket, 1: Socket} Connected, non-blocking pair: near end, far end
     */
    private function makeSocketPair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $this->sockets = [...$this->sockets, $pair[0], $pair[1]];

        return $pair;
    }
}
