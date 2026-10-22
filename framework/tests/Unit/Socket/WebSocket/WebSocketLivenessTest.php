<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketLivenessSignalDTO;
use Hilos\Socket\WebSocket\Exception\ReservedOpcodeException;
use Hilos\Socket\WebSocket\Liveness\WebSocketLiveness;
use Hilos\Socket\WebSocket\Liveness\WebSocketLivenessConfig;
use Hilos\Tests\Unit\Runtime\EmptyTestRtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Liveness of a browser socket: the deadlines alone, then real socket bytes through the server's own tick,
 * with a monotonic clock each test moves by hand.
 */
final class WebSocketLivenessTest extends TestCase
{
    private const array PING = ['opcode' => 9, 'text' => ''];

    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    private LivenessTestServer $server;
    /** @var list<Socket> Browser ends of the connections this test opened */
    private array $browsers = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        self::clearSpans();
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        // No copy of the connections here unless a case gives one: the check then has no row to read.
        Hilos::$rt = null;
        $this->server = new LivenessTestServer();
    }

    protected function tearDown(): void
    {
        foreach ($this->server->heldClients() as $client) {
            $client->close();
        }
        foreach ($this->browsers as $browser) {
            socket_close($browser);
        }
        self::clearSpans();
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
    }

    public function testTheDefaultSpansAreFortyFiftyOneHundredTwentyAndSixHundredSeconds(): void
    {
        $config = WebSocketLivenessConfig::fromEnv();

        self::assertSame(
            [40000, 50000, 120000, 600000],
            [$config->pingIdleMs, $config->pingIntervalMs, $config->probablyOfflineMs, $config->closeTimeoutMs],
        );
    }

    public function testEachDeadlineOfAnUnansweredSocketFallsExactlyOnItsMillisecond(): void
    {
        $liveness = new WebSocketLiveness(WebSocketLivenessConfig::fromEnv(), 0);

        self::assertFalse($liveness->isPingDue(39999));
        self::assertTrue($liveness->isPingDue(40000));
        $liveness->recordPingSent(40000);
        self::assertFalse($liveness->isPingDue(89999));
        self::assertTrue($liveness->isPingDue(90000));
        $liveness->recordPingSent(90000);

        self::assertFalse($liveness->isProbablyOfflineTransitionDue(119999));
        self::assertFalse($liveness->probablyOffline);
        self::assertTrue($liveness->isProbablyOfflineTransitionDue(120000));
        $liveness->settleProbablyOffline(120000);
        self::assertTrue($liveness->probablyOffline);
        self::assertFalse($liveness->isProbablyOfflineTransitionDue(120001));

        self::assertFalse($liveness->isPingDue(139999));
        self::assertTrue($liveness->isPingDue(140000));
        $liveness->recordPingSent(140000);

        self::assertFalse($liveness->isCloseDue(599999));
        self::assertTrue($liveness->isCloseDue(600000));
    }

    public function testOnceTheCloseIsDueNoPingAndNoTransitionIsOffered(): void
    {
        $liveness = new WebSocketLiveness(WebSocketLivenessConfig::fromEnv(), 0);

        self::assertTrue($liveness->isPingDue(599999));
        self::assertTrue($liveness->isProbablyOfflineTransitionDue(599999));
        self::assertFalse($liveness->isPingDue(600000));
        self::assertFalse($liveness->isProbablyOfflineTransitionDue(600000));
        self::assertFalse($liveness->probablyOffline);
    }

    public function testAFrameBetweenPingsClearsTheFlagAndStartsTheIdleSpanAgain(): void
    {
        $liveness = new WebSocketLiveness(WebSocketLivenessConfig::fromEnv(), 0);
        $liveness->recordPingSent(40000);
        $liveness->settleProbablyOffline(130000);
        self::assertTrue($liveness->probablyOffline);

        $liveness->recordReceived(150000);
        self::assertTrue($liveness->isProbablyOfflineTransitionDue(150000));
        $liveness->settleProbablyOffline(150000);
        self::assertFalse($liveness->probablyOffline);

        self::assertFalse($liveness->isPingDue(189999));
        self::assertTrue($liveness->isPingDue(190000));
        $liveness->recordPingSent(190000);
        self::assertFalse($liveness->isPingDue(239999));
        self::assertTrue($liveness->isPingDue(240000));

        self::assertFalse($liveness->isProbablyOfflineTransitionDue(269999));
        self::assertTrue($liveness->isProbablyOfflineTransitionDue(270000));
        self::assertFalse($liveness->isCloseDue(749999));
        self::assertTrue($liveness->isCloseDue(750000));
    }

    public function testALateTickOwesOnePingAndCountsTheNextFromItsActualSend(): void
    {
        $liveness = new WebSocketLiveness(WebSocketLivenessConfig::fromEnv(), 0);

        self::assertTrue($liveness->isPingDue(200000));
        $liveness->recordPingSent(200000);
        self::assertFalse($liveness->isPingDue(200000));
        self::assertFalse($liveness->isPingDue(249999));
        self::assertTrue($liveness->isPingDue(250000));
    }

    public function testTheEnvironmentOverridesEverySpanAndAnEmptyValueKeepsTheDefault(): void
    {
        putenv(EnvConstants::WEBSOCKET_PING_IDLE_MS->name . '=1000');
        putenv(EnvConstants::WEBSOCKET_PING_INTERVAL_MS->name . '=2000');
        putenv(EnvConstants::WEBSOCKET_PROBABLY_OFFLINE_MS->name . '=3000');
        putenv(EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS->name . '=4000');
        Hilos::$env = new EnvAccessor();
        $config = WebSocketLivenessConfig::fromEnv();
        self::assertSame(
            [1000, 2000, 3000, 4000],
            [$config->pingIdleMs, $config->pingIntervalMs, $config->probablyOfflineMs, $config->closeTimeoutMs],
        );

        putenv(EnvConstants::WEBSOCKET_PING_INTERVAL_MS->name . '=');
        putenv(EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS->name . '=');
        Hilos::$env = new EnvAccessor();
        $config = WebSocketLivenessConfig::fromEnv();
        self::assertSame(
            [1000, 50000, 3000, 600000],
            [$config->pingIdleMs, $config->pingIntervalMs, $config->probablyOfflineMs, $config->closeTimeoutMs],
        );
    }

    /** @return iterable<string, array{int, int, int, int}> Idle, interval, probably-offline and close spans */
    public static function invalidSpans(): iterable
    {
        yield 'zero idle' => [0, 50000, 120000, 600000];
        yield 'zero interval' => [40000, 0, 120000, 600000];
        yield 'negative interval' => [40000, -1, 120000, 600000];
        yield 'idle reaches probably offline' => [120000, 50000, 120000, 600000];
        yield 'probably offline reaches close' => [40000, 50000, 600000, 600000];
        yield 'close before idle' => [40000, 50000, 120000, 30000];
    }

    #[DataProvider('invalidSpans')]
    public function testSpansThatCannotKeepTheirOrderAreRefused(int $idle, int $interval, int $probablyOffline, int $close): void
    {
        self::setSpans($idle, $interval, $probablyOffline, $close);

        $this->expectException(EnvInvalidValueException::class);
        WebSocketLivenessConfig::fromEnv();
    }

    public function testInvalidSpansRefuseTheConnectionBeforeAnyHandshakeByteOrSignalLeaves(): void
    {
        self::setSpans(40000, 50000, 40000, 600000);
        [$client, $browser] = $this->open();
        $this->writeFromBrowser($browser, self::upgradeRequest());

        try {
            $client->read();
            self::fail('A connection was served under spans out of order');
        } catch (EnvInvalidValueException) {
        }

        self::assertFalse($client->hasPendingWrite());
        self::assertSame('', self::drain($browser));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testASilentBrowserIsPingedAtFortyAndNinetySecondsAndDroppedAtSixHundred(): void
    {
        [$client, $browser] = $this->handshaken();

        self::assertSame([], $this->tickAt($client, $browser, 39999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));
        self::assertSame([], $this->tickAt($client, $browser, 89999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 90000));
        self::assertSame([], $this->tickAt($client, $browser, 139999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 140000));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 599999));
        self::assertSame([$client], $this->server->heldClients());
        self::assertSame([true], $this->toldLiveness($client));

        self::assertSame([], $this->tickAt($client, $browser, 600000));
        self::assertSame([], $this->server->heldClients());
        $close = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalTypeConstants::CONNECTION_CLOSE, $close?->signalType->getType());
        self::assertInstanceOf(WebSocketCloseSignalDTO::class, $close->data);
        self::assertSame($client->acceptKey, $close->data->acceptKey);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAnRfcPongFromTheBrowserStartsTheIdleSpanAgainAndTheIntervalFollowsIt(): void
    {
        [$client, $browser] = $this->handshaken();
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));

        $this->writeFromBrowser($browser, self::maskedFrame(0x8A, ''));
        self::assertSame([], $this->tickAt($client, $browser, 60000));
        self::assertSame([], $this->tickAt($client, $browser, 99999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 100000));
        self::assertSame([], $this->tickAt($client, $browser, 149999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 150000));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 659999));
        self::assertSame([$client], $this->server->heldClients());
        self::assertSame([], $this->tickAt($client, $browser, 660000));
        self::assertSame([], $this->server->heldClients());
    }

    /** @return iterable<string, array{string, list<array{opcode: int, text: string}>}> Browser bytes and the transport reply */
    public static function wholeFrames(): iterable
    {
        yield 'RFC ping' => [self::maskedFrame(0x89, 'p'), [['opcode' => 10, 'text' => 'p']]];
        yield 'text ping of the page' => [self::maskedFrame(0x81, 'ping'), [['opcode' => 10, 'text' => '']]];
        yield 'application text' => [self::maskedFrame(0x81, '{"type":"group_subscribe","group":"g"}'), []];
        yield 'binary' => [self::maskedFrame(0x82, "\x00\xff"), []];
        yield 'first fragment of a text' => [self::maskedFrame(0x01, '{"type":"group_'), []];
    }

    /**
     * @param string $bytes Browser frame
     * @param list<array{opcode: int, text: string}> $reply What the master answers it with on the transport
     */
    #[DataProvider('wholeFrames')]
    public function testEveryWholeFrameCountsAsTheBrowserSpeaking(string $bytes, array $reply): void
    {
        [$client, $browser] = $this->handshaken();
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));

        $this->writeFromBrowser($browser, $bytes);
        self::assertSame($reply, $this->tickAt($client, $browser, 70000));
        self::assertSame([], $this->tickAt($client, $browser, 90000));
        self::assertSame([], $this->tickAt($client, $browser, 109999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 110000));
    }

    public function testAContinuationFrameCountsOnItsOwnArrival(): void
    {
        [$client, $browser] = $this->handshaken();
        $this->writeFromBrowser($browser, self::maskedFrame(0x01, '{"type":"group_'));
        self::assertSame([], $this->tickAt($client, $browser, 50000));

        $this->writeFromBrowser($browser, self::maskedFrame(0x80, 'subscribe","group":"g"}'));
        self::assertSame([], $this->tickAt($client, $browser, 80000));
        self::assertSame([], $this->tickAt($client, $browser, 90000));
        self::assertSame([], $this->tickAt($client, $browser, 119999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 120000));
        self::assertSame(SignalTypeConstants::GROUP_SUBSCRIBE, Hilos::$sr->getNextQueuedSignal()?->signalType->getType());
    }

    public function testPartialBytesCountOnlyOnceTheFrameIsWhole(): void
    {
        [$client, $browser] = $this->handshaken();
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));
        $pong = self::maskedFrame(0x8A, '');

        $this->writeFromBrowser($browser, substr($pong, 0, 3));
        self::assertSame([], $this->tickAt($client, $browser, 60000));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 90000));

        $this->writeFromBrowser($browser, substr($pong, 3));
        self::assertSame([], $this->tickAt($client, $browser, 95000));
        self::assertSame([], $this->tickAt($client, $browser, 134999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 135000));
    }

    public function testAMalformedFrameDoesNotCount(): void
    {
        [$client, $browser] = $this->handshaken();
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));

        $this->writeFromBrowser($browser, self::maskedFrame(0x83, 'reserved'));
        $client->now = 60000;
        try {
            $client->read();
            self::fail('A frame with a reserved opcode was accepted');
        } catch (ReservedOpcodeException) {
        }

        self::assertSame([self::PING], $this->tickAt($client, $browser, 90000));
    }

    public function testOutgoingTrafficDoesNotCountAndThePingLeavesTheDeliveryQueueAlone(): void
    {
        [$client, $browser] = $this->handshaken();
        $client->now = 39800;
        $client->sendFrame('{"type":"toast","data":1}');
        self::assertSame([['opcode' => 1, 'text' => '{"type":"toast","data":1}']], $this->tickAt($client, $browser, 39800));

        $client->now = 40000;
        $client->sendFrame('{"type":"toast","data":2}');
        self::assertSame([self::PING], $this->tickAt($client, $browser, 40000));
        self::assertTrue($client->hasPendingWrite());
        self::assertSame([['opcode' => 1, 'text' => '{"type":"toast","data":2}']], $this->tickAt($client, $browser, 40800));
        self::assertSame([], $this->tickAt($client, $browser, 89999));
        self::assertSame([self::PING], $this->tickAt($client, $browser, 90000));
    }

    public function testNoPingBeforeTheHandshakeAndNoneOnceTheSocketIsToClose(): void
    {
        [$client, $browser] = $this->open();
        $this->writeFromBrowser($browser, "GET /ws HTTP/1.1\r\nHost: localhost\r\n");
        self::assertSame('', $this->rawTickAt($client, $browser, 40000));
        self::assertSame('', $this->rawTickAt($client, $browser, 600000));
        self::assertSame([$client], $this->server->heldClients());

        [$closing, $closingBrowser] = $this->handshaken();
        self::assertSame([], $this->tickAt($closing, $closingBrowser, 10000));
        $closing->markShouldClose();
        $closing->now = 40000;
        $closing->onTick();
        self::assertFalse($closing->hasPendingWrite());
    }

    public function testEachTransitionIsToldToTheOwnerExactlyOnceWithTheStateItMovedInto(): void
    {
        [$client, $browser] = $this->handshaken();

        $this->tickAt($client, $browser, 119999);
        self::assertSame([], $this->toldLiveness($client));
        $this->tickAt($client, $browser, 120000);
        self::assertSame([true], $this->toldLiveness($client));
        $this->tickAt($client, $browser, 120001);
        self::assertSame([], $this->toldLiveness($client));

        $this->writeFromBrowser($browser, self::maskedFrame(0x8A, ''));
        $this->tickAt($client, $browser, 130000);
        self::assertSame([false], $this->toldLiveness($client));
        $this->tickAt($client, $browser, 130001);
        self::assertSame([], $this->toldLiveness($client));
    }

    public function testASocketMarkedToCloseTellsTheOwnerNothing(): void
    {
        [$client] = $this->handshaken();
        $client->markShouldClose();

        $client->now = 120000;
        $client->onTick();

        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testSilenceFirstSeenAtTheCloseDeadlineClosesTheSocketWithoutATransition(): void
    {
        [$client, $browser] = $this->handshaken();

        $this->tickAt($client, $browser, 600000);

        self::assertSame([], $this->server->heldClients());
        self::assertSame(SignalTypeConstants::CONNECTION_CLOSE, Hilos::$sr->getNextQueuedSignal()?->signalType->getType());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testWithoutAnyCopyOfTheConnectionsTheCheckRepeatsNothing(): void
    {
        [$client, $browser] = $this->handshaken();
        $this->tickAt($client, $browser, 50000);
        $this->tickAt($client, $browser, 100000);
        $this->tickAt($client, $browser, 120000);
        self::assertSame([true], $this->toldLiveness($client));

        $this->tickAt($client, $browser, 150000);
        Hilos::$rt = new EmptyTestRtContext();
        Hilos::$rt->configure();
        $this->tickAt($client, $browser, 200000);
        $this->tickAt($client, $browser, 250000);

        self::assertSame([], $this->toldLiveness($client));
    }

    /** @return array{LivenessTestClient, Socket} Connection held by the server, its browser end silent so far */
    private function open(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new LivenessTestClient($pair[0]);
        $this->server->seedClient($client);
        $this->browsers[] = $pair[1];

        return [$client, $pair[1]];
    }

    /** @return array{LivenessTestClient, Socket} Connection whose handshake completed at moment 0, its 101 and welcome read */
    private function handshaken(): array
    {
        [$client, $browser] = $this->open();
        $this->writeFromBrowser($browser, self::upgradeRequest());
        $bytes = $this->rawTickAt($client, $browser, 0);
        $headEnd = strpos($bytes, "\r\n\r\n");
        self::assertNotFalse($headEnd);
        self::assertStringStartsWith('HTTP/1.1 101', $bytes);
        $welcome = self::frames(substr($bytes, $headEnd + 4));
        self::assertCount(1, $welcome);
        self::assertSame(1, $welcome[0]['opcode']);
        self::assertSame(SignalTypeConstants::HANDSHAKE, Hilos::$sr->getNextQueuedSignal()?->signalType->getType());

        return [$client, $browser];
    }

    /** @return list<array{opcode: int, text: string}> Frames the server's tick at this moment wrote to the browser */
    private function tickAt(LivenessTestClient $client, Socket $browser, int $nowMs): array
    {
        return self::frames($this->rawTickAt($client, $browser, $nowMs));
    }

    /** @return string Bytes the server's tick at this moment wrote to the browser */
    private function rawTickAt(LivenessTestClient $client, Socket $browser, int $nowMs): string
    {
        $client->now = $nowMs;
        $this->server->onTick();

        return self::drain($browser);
    }

    /**
     * Drains the queue, which must hold nothing but this socket's liveness transitions.
     *
     * @return list<bool> State each queued transition tells the owner, in order
     */
    private function toldLiveness(LivenessTestClient $client): array
    {
        $states = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(SignalSource::WEBSOCKET, $signal->signalSource->getSource());
            self::assertSame(SignalTypeConstants::CONNECTION_LIVENESS, $signal->signalType->getType());
            self::assertSame(SignalTypeConstants::CONNECTION_LIVENESS, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketLivenessSignalDTO::class, $signal->data);
            self::assertSame($client->acceptKey, $signal->data->acceptKey);
            $states[] = $signal->data->probablyOffline;
        }

        return $states;
    }

    private function writeFromBrowser(Socket $browser, string $bytes): void
    {
        self::assertSame(strlen($bytes), socket_write($browser, $bytes));
    }

    private static function drain(Socket $browser): string
    {
        $bytes = '';
        $transport = new PlainSocketTransport($browser);
        while (($chunk = $transport->read(65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @return list<array{opcode: int, text: string}> Complete unmasked RFC frames in the given bytes */
    private static function frames(string $bytes): array
    {
        $frames = [];
        while ($bytes !== '') {
            self::assertGreaterThanOrEqual(2, strlen($bytes));
            $length = ord($bytes[1]);
            $offset = 2;
            if ($length === 126) {
                $length = unpack('n', substr($bytes, 2, 2))[1];
                $offset = 4;
            }
            self::assertGreaterThanOrEqual($offset + $length, strlen($bytes));
            $frames[] = ['opcode' => ord($bytes[0]) & 15, 'text' => substr($bytes, $offset, $length)];
            $bytes = substr($bytes, $offset + $length);
        }

        return $frames;
    }

    /**
     * @param int $firstByte FIN bit and opcode
     * @param string $payload Payload shorter than 126 bytes
     * @return string The frame as a browser sends it, masked
     */
    private static function maskedFrame(int $firstByte, string $payload): string
    {
        $mask = 'mask';

        return chr($firstByte) . chr(0x80 | strlen($payload)) . $mask
            . ($payload ^ substr(str_repeat($mask, intdiv(strlen($payload), 4) + 1), 0, strlen($payload)));
    }

    private static function upgradeRequest(): string
    {
        return "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
    }

    private static function setSpans(int $idle, int $interval, int $probablyOffline, int $close): void
    {
        putenv(EnvConstants::WEBSOCKET_PING_IDLE_MS->name . "={$idle}");
        putenv(EnvConstants::WEBSOCKET_PING_INTERVAL_MS->name . "={$interval}");
        putenv(EnvConstants::WEBSOCKET_PROBABLY_OFFLINE_MS->name . "={$probablyOffline}");
        putenv(EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS->name . "={$close}");
        Hilos::$env = new EnvAccessor();
    }

    private static function clearSpans(): void
    {
        putenv(EnvConstants::WEBSOCKET_PING_IDLE_MS->name);
        putenv(EnvConstants::WEBSOCKET_PING_INTERVAL_MS->name);
        putenv(EnvConstants::WEBSOCKET_PROBABLY_OFFLINE_MS->name);
        putenv(EnvConstants::WEBSOCKET_CLOSE_TIMEOUT_MS->name);
    }
}

/** Server whose browsers are handed to it by the test, ticked by its real loop and dropped through its real door. */
final class LivenessTestServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @param LivenessTestClient $client Browser this server now holds */
    public function seedClient(LivenessTestClient $client): void
    {
        $this->clients[] = $client;
    }

    /** @return list<WebSocketClientInterface> Browsers the server still holds */
    public function heldClients(): array
    {
        return array_values($this->clients);
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface Never returned
     * @throws SocketException Always, since this test does not accept clients
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new SocketException('the liveness test accepts no clients');
    }
}
