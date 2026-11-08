<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Error;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerPingDTO;
use Hilos\Cluster\Peer\DTO\PeerPongDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\TimeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\TlsContext;
use Hilos\Socket\Transport\TlsSocketTransport;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Native peer deadlines use original positive spans and addressed past observations, without expiry waits. */
final class PeerLinkLivenessEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private MutualTlsFixture $fixture;

    private ?TlsTestExchange $exchange = null;

    private PeerServer $server;

    private ?EnvAccessor $previousEnv;

    private ?ClusterContext $previousCluster;

    /** @var array<string, string|false> Environment restored after each case */
    private array $previousValues = [];

    /** @var list<PeerLink> Actual connections closed before the native base */
    private array $links = [];

    /** @var list<Socket> Socket-pair ends outside the TLS fixture */
    private array $sockets = [];

    /** Both real mTLS roles acquire a silence bound before any TLS or application handshake. */
    public function testAcceptedTlsTimesOutBeforeHandshakeInsideTheGuardedPortion(): void
    {
        $this->assertTlsHalfOpenExpires(false);
    }

    /** A promoted outgoing TLS connection has the same constructor-age deadline as an acceptor. */
    public function testOutgoingTlsTimesOutBeforeHandshakeInsideTheGuardedPortion(): void
    {
        $this->assertTlsHalfOpenExpires(true);
    }

    /** Binding, unrelated readiness and queued output retain exactly the original future deadline. */
    public function testOriginalAgeAndFutureTimerSurviveIdleTrafficAndRebinding(): void
    {
        [$link] = $this->plain();
        $heard = self::now() - 0.5;
        $this->set($link, 'lastHeardAt', $heard);
        $this->bind($link);
        $timer = $this->timer($link);
        $this->assertSame(1.0, $this->get($link, 'keepaliveIntervalSec'));
        $this->assertSame(5.0, $this->get($link, 'linkTimeoutSec'));
        $this->assertSame($heard + 5.0, $timer->getDeadline());
        $generation = $this->get($timer, 'generation');
        $this->loop->tick();
        [$other, $far] = $this->plain();
        $this->bind($other);
        socket_write($far, (new PeerPongDTO())->toJson());
        $link->sendFrame(new PeerPongDTO());
        for ($pass = 0; $pass < 8; ++$pass) {
            $link->onTick();
            $this->loop->tick();
        }
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->assertSame($heard + 5.0, $timer->getDeadline());
        $this->assertSame($generation, $this->get($timer, 'generation'));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->bind($link);
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $this->assertNull($timer->getDeadline());
        $this->loop->tick();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** An escaped old callback and queued ticket cannot cue the new binding of the same link. */
    public function testDetachInvalidatesActivatedAndEscapedOldNotifications(): void
    {
        [$link] = $this->plain();
        $this->bind($link);
        $this->loop->tick();
        $timer = $this->timer($link);
        $escaped = $this->get($timer, 'callback');
        $ticket = $this->get($timer, 'ready');
        $this->expire($link);
        $this->loop->tick();
        $this->assertFalse($link->shouldClose(), 'timer callback only cues a client portion');
        $this->set($link, 'lastHeardAt', self::now());
        $this->bind($link);
        $this->loop->tick();
        $deadline = $this->timer($link)->getDeadline();
        $escaped();
        $ticket->markReady();
        $this->assertTrue($ticket->isClosed());
        $this->assertFalse($this->get($link, 'livenessReady'));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame($deadline, $this->timer($link)->getDeadline());
        $this->assertFalse($link->shouldClose());
    }

    /** Activation enables max(lastHeard+interval,lastPing+interval); a late due sends just once. */
    public function testExactNearestPingDeadlineAndTimeoutBeforePing(): void
    {
        [$link, $far] = $this->plain();
        $now = self::now();
        $heard = $now - 2.0;
        $this->set($link, 'lastHeardAt', $heard);
        $this->set($link, 'lastPingAt', $now - 0.25);
        $this->bind($link);
        $link->activate($this->remote());
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->assertSame($now + 0.75, $this->timer($link)->getDeadline());
        $this->loop->tick();
        $this->set($link, 'lastPingAt', $now + 10.0);
        $this->arm($link);
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline(), 'silence bounds even a later spaced ping');
        $this->set($link, 'lastPingAt', $now - 2.0);
        $this->arm($link);
        $this->loop->tick();
        $this->assertSame(0, $link->uploadOutboundPosition(), 'a due timer sends no protocol frame itself');
        $beforeAction = self::now();
        $this->loop->tick();
        $bytes = socket_read($far, 65536);
        $this->assertIsString($bytes);
        $this->assertSame(1, substr_count($bytes, PeerPingDTO::MESSAGE_TYPE));
        $sentAt = $this->get($link, 'lastPingAt');
        $this->assertGreaterThanOrEqual($beforeAction, $sentAt);
        $this->assertSame($sentAt + 1.0, $this->timer($link)->getDeadline());
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'), 'outbound ping is no proof of life');
        $link->onTick();
        $this->assertSame($sentAt, $this->get($link, 'lastPingAt'));
        $position = $link->uploadOutboundPosition();
        $this->expire($link);
        $this->loop->tick();
        $this->assertFalse($link->shouldClose());
        $this->loop->tick();
        $this->assertTrue($link->shouldClose());
        $this->assertSame($position, $link->uploadOutboundPosition(), 'close wins even when a ping is also due');
        $this->assertNull($link->getSocket());
    }

    /** Real new bytes, even a JSON fragment, precede an already cued timeout in the client guard. */
    public function testNewPartialBytesOnTheDuePassRenewSilenceBeforeTimeout(): void
    {
        [$link, $far] = $this->plain();
        $this->bind($link);
        $this->loop->tick();
        $this->expire($link);
        $this->loop->tick();
        $this->assertTrue($this->get($link, 'livenessReady'));
        $this->assertFalse($link->shouldClose());
        $before = self::now();
        socket_write($far, '{"type":');
        $this->loop->tick();
        $heard = $this->get($link, 'lastHeardAt');
        $this->assertGreaterThanOrEqual($before, $heard);
        $this->assertFalse($link->shouldClose());
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $link->read();
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'), 'empty EAGAIN is no renewal');
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
    }

    /** Whole buffered frames retain the byte observation; a timer still closes their silent connection. */
    public function testBufferedParserContinuationDoesNotRenewSilence(): void
    {
        [$link, $far] = $this->plain();
        $this->bind($link);
        $this->loop->tick();
        socket_write($far, str_repeat((new PeerPongDTO())->toJson() . "\n", 65));
        $link->read();
        $heard = $this->get($link, 'lastHeardAt');
        $this->assertTrue(new ReflectionProperty(AbstractClient::class, 'readBufferReady')->getValue($link));
        $link->read();
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->expire($link);
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
        }
        $this->assertTrue($link->shouldClose());
        $this->assertNull($link->getSocket());
    }

    /** stopReading discards transport bytes without refreshing silence, including a due pass. */
    public function testStoppedReadingCannotKeepADuplicateAlive(): void
    {
        [$link, $far] = $this->plain();
        $this->bind($link);
        $this->loop->tick();
        $link->stopReading();
        $this->expire($link);
        $heard = $this->get($link, 'lastHeardAt');
        $this->loop->tick();
        socket_write($far, (new PeerPongDTO())->toJson());
        $this->loop->tick();
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->assertNull($link->getSocket());
    }

    /** CONNECTED/WANT progress is not inbound application data; a live session still cannot migrate. */
    public function testActualTlsHandshakeDoesNotRenewAgeAndRebindKeepsTheNativeBase(): void
    {
        $link = $this->tlsExchangeLink();
        $heard = $this->get($link, 'lastHeardAt');
        $this->exchange->handshake();
        $this->assertSame([], $this->exchange->failures);
        $this->assertSame('node-b', $link->certifiedPeerName());
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->exchange->bindClient('accepting', $link);
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $otherLoop = new EventLoop();
        try {
            $link->attachEventLoop($otherLoop, static function (): void {});
            $this->fail('a liveness rebind must not permit live TLS migration');
        } catch (SocketTlsHandshakeException) {
            $this->assertNull($this->get($link, 'livenessTimer'));
            $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        } finally {
            $otherLoop->close();
        }
    }

    /** Drain disables pings while actual native removal still governs closeOnceWritten. */
    public function testTlsDrainRetainsSilenceAndWaitsForActualNativeOutput(): void
    {
        $link = $this->tlsExchangeLink();
        $this->exchange->handshake();
        $link->activate($this->remote());
        $heard = $this->get($link, 'lastHeardAt');
        $link->beginDrain();
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $link->closeOnceWritten();
        $link->write();
        $this->assertTrue($link->hasPendingWrite());
        $this->assertFalse($link->shouldClose(), 'native enqueue is not drain');
        $this->exchange->runUntil(fn(): bool => $link->shouldClose() && $this->exchange->received['dialing'] !== '');
        $this->assertFalse($link->hasPendingWrite());
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
    }

    /** A real native TLS application read, unlike CONNECTED or an empty probe, renews the owned timer. */
    public function testActualTlsApplicationBytesRenewTheDeadline(): void
    {
        $link = $this->tlsExchangeLink();
        $this->exchange->handshake();
        $heard = $this->get($link, 'lastHeardAt');
        $frame = (new PeerPongDTO())->toJson();
        $this->assertSame(0, $this->exchange->transports['dialing']->write($frame));
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'), 'native enqueue has not delivered application bytes');
        $this->exchange->runUntil(fn(): bool => $this->get($link, 'lastHeardAt') > $heard);
        $renewed = $this->get($link, 'lastHeardAt');
        $this->assertSame($renewed + 5.0, $this->timer($link)->getDeadline());
        $this->assertSame(strlen($frame), $this->exchange->transports['dialing']->write($frame));
        $link->read();
        $this->assertSame($renewed, $this->get($link, 'lastHeardAt'));
        $this->assertFalse($link->shouldClose());
        $this->assertSame([], $this->exchange->failures);
    }

    /** Preparing shutdown retires dial owners while the established link keeps its original silence bound. */
    public function testPreparingShutdownRetainsEstablishedTimeoutUntilTheExactDrop(): void
    {
        $link = $this->tlsExchangeLink();
        $this->exchange->handshake();
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($this->server, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($this->server, $link);
        $this->server->registerCandidate($link, $this->remote());
        $this->server->activateCandidate($link, $this->remote());
        $heard = $this->get($link, 'lastHeardAt');
        $timer = $this->timer($link);
        $this->server->prepareShutdown();
        $this->assertSame($timer, $this->timer($link));
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->assertFalse($this->server->isReadyToShutdown());
        $link->beginDrain();
        $this->assertSame($heard + 5.0, $timer->getDeadline());
        $link->write();
        $this->assertTrue($link->hasPendingWrite());
        $this->expire($link);
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->exchange->loop->tick();
        }
        $this->assertTrue($link->shouldClose());
        $this->assertNull($timer->getDeadline());
        $this->server->dropClient($link);
        $this->assertNull($this->get($link, 'livenessTimer'));
        $this->assertFalse($link->hasPendingWrite());
        $this->assertTrue($this->server->isReadyToShutdown());
        $this->assertSame([], $this->server->getClients());
        $this->assertSame([], $this->exchange->failures);
    }

    /** A duplicate clears identity before waiting for its original timeout and queued native output. */
    public function testDuplicateTlsOutputRemainsBoundedWithoutPingOrDeparture(): void
    {
        $link = $this->tlsExchangeLink();
        $this->exchange->handshake();
        $link->activate($this->remote());
        $link->sendFrame(new PeerPongDTO());
        $link->write();
        $heard = $this->get($link, 'lastHeardAt');
        $link->discardAsDuplicate();
        $this->assertNull($link->remoteIdentity());
        $this->assertNull($link->candidateIdentity());
        $this->assertTrue($link->hasPendingWrite());
        $this->assertFalse($link->shouldClose());
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $this->exchange->runUntil(fn(): bool => $link->shouldClose() && $this->exchange->received['dialing'] !== '');
        $this->assertFalse($link->hasPendingWrite());
        $this->assertSame(0.0, $this->get($link, 'lastPingAt'));
    }

    /** Old draining closes preserve the new route/report; timeout plus EOF departs exactly once. */
    public function testOldRouteAndSimultaneousTimeoutEofUseTheSingleExactDropPath(): void
    {
        $observer = $this->createMock(MembershipObserver::class);
        $observer->expects($this->once())->method('onNodeLeft');
        Hilos::$cluster->registerMembershipObserver($observer);
        [$old] = $this->plain();
        $this->bind($old);
        $this->server->registerCandidate($old, $this->remote());
        $this->server->activateCandidate($old, $this->remote());
        [$current, $far] = $this->plain();
        $this->bind($current);
        $this->server->registerCandidate($current, $this->remote());
        $this->server->activateCandidate($current, $this->remote());
        $report = new PeerProtectedModeNodeReportDTO(null, null, null, null, null);
        $this->set($this->server, 'protectedModeNodeReports', ['node-b' => $report]);
        $this->loop->tick();
        $this->expire($old);
        $this->loop->tick();
        $this->loop->tick();
        $this->assertNull($old->getSocket());
        $this->assertTrue($this->server->hasActiveLinkToNode('node-b'));
        $this->assertSame(['node-b' => $report], $this->get($this->server, 'protectedModeNodeReports'));
        $this->expire($current);
        $this->loop->tick();
        socket_shutdown($far);
        $this->loop->tick();
        $this->loop->tick();
        $current->close();
        $this->assertFalse($this->server->hasActiveLinkToNode('node-b'));
        $this->assertSame([], $this->get($this->server, 'protectedModeNodeReports'));
        $this->assertNull($current->getSocket());
        $this->assertFalse($current->hasPendingWrite());
        $this->assertSame([], $this->server->getClients());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Isolate facade state; native cases always use the unmodified positive production intervals. */
    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE' => '65536',
            'CLUSTER_ENABLED' => 'true',
            'CLUSTER_NODE_ID' => 'node-a',
            'CLUSTER_NODE_NAME' => 'Local',
            'CLUSTER_NODE_ROLE' => 'master',
            'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS' => null,
            'CLUSTER_LINK_TIMEOUT_MS' => null,
        ] as $key => $value) {
            $this->previousValues[$key] = getenv($key);
            putenv($value === null ? $key : $key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        $this->loop = new EventLoop();
        $this->fixture = new MutualTlsFixture();
        $this->server = new PeerServer('127.0.0.1', 0, $this->local(), [], PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /** Release timers and owner tickets before transport sockets and their native base. */
    protected function tearDown(): void
    {
        $this->exchange?->close();
        foreach ($this->links as $link) {
            $link->close();
        }
        $this->loop->close();
        $this->fixture->close();
        foreach ($this->sockets as $socket) {
            try {
                socket_close($socket);
            } catch (Error) {
                // The guarded drop already closed this end.
            }
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach ($this->previousValues as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /**
     * @param bool $dialer Role of the half-open native TLS end
     */
    private function assertTlsHalfOpenExpires(bool $dialer): void
    {
        $authority = $this->fixture->issueAuthority('Peer liveness CA');
        $pem = $this->fixture->issueNode('node-a', $authority);
        $trust = $this->fixture->trustFile($authority);
        [$accepted, $dialed] = $this->fixture->connectSockets();
        $socket = $dialer ? $dialed : $accepted;
        $transport = $dialer
            ? TlsSocketTransport::dialing($socket, TlsContext::dialing($pem, $trust))
            : TlsSocketTransport::accepting($socket, TlsContext::accepting($pem, $trust));
        $link = new PeerLink($socket, $this->server, $this->local(), $dialer, $transport);
        $this->links[] = $link;
        $heard = self::now() - 5.0;
        $this->set($link, 'lastHeardAt', $heard);
        $this->bind($link);
        $this->assertSame($heard + 5.0, $this->timer($link)->getDeadline());
        $this->loop->tick();
        $this->assertTrue($transport->needsHandshake());
        $this->assertFalse($link->shouldClose(), 'native due callback cannot close outside the client guard');
        $this->loop->tick();
        $this->assertTrue($link->shouldClose());
        $this->assertSame($heard, $this->get($link, 'lastHeardAt'));
        $this->assertTrue($this->get($link, 'droppedHere'));
        $this->assertNull($link->getSocket());
        $this->assertNull($this->get($link, 'livenessTimer'));
        $this->assertSame([], $this->server->getClients());
    }

    /** @return array{PeerLink, Socket} Link and its controllable inbound application source */
    private function plain(): array
    {
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            socket_set_nonblock($socket);
            $this->sockets[] = $socket;
        }
        $link = new PeerLink($pair[0], $this->server, $this->local(), false, new PlainSocketTransport($pair[0]));
        $this->links[] = $link;
        return [$link, $pair[1]];
    }

    /**
     * @param PeerLink $link Real client on the native loop
     */
    private function bind(PeerLink $link): void
    {
        if (!in_array($link, $this->server->getClients(), true)) {
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($this->server, $link);
            new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($this->server, $link);
        }
        $link->attachEventLoop($this->loop, function () use ($link): void {
            $link->read();
            $link->onTick();
            $link->write();
            if ($link->shouldClose()) {
                $this->server->dropClient($link);
            }
        });
    }

    /** @return PeerLink Real mutual TLS link with its production guarded read/tick/write order */
    private function tlsExchangeLink(): PeerLink
    {
        $authority = $this->fixture->issueAuthority('Peer liveness CA');
        $trust = $this->fixture->trustFile($authority);
        $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('node-a', $authority), $this->fixture->issueNode('node-b', $authority), $trust, $trust);
        $link = new PeerLink($this->exchange->sockets['accepting'], $this->server, $this->local(), false,
            $this->exchange->transports['accepting']);
        $this->exchange->bindClient('accepting', $link);
        return $link;
    }

    /**
     * @param PeerLink $link Addressed connection whose existing silence is brought to the inclusive boundary
     */
    private function expire(PeerLink $link): void
    {
        $this->set($link, 'lastHeardAt', self::now() - 5.0);
        $this->arm($link);
    }

    /**
     * @param PeerLink $link Addressed deadline observation
     */
    private function arm(PeerLink $link): void
    {
        new ReflectionMethod($link, 'armLiveness')->invoke($link);
    }

    /**
     * @param PeerLink $link Bound native client
     * @return EventLoopTimer Its current owned silence/quiet handle
     */
    private function timer(PeerLink $link): EventLoopTimer
    {
        return $this->get($link, 'livenessTimer');
    }

    /**
     * @param object $owner Object whose private observation is inspected
     * @param string $field Local member
     * @return mixed Exact current value
     */
    private function get(object $owner, string $field): mixed
    {
        return new ReflectionProperty($owner, $field)->getValue($owner);
    }

    /**
     * @param object $owner Object whose original observation is addressed
     * @param string $field Local member
     * @param mixed $value Exact test observation
     */
    private function set(object $owner, string $field, mixed $value): void
    {
        new ReflectionProperty($owner, $field)->setValue($owner, $value);
    }

    /** @return NodeIdentity Local certified role */
    private function local(): NodeIdentity
    {
        return NodeIdentity::of('node-a', 'Local', NodeRole::Master, []);
    }

    /** @return NodeIdentity Remote certified role */
    private function remote(): NodeIdentity
    {
        return NodeIdentity::of('node-b', 'Remote', NodeRole::Master, []);
    }

    /** @return float Monotonic seconds, used only for addressed observations */
    private static function now(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }
}
