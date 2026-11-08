<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Files\Upload\UploadMemoryInput;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\DTO\WebSocketLivenessSignalDTO;
use Hilos\Socket\WebSocket\Liveness\WebSocketLiveness;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Actual native protocol waits and guarded socket portions, without a server sweep or expiry sleep. */
final class WebSocketClientEventLoopTest extends TestCase
{
    private const string UPGRADE = "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: MDEyMzQ1Njc4OWFiY2RlZg==\r\nSec-WebSocket-Version: 13\r\n\r\n";
    private const string PONG = "\x8a\x80mask";

    private EventLoop $loop;
    private NativeWebSocketTestServer $server;
    private NativeWebSocketTestManager $manager;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    /**
     * @var list<NativeWebSocketTestClient> Exact accepted objects retired before the native base
     */
    private array $clients = [];
    /**
     * @var list<Socket> Browser ends owned by this test
     */
    private array $peers = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        $this->loop = new EventLoop();
        $this->server = new NativeWebSocketTestServer('127.0.0.1', 0);
        // The test calls the real connection guard, without booting the daemon or installing handlers.
        $this->manager = new ReflectionClass(NativeWebSocketTestManager::class)->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->loop->close();
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
    }

    public function testAcceptedPlainUpgradeStartsLifeBeforeOutputDrainAndUnrelatedWorkKeepsTheDeadline(): void
    {
        [$client, $peer] = $this->open(false);
        $timer = $this->timer($client);
        self::assertNull($timer->getDeadline());
        socket_write($peer, self::UPGRADE);
        $client->read();
        self::assertTrue($client->hasPendingWrite(), 'The 101 is queued before its physical drain');
        $life = $this->life($client);
        $heard = self::get($life, 'lastReceivedAtMs');
        self::assertSame($client->now, $heard);
        self::assertSame(($heard + 40000) / 1000, $timer->getDeadline());
        $generation = self::get($timer, 'generation');
        $client->sendFrameBinary('outgoing');
        [$other] = $this->open();
        $other->sendFrame('{"type":"other"}');
        $this->pump();
        self::assertSame($heard, self::get($life, 'lastReceivedAtMs'));
        self::assertSame(($heard + 40000) / 1000, $timer->getDeadline());
        self::assertSame($generation, self::get($timer, 'generation'));
        $client->read();
        self::assertSame($heard, self::get($life, 'lastReceivedAtMs'), 'EAGAIN does not renew life');
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testNativeDueCallbackOnlyCuesOneLatePingAndTheNextSendUsesActualNow(): void
    {
        [$client, $peer] = $this->open();
        $now = $client->now;
        $life = $this->life($client);
        self::set($life, 'lastReceivedAtMs', $now - 40000);
        self::set($life, 'nextPingAtMs', $now - 10000);
        self::set($life, 'nextReconcileAtMs', $now + 80000);
        $this->arm($client);
        self::assertSame($now / 1000, $this->timer($client)->getDeadline());
        $portions = $this->manager->portions;
        $this->loop->tick();
        self::assertSame($portions, $this->manager->portions);
        self::assertSame('', $this->bytes($peer), 'The native callback sends nothing');
        $this->loop->tick();
        self::assertSame("\x89\x00", $this->bytes($peer));
        self::assertSame($now + 50000, self::get($life, 'nextPingAtMs'));
        self::assertSame(($now + 50000) / 1000, $this->timer($client)->getDeadline());
        $this->pump();
        self::assertSame('', $this->bytes($peer), 'A late pass sends one ping');
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testSettledDoubtSleepsUntilRealWorkAndWholePongClearsItAtOnce(): void
    {
        [$client, $peer] = $this->open();
        $now = $client->now;
        $life = $this->life($client);
        self::set($life, 'lastReceivedAtMs', $now - 120000);
        self::set($life, 'nextPingAtMs', $now + 10000);
        self::set($life, 'nextReconcileAtMs', $now + 20000);
        $this->arm($client);
        $this->pump();
        self::assertSame([true], $this->transitions());
        self::assertSame(($now + 10000) / 1000, $this->timer($client)->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
        socket_write($peer, self::PONG);
        $this->pump();
        self::assertSame([false], $this->transitions());
        self::assertSame($now, self::get($life, 'lastReceivedAtMs'));
        self::assertSame($now + 20000, self::get($life, 'nextReconcileAtMs'));
        self::assertSame(($now + 20000) / 1000, $this->timer($client)->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /**
     * @return iterable<string, array{string, bool, bool}> Physical frame, stopped input, expected terminal verdict
     */
    public static function dueInputs(): iterable
    {
        yield 'whole pong saves due socket' => [self::PONG, false, false];
        yield 'partial pong does not' => [substr(self::PONG, 0, -1), false, true];
        yield 'discarded whole pong does not' => [self::PONG, true, true];
        yield 'malformed frame reaches the guard' => ["\x8b\x00", false, true];
    }

    /**
     * @param string $input Wire input
     * @param bool $stopped Discard instead of parse
     * @param bool $closed Expected close
     */
    #[DataProvider('dueInputs')]
    public function testDuePortionReadsBeforeSilenceAndClosesExactlyOnce(string $input, bool $stopped, bool $closed): void
    {
        [$client, $peer] = $this->open();
        $life = $this->life($client);
        $heard = $client->now - 600000;
        self::set($life, 'lastReceivedAtMs', $heard);
        self::set($life, 'nextPingAtMs', $client->now - 1);
        $this->arm($client);
        $this->loop->tick();
        self::assertFalse($client->shouldClose(), 'Timer activation alone is not a terminal decision');
        if ($stopped) {
            $client->stopReading();
        }
        socket_write($peer, $input);
        $this->pump();
        self::assertSame($closed, $client->getSocket() === null);
        self::assertSame($closed ? 1 : 0, $client->closes);
        self::assertSame($closed ? $heard : $client->now, self::get($life, 'lastReceivedAtMs'));
        if ($closed) {
            self::assertNull($client->getSocket());
            self::assertNull(self::getClient($client, 'socketWorkTimer'));
            self::assertSame('', $this->bytes($peer), 'Close wins over overdue ping');
            self::assertSame([], $this->transitions());
            $client->close();
            self::assertSame(1, $client->closes);
        }
    }

    public function testBufferedSixtyFifthPhysicalFrameRenewsWithoutNewTransportBytes(): void
    {
        [$client, $peer] = $this->open();
        $life = $this->life($client);
        socket_write($peer, str_repeat(self::PONG, 65));
        $client->read();
        self::assertTrue(new ReflectionProperty(AbstractClient::class, 'readBufferReady')->getValue($client));
        self::set($life, 'lastReceivedAtMs', $client->now - 120000);
        $life->settleProbablyOffline($client->now);
        $this->pump();
        self::assertSame($client->now, self::get($life, 'lastReceivedAtMs'));
        self::assertSame([false], $this->transitions());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testFragmentedPhysicalFramesRenewBeforeTheApplicationMessageCompletes(): void
    {
        [$client, $peer] = $this->open();
        $life = $this->life($client);
        self::set($life, 'lastReceivedAtMs', $client->now - 120000);
        $life->settleProbablyOffline($client->now);
        socket_write($peer, self::masked(0x01, '{"type":"group_'));
        $this->pump();
        self::assertSame([false], $this->transitions());
        self::assertSame($client->now, self::get($life, 'lastReceivedAtMs'));
        $client->now++;
        socket_write($peer, self::masked(0x80, 'subscribe","group":"g"}'));
        $this->pump();
        self::assertSame($client->now, self::get($life, 'lastReceivedAtMs'));
        self::assertFalse($client->shouldClose());
    }

    public function testDeliveryWaitKeepsItsOpenedDeadlineAcrossTrafficAndSettingReplacement(): void
    {
        [$client, $peer] = $this->open();
        $origin = $client->now;
        $client->sendFrame('{"type":"first"}');
        $this->pump();
        self::assertSame("\x81\x10{\"type\":\"first\"}", $this->bytes($peer));
        $client->sendFrame('{"type":"later"}');
        $timer = $this->timer($client);
        self::assertSame(($origin + 1000) / 1000, $timer->getDeadline());
        $generation = self::get($timer, 'generation');
        $mirror = new DeliverySettingsMirror();
        $client->setDeliverySettingsMirror($mirror);
        $mirror->onDeliverySettings($this->createStub(WorkerClient::class), 2000);
        $client->setDeliverySettingsMirror($mirror);
        socket_write($peer, self::PONG);
        $this->pump();
        self::assertSame('', $this->bytes($peer));
        self::assertSame(($origin + 1000) / 1000, $timer->getDeadline());
        self::assertSame($generation, self::get($timer, 'generation'));
        self::assertFalse($this->loop->hasReadyWork());
        // Age only the captured observation, preserving the production span and native clock origin.
        $queue = self::getClient($client, 'deliveryQueue');
        self::set($queue, 'nextDueAt', $origin - 1);
        $this->arm($client);
        $this->pump();
        self::assertSame("\x81\x10{\"type\":\"later\"}", $this->bytes($peer));
        self::assertSame($origin + 2000, $queue->getNextDueAt());
        $client->sendFrame('{"type":"after"}');
        self::assertSame(($origin + 2000) / 1000, $timer->getDeadline());
    }

    public function testHeldCompletionCancelAndUrgentReplyScheduleOnlyTheCurrentAttempt(): void
    {
        [$client, $peer] = $this->open();
        $client->sendFrame('{"type":"first"}');
        $this->pump();
        $this->bytes($peer);
        $client->sendFrame('{"type":"waiting"}');
        self::assertSame(($client->now + 1000) / 1000, $this->timer($client)->getDeadline());
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        $old = $client->pendingPageSubscribe();
        $this->pump();
        self::assertSame('', $this->bytes($peer));
        self::assertSame(($client->now + 40000) / 1000, $this->timer($client)->getDeadline());
        $client->acceptJson('{"type":"page_subscribe","page":"q"}');
        $current = $client->pendingPageSubscribe();
        $client->sendFrame('{"type":"held"}');
        $client->completeDelivery('p', $old->deliveryId);
        $client->completeDelivery('wrong', $current->deliveryId);
        $this->pump();
        self::assertSame('', $this->bytes($peer));
        self::assertFalse($this->loop->hasReadyWork());
        $client->completeDelivery('q', $current->deliveryId);
        $this->pump();
        self::assertSame("\x81\x0f{\"type\":\"held\"}", $this->bytes($peer));
        $client->completeDelivery('q', $current->deliveryId);
        $this->pump();
        self::assertFalse($this->loop->hasReadyWork());
        $client->acceptJson('{"type":"page_update_subscription","page":"q"}');
        $client->cancelDeliveries('q');
        $client->sendFrame('{"type":"reply"}');
        $this->pump();
        self::assertSame("\x81\x10{\"type\":\"reply\"}", $this->bytes($peer));
        $client->acceptJson('{"type":"page_subscribe","page":"held"}');
        $client->sendFrame('{"type":"incomplete"}');
        $client->closeOnceWritten();
        $this->pump();
        self::assertTrue($client->shouldClose());
        self::assertSame('', $this->bytes($peer), 'Force cannot release an incomplete subscription');
    }

    public function testActivatedAndEscapedOldCallbackCannotCueAReboundOrReplacementSocket(): void
    {
        [$client] = $this->open();
        $timer = $this->timer($client);
        $escaped = self::get($timer, 'callback');
        $ticket = self::get($timer, 'ready');
        $life = $this->life($client);
        self::set($life, 'nextPingAtMs', $client->now - 1);
        $this->arm($client);
        $this->loop->tick();
        $life->recordReceived($client->now);
        $this->bind($client);
        $this->pump();
        $calls = $this->manager->portions;
        $deadline = $this->timer($client)->getDeadline();
        $escaped();
        $ticket->markReady();
        $this->pump();
        self::assertTrue($ticket->isClosed());
        self::assertSame($calls, $this->manager->portions);
        self::assertSame($deadline, $this->timer($client)->getDeadline());
        [$replacement] = $this->open();
        $replacement->acceptKey = $client->acceptKey;
        $client->close();
        $calls = $this->manager->portions;
        $escaped();
        $this->pump();
        self::assertSame($calls, $this->manager->portions);
        self::assertFalse($replacement->shouldClose());
        $weak = WeakReference::create($client);
        $this->server->removeClient($client);
        $this->clients = array_values(array_filter($this->clients, static fn($entry): bool => $entry !== $client));
        unset($client);
        self::assertNull($weak->get(), 'Escaped timer callback cannot retain its accepted object');
    }

    public function testCapturedPacketControlAndClosingPrefixAdvanceWithoutTheServerSweep(): void
    {
        [$client, $peer] = $this->open();
        $json = [];
        for ($index = 0; $index < 130; ++$index) {
            $json[] = '{"type":"event","data":' . $index . '}';
            $client->sendFrame($json[$index]);
        }
        $client->write();
        self::assertSame('', $this->bytes($peer), 'One 64-step portion cannot publish a partial packet');
        $client->sendFrameBinary('binary');
        socket_write($peer, self::masked(0x89, 'probe'));
        // Handle the control before admitting the closing prefix, through the real guarded reader.
        $client->read();
        $client->sendFrame('{"type":"last"}');
        $client->closeOnceWritten();
        $client->sendFrame('{"type":"outside"}');
        $this->pump();
        $batch = '{"type":"signal_batch","data":{"frames":[' . implode(',', $json) . ']}}';
        self::assertSame(
            "\x81\x7e" . pack('n', strlen($batch)) . $batch
                . "\x82\x06binary\x8a\x05probe\x81\x0f{\"type\":\"last\"}",
            $this->bytes($peer),
        );
        self::assertSame(1, $client->closes);
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testBlockedOutputWaitsForNativeDrainAndLeavesProtocolWaitingIdle(): void
    {
        [$client, $peer] = $this->open();
        socket_set_option($client->getSocket(), SOL_SOCKET, SO_SNDBUF, 4096);
        $client->sendFrameBinary(str_repeat('x', 131072));
        $this->pump();
        self::assertTrue($client->hasPendingWrite());
        self::assertFalse($this->loop->hasReadyWork());
        $portions = $this->manager->portions;
        $deadline = $this->timer($client)->getDeadline();
        $this->pump();
        self::assertSame($portions, $this->manager->portions, 'Blocked native output cannot spin owner work');
        self::assertSame($deadline, $this->timer($client)->getDeadline());
        $client->closeOnceWritten();
        $wire = '';
        for ($pass = 0; $pass < 128 && !$client->shouldClose(); ++$pass) {
            $wire .= $this->bytes($peer);
            $this->loop->tick();
        }
        $wire .= $this->bytes($peer);
        self::assertSame("\x82\x7f" . pack('J', 131072) . str_repeat('x', 131072), $wire);
        self::assertSame(1, $client->closes);
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testEofAndActivatedTimerRetireTogetherWithoutASecondClose(): void
    {
        [$client, $peer] = $this->open();
        $pool = new UploadMemoryPool(8, 'socket-generation');
        $pool->request($client->acceptKey, 'file', 8);
        $reservation = $pool->reserveNext();
        $input = new UploadMemoryInput($pool, $client->acceptKey);
        $input->allow($pool->publish($reservation->id));
        $sink = $this->createMock(UploadMemoryPoolSink::class);
        $sink->method('getUploadInput')->willReturn($input);
        $sink->expects(self::once())->method('onUploadConnectionClosed')->with($client->acceptKey)
            ->willReturnCallback(static function () use ($client, $pool): void {
                self::assertSame('', new ReflectionProperty(AbstractClient::class, 'readBuffer')->getValue($client));
                self::assertSame(0, $pool->getUsedBytes(), 'Deleted partial input and unused permission are released before close notification');
            });
        $client->setUploadMemoryPoolSink($sink);
        $partial = substr(self::masked(0x82, "\x04fileabcdefgh"), 0, -2);
        socket_write($peer, $partial);
        $this->pump();
        self::assertSame(8, $pool->getUsedBytes());
        $timer = $this->timer($client);
        $escaped = self::get($timer, 'callback');
        self::set($this->life($client), 'nextPingAtMs', $client->now - 1);
        $this->arm($client);
        $this->loop->tick();
        socket_shutdown($peer, 1);
        $this->pump();
        $escaped();
        $client->close();
        self::assertSame(1, $client->closes);
        self::assertSame(0, $pool->getUsedBytes());
        self::assertNull($timer->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertSame(0, $this->loop->registeredCount());
    }

    public function testActualCommandManagerMatchesEverySessionSocketAndImmediatelyCuesOutputAndClose(): void
    {
        [$first, $peer] = $this->open();
        [$second] = $this->open();
        [$other] = $this->open();
        $hash = hash('sha256', 'browser-token');
        foreach ([$first, $second] as $client) {
            new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($client, $hash);
        }
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($this->manager, [$this->server]);
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $this->peers[] = $pair[1];
        $server = new CommandServer('127.0.0.1', 0);
        $server->setConnectionLivenessTestDoor($this->manager);
        $command = new CommandClient($pair[0], $server);
        try {
            foreach (['probably-offline', 'close'] as $moment) {
                $request = json_encode([
                    CommandConstants::FIELD_CORRELATION_ID => 'native',
                    CommandConstants::FIELD_COMMAND => CliCommands::CONNECTION_TEST_LIVENESS,
                    CommandConstants::FIELD_PAYLOAD => [
                        CommandConstants::FIELD_SESSION_TOKEN => 'browser-token', CommandConstants::FIELD_MOMENT => $moment,
                    ],
                ]) . "\n";
                socket_write($pair[1], $request);
                $command->read();
                $command->write();
                $reply = json_decode($this->bytes($pair[1]), true);
                self::assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS]);
                self::assertSame([CommandConstants::FIELD_SOCKETS => 2], $reply[CommandConstants::FIELD_PAYLOAD]);
                self::assertSame($moment === 'close', $first->shouldClose(), 'Business runs synchronously before count reply');
                self::assertSame($moment === 'close', $second->shouldClose());
                self::assertFalse($other->shouldClose());
                $this->pump();
                if ($moment === 'probably-offline') {
                    self::assertSame([true, true], $this->transitions());
                    self::assertSame("\x89\x00", $this->bytes($peer));
                    self::assertNotNull($this->timer($first)->getDeadline());
                }
            }
            self::assertSame(1, $first->closes);
            self::assertSame(1, $second->closes);
            self::assertSame(0, $other->closes);
        } finally {
            $command->close();
        }
    }

    public function testTlsConnectedAndOutputDrainDoNotRenewLifeAndShareTheSameBase(): void
    {
        $fixture = new MutualTlsFixture();
        $authority = $fixture->issueAuthority('WebSocket deadlines CA');
        $exchange = new TlsTestExchange($fixture, $fixture->issueNode('server', $authority),
            $fixture->issueNode('client', $authority), null, $fixture->trustFile($authority));
        $client = new NativeWebSocketTestClient($exchange->sockets['accepting'], $exchange->transports['accepting']);
        $client->now = intdiv(hrtime(true), 1_000_000);
        $exchange->bindClient('accepting', $client);
        try {
            self::assertNull($this->timer($client)->getDeadline());
            self::assertSame(0, $exchange->loop->registeredCount(), 'TLS owns native buffer events, no raw watch');
            $exchange->handshake();
            self::assertNull($this->timer($client)->getDeadline());
            $exchange->transports['dialing']->write(self::UPGRADE);
            $exchange->runUntil(fn(): bool => str_contains($exchange->received['dialing'], 'HTTP/1.1 101'));
            $heard = self::get($this->life($client), 'lastReceivedAtMs');
            self::assertSame($client->now, $heard);
            self::assertSame(($heard + 40000) / 1000, $this->timer($client)->getDeadline());
            $client->sendFrameBinary('final');
            $client->closeOnceWritten();
            $client->write();
            self::assertTrue($client->hasPendingWrite(), 'TLS enqueue remains charged before drain');
            self::assertFalse($client->shouldClose());
            $exchange->runUntil(fn(): bool => $client->shouldClose() && str_ends_with($exchange->received['dialing'], 'final'));
            self::assertSame($heard, self::get($this->life($client), 'lastReceivedAtMs'));
            self::assertNull(self::getClient($client, 'socketWorkTimer'));
        } finally {
            $exchange->close();
            $fixture->close();
        }
    }

    /**
     * @param bool $upgrade Whether to complete a real HTTP upgrade
     * @return array{NativeWebSocketTestClient, Socket} Accepted client and browser
     */
    private function open(bool $upgrade = true): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new NativeWebSocketTestClient($pair[0]);
        $client->now = intdiv(hrtime(true), 1_000_000);
        $this->clients[] = $client;
        $this->peers[] = $pair[1];
        $this->server->seed($client);
        $this->bind($client);
        $this->pump();
        if ($upgrade) {
            $this->upgrade($client, $pair[1]);
            $this->bytes($pair[1]);
        }
        return [$client, $pair[1]];
    }

    /**
     * @param NativeWebSocketTestClient $client Exact accepted object
     */
    private function bind(NativeWebSocketTestClient $client): void
    {
        $client->attachEventLoop($this->loop, fn() => $this->manager->drive($this->server, $client));
    }

    /**
     * @param NativeWebSocketTestClient $client Accepting client
     * @param Socket $peer Browser end
     */
    private function upgrade(NativeWebSocketTestClient $client, Socket $peer): void
    {
        socket_write($peer, self::UPGRADE);
        $this->pump();
        self::assertNotNull($this->life($client));
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
    }

    /**
     * Only already-ready I/O/continuations are polled; no wait for a production expiry.
     */
    private function pump(): void
    {
        for ($pass = 0; $pass < 8; ++$pass) {
            $this->loop->tick();
        }
    }

    /**
     * @param WebSocketClient $client Socket whose private observations changed
     */
    private function arm(WebSocketClient $client): void
    {
        new ReflectionMethod(WebSocketClient::class, 'rearmSocketWork')->invoke($client);
    }

    /**
     * @param WebSocketClient $client Exact accepted object
     * @return EventLoopTimer Its socket-private native wait
     */
    private function timer(WebSocketClient $client): EventLoopTimer
    {
        return self::getClient($client, 'socketWorkTimer');
    }

    /**
     * @param WebSocketClient $client Upgraded socket
     * @return WebSocketLiveness Its original liveness observations
     */
    private function life(WebSocketClient $client): WebSocketLiveness
    {
        return self::getClient($client, 'liveness');
    }

    /**
     * @param WebSocketClient $client Socket owner
     * @param string $property Private field
     * @return mixed Current field value
     */
    private static function getClient(WebSocketClient $client, string $property): mixed
    {
        return new ReflectionProperty(WebSocketClient::class, $property)->getValue($client);
    }

    /**
     * @param object $owner State owner
     * @param string $property Private field
     * @return mixed Current field value
     */
    private static function get(object $owner, string $property): mixed
    {
        return new ReflectionProperty($owner, $property)->getValue($owner);
    }

    /**
     * @param object $owner Observation owner
     * @param string $property Field to age
     * @param int $value Changed monotonic observation
     */
    private static function set(object $owner, string $property, int $value): void
    {
        new ReflectionProperty($owner, $property)->setValue($owner, $value);
    }

    /**
     * @param int $firstByte FIN/opcode
     * @param string $payload Short physical payload
     * @return string Masked browser frame
     */
    private static function masked(int $firstByte, string $payload): string
    {
        $masked = '';
        for ($index = 0; $index < strlen($payload); ++$index) {
            $masked .= $payload[$index] ^ 'mask'[$index % 4];
        }
        return chr($firstByte) . chr(0x80 | strlen($payload)) . 'mask' . $masked;
    }

    /**
     * @param Socket $peer Nonblocking browser
     * @return string All currently available wire bytes
     */
    private function bytes(Socket $peer): string
    {
        $bytes = '';
        while (($part = @socket_read($peer, 65536)) !== false && $part !== '') {
            $bytes .= $part;
        }
        socket_clear_error($peer);
        return $bytes;
    }

    /**
     * @return list<bool> Liveness transitions, consuming unrelated setup signals
     */
    private function transitions(): array
    {
        $states = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketLivenessSignalDTO) {
                $states[] = $signal->data->probablyOffline;
            }
        }
        return $states;
    }
}

/** A real parser/handshake with a single process-monotonic test origin and counted terminal effects. */
final class NativeWebSocketTestClient extends WebSocketClient
{
    public int $now;
    public int $closes = 0;

    protected function deliveryNowMs(): int
    {
        return $this->now;
    }

    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /**
     * @param string $json Accepted application request
     */
    public function acceptJson(string $json): void
    {
        $this->onFrame($json);
    }

    /**
     * Counts the actual close while retaining framework input/debt/notification cleanup.
     */
    protected function onClose(): void
    {
        ++$this->closes;
        parent::onClose();
    }

    /**
     * @param array<string, string> $headers Unused headers
     * @param string $acceptKey Connection key
     * @param array<string, string> $cookies Unused cookies
     * @param ?string $clientIp Peer address
     * @param RequestQueryParams $queryParams Upgrade query
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

/** Holds actual accepted objects but never runs the blanket server tick. */
final class NativeWebSocketTestServer extends WebSocketServer
{
    /**
     * @param WebSocketClient $client Accepted object
     */
    public function seed(WebSocketClient $client): void
    {
        $this->addClient($client);
    }

    protected function onStart(): void
    {
    }

    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new SocketException('This fixture seeds accepted sockets directly');
    }
}

/** Invokes the production connection guard; no daemon boot, handler replacement or periodic scan. */
final class NativeWebSocketTestManager extends DaemonManager
{
    public int $portions = 0;

    /**
     * @param NativeWebSocketTestServer $server Accepting server
     * @param ClientInterface $client Exact accepted object
     */
    public function drive(NativeWebSocketTestServer $server, ClientInterface $client): void
    {
        ++$this->portions;
        $this->onClientRead($server, $client);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new SocketException('No agent manager is needed to drive one connection');
    }
}
