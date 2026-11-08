<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsSink;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

/** Same-process native loops expose parser yields and releases without waiting on a clock. */
final class ClientParserContinuationTest extends TestCase
{
    private EventLoop $loop;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private string|false $previousReadSize;

    /** @var list<AbstractClient> Clients whose bindings are released before their sockets */
    private array $clients = [];

    /** @var array<int, Socket> Socket ends owned by this fixture */
    private array $sockets = [];

    /** A long pipeline yields between requests and writes every body once in request order. */
    public function testHttpPipelineIsFiniteAndContinuesWithoutAnotherPeerWrite(): void
    {
        [$near, $peer] = $this->pair();
        $client = new HttpClient($near);
        $received = [];
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_POST, '/echo', static function (array $args) use (&$received): array {
            $received[] = $args['request'][HttpConstants::REQUEST_KEY_BODY];
            return ['body' => end($received)];
        });
        $client->setRouter($router);
        $this->bind($client);
        $wire = '';
        $expected = [];
        for ($index = 0; $index < 150; ++$index) {
            $body = 'request-' . $index;
            $expected[] = $body;
            $wire .= "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        }
        $this->send($peer, $wire);
        $this->loop->tick();
        $this->assertNotEmpty($received);
        $this->assertLessThan(150, count($received), 'one owner pass must not recurse through the pipeline');
        $responses = $this->drain($peer);
        for ($pass = 0; $pass < 200; ++$pass) {
            $this->loop->tick();
            $responses .= $this->drain($peer);
        }
        $this->assertSame($expected, $received);
        preg_match_all('/\{"body":"(request-\d+)"\}/', $responses, $matches);
        $this->assertSame($expected, $matches[1]);
        $this->assertSame(150, substr_count($responses, 'HTTP/1.1 200 OK'));
        $this->assertFalse($client->shouldClose());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A partial next body stops after the drain probe and resumes only when its tail arrives. */
    public function testHttpIncompleteBodyWaitsAndConnectionCloseStopsThePipeline(): void
    {
        [$near, $peer] = $this->pair();
        $client = new HttpClient($near);
        $this->bind($client);
        $this->send($peer, "GET / HTTP/1.1\r\n\r\nPOST / HTTP/1.1\r\nContent-Length: 4\r\nConnection: close\r\n\r\nab");
        $this->poll();
        $this->assertSame(1, substr_count($this->drain($peer), 'HTTP/1.1 200 OK'));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertFalse($client->shouldClose());
        $this->send($peer, "cdGET / HTTP/1.1\r\n\r\n");
        $this->poll();
        $this->assertSame(1, substr_count($this->drain($peer), 'HTTP/1.1 200 OK'));
        $this->assertTrue($client->shouldClose());
    }

    /** A blocked response gates parsing until real drain, then wakes the retained next request. */
    public function testHttpBackpressureResumesPipelineOnlyAfterResponseDrain(): void
    {
        [$near, $peer] = $this->pair();
        $this->assertTrue(socket_set_option($near, SOL_SOCKET, SO_SNDBUF, 4096));
        $client = new HttpClient($near);
        $router = new HttpRouter();
        $handled = [];
        $large = str_repeat('response', 16_384);
        $router->addRoute(HttpConstants::METHOD_POST, '/echo', static function (array $args) use (&$handled, $large): string {
            $body = $args['request'][HttpConstants::REQUEST_KEY_BODY];
            $handled[] = $body;
            return $body === 'first' ? $large : 'last';
        });
        $client->setRouter($router);
        $this->bind($client);
        $this->send($peer, "POST /echo HTTP/1.1\r\nContent-Length: 5\r\n\r\nfirst"
            . "POST /echo HTTP/1.1\r\nContent-Length: 4\r\nConnection: close\r\n\r\nlast");
        $this->poll();
        $this->assertSame(['first'], $handled);
        $this->assertTrue($client->hasPendingWrite());
        $this->assertFalse($this->loop->hasReadyWork());
        $responses = '';
        for ($pass = 0; $pass < 128; ++$pass) {
            $responses .= $this->drain($peer);
            $this->loop->tick();
        }
        $responses .= $this->drain($peer);
        $this->assertSame(['first', 'last'], $handled);
        $this->assertStringContainsString("\r\n\r\n" . $large . 'HTTP/1.1 200 OK', $responses);
        $this->assertStringEndsWith("\r\n\r\nlast", $responses);
        $this->assertSame(2, substr_count($responses, 'HTTP/1.1 200 OK'));
        $this->assertFalse($client->hasPendingWrite());
        $this->assertTrue($client->shouldClose());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** An EOF after a whole pipeline follows all runnable requests, including immediate responses. */
    public function testHttpEofFollowsAcceptedPipeline(): void
    {
        [$near, $peer] = $this->pair();
        $client = new HttpClient($near);
        $this->bind($client);
        $this->send($peer, str_repeat("GET / HTTP/1.1\r\n\r\n", 24) . 'GET /incomplete');
        $this->assertTrue(socket_shutdown($peer, 1));
        $this->loop->tick();
        $this->assertFalse($client->shouldClose());
        $responses = $this->drain($peer);
        for ($pass = 0; $pass < 30; ++$pass) {
            $this->loop->tick();
            $responses .= $this->drain($peer);
        }
        $this->assertSame(24, substr_count($responses, 'HTTP/1.1 200 OK'));
        $this->assertTrue($client->shouldClose());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** Releasing one held command resumes the old buffer and stops at the next hold. */
    public function testCommandReleasePreservesReplyOrderAndSingleHold(): void
    {
        [$near, $peer] = $this->pair();
        $server = new CommandServer('127.0.0.1', 0);
        $client = new CommandClient($near, $server);
        $this->bind($client);
        $this->send($peer,
            new CommandRequestDTO('held-1', 'fixture:hold')->toJson() . "\n"
            . new CommandRequestDTO('ping', CommandConstants::COMMAND_PING)->toJson() . "\n"
            . new CommandRequestDTO('held-2', 'fixture:hold')->toJson() . "\n"
            . new CommandRequestDTO('tail', CommandConstants::COMMAND_PING)->toJson() . "\n",
        );
        $this->poll();
        $this->assertSame('', $this->drain($peer));
        $this->assertSame('held-1', Hilos::$sr->getNextQueuedSignal()?->data->correlationId);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());

        $server->deliver('held-1', CommandReplyDTO::ok('held-1'));
        $this->poll();
        $this->assertSame(['held-1', 'ping'], $this->replyIds($this->drain($peer)));
        $this->assertSame('held-2', Hilos::$sr->getNextQueuedSignal()?->data->correlationId);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());

        $server->deliver('held-2', CommandReplyDTO::ok('held-2'));
        $this->poll();
        $this->assertSame(['held-2', 'tail'], $this->replyIds($this->drain($peer)));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** A command burst uses the same bounded JSON path and leaves its incomplete suffix idle. */
    public function testCommandBurstYieldsAndIncompleteJsonDoesNotSpin(): void
    {
        [$near, $peer] = $this->pair();
        $client = new CommandClient($near, new CommandServer('127.0.0.1', 0));
        $this->bind($client);
        $wire = '';
        $expected = [];
        for ($index = 0; $index < 150; ++$index) {
            $expected[] = 'ping-' . $index;
            $wire .= new CommandRequestDTO('ping-' . $index, CommandConstants::COMMAND_PING)->toJson() . "\n";
        }
        $tail = new CommandRequestDTO('last', CommandConstants::COMMAND_PING)->toJson() . "\n";
        $this->send($peer, $wire . substr($tail, 0, -2));
        $this->loop->tick();
        $responses = $this->drain($peer);
        $queued = new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($client);
        $this->assertNotEmpty($queued);
        $this->assertLessThan(150, count($this->replyIds($queued)));
        $this->assertSame('', $responses, 'external writes follow the accepted parser prefix');
        $this->poll();
        $responses .= $this->drain($peer);
        $this->assertSame($expected, $this->replyIds($responses));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->send($peer, substr($tail, -2));
        $this->poll();
        $this->assertSame(['last'], $this->replyIds($this->drain($peer)));
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** The upgrade and every following frame may arrive in one TCP write. */
    public function testWebSocketUpgradeAndBurstContinueWithFragmentationIntact(): void
    {
        [$near, $peer] = $this->pair();
        $client = new ParserContinuationWebSocket($near);
        $this->bind($client);
        $wire = "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
        $expected = [];
        for ($index = 0; $index < 150; ++$index) {
            $expected[] = 'message-' . $index;
            $wire .= $this->frame(0x1, 'message-', false)
                . $this->frame(0x0, (string)$index);
        }
        $tail = $this->frame(0x1, 'tail');
        $this->send($peer, $wire . substr($tail, 0, -1));
        $this->loop->tick();
        $this->assertNotEmpty($client->messages, 'post-upgrade bytes must be parsed without another socket read');
        $this->assertLessThan(150, count($client->messages));
        $this->poll();
        $this->assertSame($expected, $client->messages);
        $this->assertStringStartsWith('HTTP/1.1 101 Switching Protocols', $this->drain($peer));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->send($peer, substr($tail, -1));
        $this->poll();
        $expected[] = 'tail';
        $this->assertSame($expected, $client->messages);
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> Both the future owner and current legacy passes */
    public static function bindings(): iterable
    {
        yield 'attached' => [true];
        yield 'legacy' => [false];
    }

    /** @param bool $attached Whether the native ready callback or legacy read pass drives input */
    #[DataProvider('bindings')]
    public function testWorkerBurstIsFifoWithoutAnotherPeerWrite(bool $attached): void
    {
        [$client, $peer, $sink] = $this->worker();
        if ($attached) {
            $this->bind($client);
        }
        $this->send($peer, $this->workerBurst());
        if ($attached) {
            $this->loop->tick();
        } else {
            $client->read();
        }
        $this->assertNotEmpty($sink->windows);
        $this->assertLessThan(150, count($sink->windows));
        for ($pass = 0; $pass < 12; ++$pass) {
            if ($attached) {
                $this->loop->tick();
            } else {
                $client->read();
            }
        }
        $this->assertSame(range(1, 150), $sink->windows);
        $this->assertFalse($client->shouldClose());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> Terminal read outcomes after the same accepted prefix */
    public static function terminalReads(): iterable
    {
        yield 'EOF' => [false];
        yield 'reset' => [true];
    }

    /** @param bool $reset Whether unread output makes closing the peer produce a real reset */
    #[DataProvider('terminalReads')]
    public function testTerminalReadWaitsForAllAcceptedWorkerMessages(bool $reset): void
    {
        [$client, $peer, $sink] = $this->worker();
        $this->bind($client);
        $client->sendAgentStop('fixture-agent');
        $this->send($peer, $this->workerBurst());
        if ($reset) {
            $this->send($client->getSocket(), 'unread');
        }
        socket_close($peer);
        unset($this->sockets[spl_object_id($peer)]);
        $this->loop->tick();
        $this->assertFalse($client->shouldClose());
        $this->assertTrue($client->hasPendingWrite(), 'terminal input must not permit an intervening output flush');
        $this->assertNotEmpty($sink->windows);
        $this->assertLessThan(150, count($sink->windows));
        $failure = null;
        try {
            $this->poll();
        } catch (ConnectionResetException $caught) {
            $failure = $caught;
        }
        $this->assertSame(range(1, 150), $sink->windows);
        $this->assertTrue($client->shouldClose());
        $this->assertTrue($client->hasPendingWrite());
        $this->assertSame($reset, $failure instanceof ConnectionResetException);
    }

    /** A read ending exactly at its byte budget must finish buffered input before a write can fail. */
    public function testWriteFailureCannotOvertakeAcceptedParserRemainder(): void
    {
        $wire = $this->workerBurst();
        putenv('SOCKET_READ_BUFFER_SIZE=' . strlen($wire));
        [$client, $peer, $sink] = $this->worker();
        $this->bind($client);
        $client->sendAgentStop('fixture-agent');
        $this->send($peer, $wire);
        $this->send($client->getSocket(), 'unread');
        socket_close($peer);
        unset($this->sockets[spl_object_id($peer)]);
        $failure = null;
        try {
            $this->poll();
        } catch (SocketException $caught) {
            $failure = $caught;
        }
        $this->assertSame(range(1, 150), $sink->windows, 'a failing writer must not discard the unread parser suffix');
        $this->assertInstanceOf(SocketException::class, $failure);
        $this->assertTrue($client->shouldClose());
    }

    /** stopReading cancels buffered parsing, while terminal input still beats outbound draining. */
    public function testStopReadingCancelsBufferedContinuationBeforeEof(): void
    {
        [$client, $peer, $sink] = $this->worker();
        $this->bind($client);
        $this->send($peer, $this->workerBurst());
        $this->loop->tick();
        $accepted = $sink->windows;
        $this->assertLessThan(150, count($accepted));
        $client->stopReading();
        $this->assertTrue(socket_shutdown($peer, 1));
        $this->poll();
        $this->assertSame($accepted, $sink->windows);
        $this->assertTrue($client->shouldClose());
    }

    /** Install ordinary environment and router without creating a daemon or a worker process. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousReadSize = getenv('SOCKET_READ_BUFFER_SIZE');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        $this->loop = new EventLoop();
    }

    /** Teardown releases bindings before descriptors without invoking unrelated server cleanup. */
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->detachEventLoop();
        }
        $this->loop->close();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        putenv($this->previousReadSize === false ? 'SOCKET_READ_BUFFER_SIZE' : 'SOCKET_READ_BUFFER_SIZE=' . $this->previousReadSize);
    }

    /** @return array{Socket, Socket} Nonblocking ends in this process */
    private function pair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
            $this->sockets[spl_object_id($socket)] = $socket;
        }
        return $pair;
    }

    /** @param AbstractClient $client Client whose production reader and writer are driven */
    private function bind(AbstractClient $client): void
    {
        $this->clients[] = $client;
        $client->attachEventLoop($this->loop, static function () use ($client): void {
            $client->read();
            $client->write();
            if ($client->shouldClose()) {
                $client->detachEventLoop();
            }
        });
    }

    /** Execute a finite number of nonblocking turns, without sleeping or expiring a deadline. */
    private function poll(): void
    {
        for ($pass = 0; $pass < 12; ++$pass) {
            $this->loop->tick();
        }
    }

    /**
     * @param Socket $peer Sending socket end
     * @param string $bytes Complete fixture input
     */
    private function send(Socket $peer, string $bytes): void
    {
        $this->assertSame(strlen($bytes), socket_write($peer, $bytes));
    }

    /** @param Socket $peer Receiving end
     * @return string Every byte already available, without blocking
     */
    private function drain(Socket $peer): string
    {
        $bytes = '';
        while (true) {
            $chunk = @socket_read($peer, 65_536);
            if ($chunk === false) {
                $this->assertContains(socket_last_error($peer), [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);
                socket_clear_error($peer);
                return $bytes;
            }
            if ($chunk === '') {
                return $bytes;
            }
            $bytes .= $chunk;
        }
    }

    /** @param string $wire Command replies from the actual socket
     * @return list<string> Correlation ids in wire order
     */
    private function replyIds(string $wire): array
    {
        return array_map(
            static fn(string $line): string => CommandReplyDTO::fromJson($line)->correlationId,
            array_values(array_filter(explode("\n", $wire))),
        );
    }

    /** @param int $opcode Physical frame opcode
     * @param string $payload Small text payload
     * @param bool $fin Whether the message finishes in this frame
     * @return string Masked browser frame
     */
    private function frame(int $opcode, string $payload, bool $fin = true): string
    {
        $mask = 'mask';
        return chr(($fin ? 0x80 : 0) | $opcode) . chr(0x80 | strlen($payload)) . $mask
            . ($payload ^ str_repeat($mask, intdiv(strlen($payload) + 3, 4)));
    }

    /** @return array{WorkerClient, Socket, ParserContinuationDeliverySink} Actual worker reader with a recording report sink */
    private function worker(): array
    {
        [$near, $peer] = $this->pair();
        $client = new WorkerClient(
            $near,
            $this->createMock(AgentManagerDaemon::class),
            $this->createMock(WorkerServer::class),
        );
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, true);
        $sink = new ParserContinuationDeliverySink();
        $client->setDeliverySettingsSink($sink);
        return [$client, $peer, $sink];
    }

    /** @return string Distinct worker reports whose order is observable at their real receiver */
    private function workerBurst(): string
    {
        $wire = '';
        foreach (range(1, 150) as $window) {
            $wire .= new WorkerDeliverySettingsDTO($window)->toJson() . "\n";
        }
        return $wire;
    }
}

/** Records real decoded worker reports without replacing the parser. */
final class ParserContinuationDeliverySink implements DeliverySettingsSink
{
    /** @var list<int> Reported values in delivery order */
    public array $windows = [];

    /** @param WorkerClient $worker Channel that decoded this report
     * @param int $windowMs Reported value
     */
    public function onDeliverySettings(WorkerClient $worker, int $windowMs): void
    {
        $this->windows[] = $windowMs;
    }
}

/** Keeps the production handshake, frame validation and fragmentation before recording text. */
final class ParserContinuationWebSocket extends WebSocketClient
{
    /** @var list<string> Completed text messages */
    public array $messages = [];

    /** @param string $payload Reassembled text message */
    protected function onFrame(string $payload): void
    {
        $this->messages[] = $payload;
    }

    /** @return string Local fixture address, without a TCP peer-name lookup on a Unix pair */
    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /** @param array<string, string> $headers Parsed upgrade headers
     * @param string $acceptKey Minted connection key
     * @param array<string, string> $cookies Upgrade cookies
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
