<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The markers of a hello and a welcome bind the handshake on both ends of a link (HIL-1206).
 *
 * Each end names its markers and judges the other's right after the certificate name: a peer
 * that reads another database is refused before it is remembered, the server hears nothing of it,
 * and an accepting side sends no welcome. The certificate name is right in every case here, so
 * what refuses is the marker and nothing else.
 */
final class PeerLinkMarkerTest extends TestCase
{
    /** Node id of this side of every link */
    private const string LOCAL_NODE = 'node-a';

    /** Node id the far end introduces itself with */
    private const string REMOTE_NODE = 'node-b';

    /** Database marker a node on another database reads */
    private const string FOREIGN_MARKER = 'ffffffffffffffffffffffffffffffff';

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
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$cluster = new ClusterContext();

        $file = tempnam(sys_get_temp_dir(), 'hilos-peer-link-marker');
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
        foreach (['SOCKET_READ_BUFFER_SIZE', 'CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }
    }

    public function testAHelloNamingTheSameMarkerCompletesTheHandshakeAndIsWelcomedWithIt(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false);

        $this->deliver($far, $this->hello(PeerTestMarkers::onWire()));
        $link->read();

        $this->assertFalse($link->shouldClose());
        $this->assertSame(self::REMOTE_NODE, $link->remoteIdentity()?->nodeId);
        $this->assertContains(self::REMOTE_NODE, $this->registeredNodeIds());
        $welcome = $this->flushAndReadFar($link, $far);
        $this->assertStringContainsString(PeerWelcomeDTO::MESSAGE_TYPE, $welcome);
        $this->assertStringContainsString(PeerTestMarkers::DATABASE_MARKER, $welcome, 'the welcome names this node\'s marker');
    }

    public function testTheHelloADialerSendsNamesItsMarkers(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: true);

        $link->startHandshake();
        $hello = json_decode(trim($this->flushAndReadFar($link, $far)), true);

        $this->assertIsArray($hello);
        $this->assertSame(PeerTestMarkers::onWire(), $hello[PeerHandshakeDTO::FIELD_MARKERS]);
    }

    public function testAHelloNamingAnotherDatabaseMarkerIsRefusedAndNotWelcomed(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false);

        $this->deliver($far, $this->hello([PeerMarkers::DATABASE => self::FOREIGN_MARKER]));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a hello from a node on another database must drop the link');
        $this->assertNull($link->remoteIdentity());
        $this->assertNotContains(self::REMOTE_NODE, $this->registeredNodeIds());
        $this->assertSame('', $this->flushAndReadFar($link, $far), 'no welcome goes back to a refused hello');
        $this->assertStringContainsString(
            "Peer link dropped: Peer handshake from node 'node-b' names database marker '" . self::FOREIGN_MARKER . "',"
            . " but this node reads '" . PeerTestMarkers::DATABASE_MARKER . "' from " . PeerTestMarkers::DATABASE_PLACE
            . ': the two nodes do not read one database',
            $this->log(),
        );
    }

    public function testAWelcomeNamingAnotherDatabaseMarkerIsRefusedOnTheDialingSide(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: true);

        $this->deliver($far, new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            self::REMOTE_NODE,
            NodeRole::Master,
            [],
            [PeerMarkers::DATABASE => self::FOREIGN_MARKER],
        ));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a welcome from a node on another database must drop the link');
        $this->assertNull($link->remoteIdentity());
        $this->assertNotContains(self::REMOTE_NODE, $this->registeredNodeIds());
        $this->assertFalse($link->closedUnwelcomed(), 'this side refused the welcome; the peer did not close before it');
        $this->assertStringContainsString(
            "Peer link dropped: Peer handshake from node 'node-b' names database marker '" . self::FOREIGN_MARKER . "'",
            $this->log(),
        );
    }

    public function testAHelloNamingNoDatabaseMarkerIsRefused(): void
    {
        [$near, $far] = $this->makeSocketPair();
        $link = $this->link($near, dialer: false);

        $this->deliver($far, $this->hello([]));
        $link->read();

        $this->assertTrue($link->shouldClose(), 'a hello that names no database marker must drop the link');
        $this->assertNotContains(self::REMOTE_NODE, $this->registeredNodeIds());
        $this->assertStringContainsString(
            "Peer link dropped: Peer handshake from node 'node-b' names no database marker",
            $this->log(),
        );
    }

    /**
     * @param Socket $socket Near end of a pair
     * @param bool $dialer Whether the link dialed
     * @return PeerLink Link under test, over a transport that vouches for the far node's name
     */
    private function link(Socket $socket, bool $dialer): PeerLink
    {
        $identity = NodeIdentity::of(self::LOCAL_NODE, NodeRole::Master, []);
        $server = new PeerServer('127.0.0.1', 0, $identity, [], PeerTestTls::unread(), PeerTestMarkers::shared());

        return new PeerLink($socket, $server, $identity, $dialer, new NamedPeerTestTransport($socket, self::REMOTE_NODE));
    }

    /**
     * @param array<string, string> $markers Markers the hello names
     * @return PeerHelloDTO Hello of the far node
     */
    private function hello(array $markers): PeerHelloDTO
    {
        return new PeerHelloDTO(PeerProtocol::VERSION, self::REMOTE_NODE, NodeRole::Master, [], $markers, null);
    }

    /**
     * Writes one frame into the far end, the way the node on the other side sends it.
     *
     * @param Socket $far Far end of a pair
     * @param PeerHelloDTO|PeerWelcomeDTO $frame Frame to send
     */
    private function deliver(Socket $far, PeerHelloDTO|PeerWelcomeDTO $frame): void
    {
        $this->assertNotFalse(socket_write($far, $frame->toJson() . "\n"));
    }

    /**
     * Flushes the link's outbound buffer and returns everything readable on the far end.
     *
     * Asks the far end whether anything arrived before reading it: a read of an empty
     * non-blocking socket warns, and a warning fails the suite.
     *
     * @param PeerLink $link Link to flush
     * @param Socket $far Far end of its pair
     * @return string Bytes the far end received, empty when the link sent nothing
     */
    private function flushAndReadFar(PeerLink $link, Socket $far): string
    {
        $link->write();

        $readable = [$far];
        $unused = null;
        $unusedToo = null;
        if (socket_select($readable, $unused, $unusedToo, 0) !== 1) {
            return '';
        }

        $received = socket_read($far, 65536, PHP_BINARY_READ);
        $this->assertIsString($received);

        return $received;
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
