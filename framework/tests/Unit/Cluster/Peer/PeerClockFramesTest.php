<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Clock\ClusterClock;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerClockProbeDTO;
use Hilos\Cluster\Peer\DTO\PeerClockReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Transport\PlainSocketTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Clock frames travel through the shared parser and the real link dispatch. */
final class PeerClockFramesTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    /** @var list<Socket> Handles behind links whose outbound bytes remain buffered */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testClockFramesRoundTripWithTheirExactWireFields(): void
    {
        $probe = new PeerClockProbeDTO('probe-7');
        $reply = new PeerClockReplyDTO('probe-7', 1_800_000_000_123_456);
        self::assertSame(['type' => 'peer_clock_probe', 'probeId' => 'probe-7'], $probe->toArray());
        self::assertSame([
            'type' => 'peer_clock_reply',
            'probeId' => 'probe-7',
            'nodeTimeUs' => 1_800_000_000_123_456,
        ], $reply->toArray());

        foreach ([$probe, $reply] as $frame) {
            $parsed = PeerDTO::fromWire($frame->toJson());
            self::assertSame($frame::class, $parsed::class);
            self::assertSame($frame->toArray(), $parsed->toArray());
            self::assertSame($frame->toArray(), $frame::fromArray($frame->toArray())->toArray());
        }
    }

    /** @return iterable<string, array{class-string<PeerDTO>, array<string, mixed>}> Invalid required fields */
    public static function malformedFrames(): iterable
    {
        foreach ([PeerClockProbeDTO::class, PeerClockReplyDTO::class] as $class) {
            yield $class . ' missing id' => [$class, ['nodeTimeUs' => 123]];
            yield $class . ' empty id' => [$class, ['probeId' => '', 'nodeTimeUs' => 123]];
            yield $class . ' blank id' => [$class, ['probeId' => '  ', 'nodeTimeUs' => 123]];
            yield $class . ' mistyped id' => [$class, ['probeId' => 7, 'nodeTimeUs' => 123]];
        }
        yield 'missing node time' => [PeerClockReplyDTO::class, ['probeId' => 'p']];
        yield 'null node time' => [PeerClockReplyDTO::class, ['probeId' => 'p', 'nodeTimeUs' => null]];
        yield 'string node time' => [PeerClockReplyDTO::class, ['probeId' => 'p', 'nodeTimeUs' => '123']];
        yield 'float node time' => [PeerClockReplyDTO::class, ['probeId' => 'p', 'nodeTimeUs' => 123.5]];
    }

    /**
     * @param class-string<PeerDTO> $class Parser under test
     * @param array<string, mixed> $payload Broken wire fields
     */
    #[DataProvider('malformedFrames')]
    public function testMissingOrMalformedFieldsAreRefused(string $class, array $payload): void
    {
        $this->expectException(PeerTransportException::class);
        $class::fromArray($payload);
    }

    /** @return iterable<string, array{PeerDTO, string}> Both post-handshake frame kinds */
    public static function clockFrames(): iterable
    {
        yield 'probe' => [new PeerClockProbeDTO('p'), 'clock probe'];
        yield 'reply' => [new PeerClockReplyDTO('p', 123), 'clock reply'];
    }

    /**
     * @param PeerDTO $frame Clock frame received before authentication finishes
     * @param string $kind Frame kind named in the sequence refusal
     */
    #[DataProvider('clockFrames')]
    public function testClockFramesAreRefusedBeforeTheHandshake(PeerDTO $frame, string $kind): void
    {
        $link = $this->link($this->server());
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage($kind);
        new ReflectionMethod(PeerLink::class, 'handleFrame')->invoke($link, $frame);
    }

    public function testAHandshakedNodeAnswersWithTheSameProbeIdAndItsOwnClock(): void
    {
        $server = $this->server();
        $clock = new ClusterClock(static fn (): array => [], static fn (): int => 1_800_000_000_123_456);
        $clock->setTestShift(812);
        new ReflectionProperty(PeerServer::class, 'clock')->setValue($server, $clock);
        $link = $this->link($server);
        $probe = new PeerClockProbeDTO('probe-echo');

        // The server also ignores an unidentifiable link when called directly.
        $server->onClockProbeReceived($link, $probe);
        self::assertSame('', $this->buffer($link));
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of('leader', 'Node leader', NodeRole::Master, []));
        new ReflectionMethod(PeerLink::class, 'handleFrame')->invoke($link, $probe);

        $reply = PeerDTO::fromWire(trim($this->buffer($link)));
        self::assertInstanceOf(PeerClockReplyDTO::class, $reply);
        self::assertSame('probe-echo', $reply->probeId);
        self::assertSame(1_800_000_000_935_456, $reply->nodeTimeUs);
    }

    public function testAHandshakedReplyReachesTheLeadersSeries(): void
    {
        $server = $this->server();
        $link = $this->link($server);
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of('node-b', 'Node node-b', NodeRole::Slave, []));
        $monoNs = 1_000_000;
        $clock = new ClusterClock(
            static fn (): array => ['node-b' => $link],
            static fn (): int => 1_800_000_000_000_000,
            static function () use (&$monoNs): int {
                return $monoNs;
            },
        );
        new ReflectionProperty(PeerServer::class, 'clock')->setValue($server, $clock);
        $clock->onBecameLeader();
        $probe = PeerDTO::fromWire(trim($this->buffer($link)));
        self::assertInstanceOf(PeerClockProbeDTO::class, $probe);
        $monoNs += 2_000_000;

        new ReflectionMethod(PeerLink::class, 'handleFrame')->invoke(
            $link,
            new PeerClockReplyDTO($probe->probeId, 1_800_000_000_001_000),
        );

        $frames = explode("\n", trim($this->buffer($link)));
        self::assertCount(2, $frames);
        $next = PeerDTO::fromWire($frames[1]);
        self::assertInstanceOf(PeerClockProbeDTO::class, $next);
        self::assertNotSame($probe->probeId, $next->probeId);
    }

    /** @return PeerServer Server whose listener is never opened */
    private function server(): PeerServer
    {
        return new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-b', 'Node node-b', NodeRole::Slave, []),
            [],
            PeerTestTls::unread(),
            PeerTestMarkers::shared(),
        );
    }

    /**
     * @param PeerServer $server Server receiving parsed frames
     * @return PeerLink Link without a completed handshake
     */
    private function link(PeerServer $server): PeerLink
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        self::assertInstanceOf(Socket::class, $socket);
        $this->sockets[] = $socket;
        return new PeerLink(
            $socket,
            $server,
            NodeIdentity::of('node-b', 'Node node-b', NodeRole::Slave, []),
            false,
            new PlainSocketTransport($socket),
        );
    }

    /**
     * @param PeerLink $link Link whose queued frames are inspected without socket I/O
     * @return string Serialized outbound frames
     */
    private function buffer(PeerLink $link): string
    {
        return new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($link);
    }
}
