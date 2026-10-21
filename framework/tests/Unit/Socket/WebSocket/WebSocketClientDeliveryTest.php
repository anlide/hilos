<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryInput;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Socket;

/** Real one-process socket bytes, with a monotonic clock controlled by each test. */
final class WebSocketClientDeliveryTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    /** @var list<array{DeliveryTestClient, Socket}> Connections and their browser ends */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as [$client, $peer]) {
            $client->close();
            socket_close($peer);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
    }

    public function testQuietDeliveryBatchesEventsAndPreservesInnerMetadataAndOrder(): void
    {
        [$client, $peer] = $this->connection();
        $first = '{"type":"toast","data":{"text":"one"},"outcome":"success","requestId":"r","time":123}';
        $second = '{"type":"toast","data":{"text":"two"}}';
        $client->sendFrame($first);
        $client->sendFrame($second);
        self::assertTrue($client->hasPendingWrite());
        self::assertSame([], $this->readFrames($peer));
        $client->write();
        self::assertSame([
            ['opcode' => 1, 'text' => '{"type":"signal_batch","data":{"frames":[' . $first . ',' . $second . ']}}'],
        ], $this->readFrames($peer));
        self::assertFalse($client->hasPendingWrite());
        $client->write();
        self::assertSame([], $this->readFrames($peer));
    }

    public function testTenEntityChangesReachTheSocketAsOneLatestBareEnvelope(): void
    {
        [$client, $peer] = $this->connection();
        for ($value = 1; $value <= 10; $value++) {
            $client->sendFrame(json_encode([
                'type' => 'page_response',
                'data' => ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'some_value' => $value]]]],
            ], JSON_THROW_ON_ERROR));
        }
        $client->write();
        $frames = $this->readFrames($peer);
        self::assertCount(1, $frames);
        self::assertSame([
            'type' => 'page_response',
            'data' => ['page' => 'p', 'payload' => ['entities' => ['thing' => ['id' => 42, 'some_value' => 10]]]],
        ], json_decode($frames[0]['text'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testBusyTrafficKeepsAFixedDeadlineAndQuietTrafficRestartsImmediately(): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        foreach ([100, 400, 999] as $now) {
            $client->now = $now;
            $client->sendFrame('{"type":"subscription_page_demo","data":{"value":' . $now . '}}');
            $client->write();
            self::assertSame([], $this->readFrames($peer));
        }
        $client->now = 1000;
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => '{"type":"subscription_page_demo","data":{"value":999}}']], $this->readFrames($peer));
        $client->now = 5000;
        $client->sendFrame('quiet again');
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'quiet again']], $this->readFrames($peer));
    }

    /** @return iterable<string, array{array<string, mixed>}> Each accepted application request branch */
    public static function acceptedRequests(): iterable
    {
        yield 'action' => [['type' => 'action', 'action' => 'allowed']];
        yield 'upload credit' => [['type' => SignalTypeConstants::UPLOAD_CREDIT_REQUEST, 'clientUploadId' => 'u', 'bytes' => 20]];
        foreach (['page_subscribe', 'page_update_subscription', 'page_unsubscribe'] as $type) {
            yield $type => [['type' => $type, 'page' => 'p']];
        }
        foreach (['table_viewport', 'table_facets', 'table_rendered', 'table_row_focus'] as $type) {
            yield $type => [['type' => $type, 'page' => 'p', 'tableKey' => 't', 'rendered' => [], 'rowKey' => 'r', 'limit' => 20, 'facets' => []]];
        }
        foreach (['group_subscribe', 'group_update_subscription', 'group_unsubscribe'] as $type) {
            yield $type => [['type' => $type, 'group' => 'g']];
        }
    }

    /** @param array<string, mixed> $request Valid browser wire request */
    #[DataProvider('acceptedRequests')]
    public function testAcceptedRequestReleasesOnlyItsSocketAndOpensGrace(array $request): void
    {
        [$clicked, $peer] = $this->connection();
        [$other, $otherPeer] = $this->connection();
        if ($request['type'] === SignalTypeConstants::UPLOAD_CREDIT_REQUEST) {
            $sink = $this->createMock(UploadMemoryPoolSink::class);
            $sink->method('getUploadInput')->willReturn(new UploadMemoryInput(new UploadMemoryPool(1000, 'test'), $clicked->acceptKey));
            $clicked->setUploadMemoryPoolSink($sink);
        }
        foreach ([[$clicked, $peer], [$other, $otherPeer]] as [$client, $browser]) {
            $this->prime($client, $browser);
            $client->now = 100;
            $client->sendFrame('waiting');
        }
        $clicked->acceptJson(json_encode($request, JSON_THROW_ON_ERROR));
        $accepted = Hilos::$sr->getNextQueuedSignal();
        self::assertSame($request['type'], $accepted?->signalType->getType());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        if ($request['type'] === SignalTypeConstants::PAGE_SUBSCRIBE) {
            $clicked->write();
            self::assertSame([], $this->readFrames($peer));
            $clicked->completeDelivery('p', $accepted->data->deliveryId);
        }
        $clicked->write();
        $other->write();
        self::assertSame([['opcode' => 1, 'text' => 'waiting']], $this->readFrames($peer));
        self::assertSame([], $this->readFrames($otherPeer));
        $clicked->now = 1099;
        $clicked->sendFrame('grace');
        $clicked->write();
        self::assertSame([['opcode' => 1, 'text' => 'grace']], $this->readFrames($peer));
        $clicked->now = 1100;
        $clicked->sendFrame('after grace');
        $clicked->write();
        self::assertSame([], $this->readFrames($peer));
    }

    public function testBrowserCannotSupplyAnInternalDeliveryToken(): void
    {
        [$client] = $this->connection();
        $tokens = [];
        foreach (['page_subscribe', 'page_update_subscription'] as $type) {
            $client->acceptJson(json_encode(['type' => $type, 'page' => 'p', 'deliveryId' => 'browser-forgery'], JSON_THROW_ON_ERROR));
            $signal = Hilos::$sr->getNextQueuedSignal();
            self::assertNotNull($signal);
            self::assertSame($type, $signal->signalType->getType());
            self::assertSame($client->acceptKey . ':' . (count($tokens) + 1), $signal->data->deliveryId);
            $tokens[] = $signal->data->deliveryId;
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Both supported window addresses */
    public static function restoredWindows(): iterable
    {
        $common = [
            'filter' => ['search' => 'worker', 'class' => 'agent'],
            'sort' => [['field' => 'key', 'direction' => 'desc']],
            'facets' => ['class' => ['daemon', 'agent', 'worker']],
            'rendered' => ['key', 'class'],
        ];
        yield 'anchor' => [['limit' => 17, 'anchor' => ['key' => 'worker-3'], 'anchorDirection' => 'before', ...$common]];
        yield 'page index' => [['limit' => 17, 'pageIndex' => 2, ...$common]];
    }

    /** @param array<string, mixed> $descriptor Window the browser retained across reconnect */
    #[DataProvider('restoredWindows')]
    public function testSocketParserPreservesRestoredWindowsAcrossWorkerAndPeerTransport(array $descriptor): void
    {
        [$client] = $this->connection();
        $client->acceptJson(json_encode([
            'type' => 'page_subscribe',
            'page' => 'logsKeys',
            'params' => ['nodeId' => 'node-a'],
            'acceptKey' => ['forged'],
            'deliveryId' => ['forged'],
            'tableWindows' => ['hilosLogKeys' => $descriptor, 'not-a-descriptor' => 7, 12 => ['limit' => 1]],
        ], JSON_THROW_ON_ERROR));
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $signal->data);
        self::assertSame('logsKeys', $signal->data->page);
        self::assertSame(['nodeId' => 'node-a'], $signal->data->params);
        self::assertSame($client->acceptKey, $signal->data->acceptKey);
        self::assertSame($client->acceptKey . ':1', $signal->data->deliveryId);
        self::assertSame(['hilosLogKeys'], array_keys($signal->data->tableWindows));
        self::assertInstanceOf(TableWindowDescriptorDTO::class, $signal->data->tableWindows['hilosLogKeys']);
        self::assertSame($descriptor, $signal->data->tableWindows['hilosLogKeys']->toArray());
        self::assertTrue($client->isDeliveryPending('logsKeys', $signal->data->deliveryId));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        $worker = WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO('owner', $signal)->toJson());
        self::assertInstanceOf(DaemonAgentMessageDTO::class, $worker);
        $peer = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $worker->signal)->toJson());
        self::assertInstanceOf(PeerSignalDTO::class, $peer);
        foreach ([$worker->signal, $peer->signal] as $restored) {
            self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $restored->data);
            self::assertSame($signal->data->toArray(), $restored->data->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Cold entries and non-descriptor entries the DTO ignores */
    public static function coldWindows(): iterable
    {
        yield 'absent' => [[]];
        yield 'empty' => [['tableWindows' => []]];
        yield 'null' => [['tableWindows' => null]];
        yield 'ignored entries' => [['tableWindows' => ['hilosLogKeys' => false, 12 => ['limit' => 1]]]];
    }

    /** @param array<string, mixed> $fields Optional window field */
    #[DataProvider('coldWindows')]
    public function testColdSubscribeKeepsTheExistingPageAndParamsSemantics(array $fields): void
    {
        [$client] = $this->connection();
        $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => ' p ', 'params' => false, ...$fields], JSON_THROW_ON_ERROR));
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $signal->data);
        self::assertSame(' p ', $signal->data->page);
        self::assertSame([], $signal->data->params);
        self::assertSame([], $signal->data->tableWindows);
        self::assertSame($client->acceptKey . ':1', $signal->data->deliveryId);
    }

    public function testUpdateSubscriptionDoesNotReadSubscribeWindows(): void
    {
        [$client] = $this->connection();
        $client->acceptJson('{"type":"page_update_subscription","page":"p","tableWindows":{"hilosLogKeys":{}}}');
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $signal->data);
        self::assertArrayNotHasKey('tableWindows', $signal->data->toArray());
        self::assertSame($client->acceptKey . ':1', $signal->data->deliveryId);
    }

    /** @return iterable<string, array{mixed}> Existing descriptor validation failures */
    public static function invalidWindows(): iterable
    {
        yield 'not a map' => ['invalid'];
        yield 'missing limit' => [['hilosLogKeys' => []]];
        yield 'mistyped limit' => [['hilosLogKeys' => ['limit' => '17']]];
        yield 'two addresses' => [['hilosLogKeys' => ['limit' => 17, 'pageIndex' => 2, 'anchor' => ['key' => 'a']]]];
        yield 'unknown direction' => [['hilosLogKeys' => ['limit' => 17, 'anchorDirection' => 'sideways']]];
        yield 'mistyped rendered field' => [['hilosLogKeys' => ['limit' => 17, 'rendered' => ['key', false]]]];
    }

    /** @param mixed $windows Invalid browser input */
    #[DataProvider('invalidWindows')]
    public function testInvalidWindowCannotStartGraceOrReplaceAnExistingAttempt(mixed $windows): void
    {
        [$cold, $coldPeer] = $this->connection();
        [$held, $heldPeer] = $this->connection();
        $this->prime($cold, $coldPeer);
        $held->acceptJson('{"type":"page_subscribe","page":"p"}');
        $previous = Hilos::$sr->getNextQueuedSignal();
        foreach ([$cold, $held] as $client) {
            $client->now = 100;
            $client->sendFrame('waiting');
            try {
                $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => 'other', 'tableWindows' => $windows], JSON_THROW_ON_ERROR));
                self::fail('A malformed descriptor must refuse the frame.');
            } catch (InvalidFrameException $failure) {
                self::assertInstanceOf(InvalidFormatException::class, $failure->getPrevious());
            }
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
        $cold->write();
        self::assertSame([], $this->readFrames($coldPeer), 'A refused frame cannot open grace.');
        $cold->now = $held->now = 10_000;
        $cold->write();
        self::assertSame([['opcode' => 1, 'text' => 'waiting']], $this->readFrames($coldPeer), 'A refused frame cannot start a hold.');
        $held->write();
        self::assertSame([], $this->readFrames($heldPeer));
        self::assertTrue($held->isDeliveryPending('p', $previous->data->deliveryId));
        $held->completeDelivery('p', $previous->data->deliveryId);
        $held->write();
        self::assertSame([['opcode' => 1, 'text' => 'waiting']], $this->readFrames($heldPeer), 'The previous attempt and its output survive.');
    }

    /** @return iterable<string, array{string}> Non-accepted browser frames */
    public static function refusedRequests(): iterable
    {
        foreach (['broken', '{}', '{"type":"unknown"}', '{"type":"page_subscribe"}',
            '{"type":"table_rendered","page":"p","tableKey":"t"}', '{"type":"action","action":"denied"}',
            '{"type":"hilos_upload_credit_request","clientUploadId":"u"}'] as $wire) {
            yield $wire => [$wire];
        }
    }

    /** @param string $wire Malformed or unaccepted request */
    #[DataProvider('refusedRequests')]
    public function testUnacceptedRequestCannotOpenGrace(string $wire): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        $client->now = 100;
        $client->sendFrame('waiting');
        try {
            $client->acceptJson($wire);
        } catch (InvalidFrameException | AgentUnknownActionException) {
            // A refusal is expected; what matters here is the absence of urgent delivery.
        }
        $client->write();
        self::assertSame([], $this->readFrames($peer));
        $client->now = 1000;
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'waiting']], $this->readFrames($peer));
    }

    /** @return iterable<string, array{string}> Urgent server reply envelopes */
    public static function urgentReplies(): iterable
    {
        yield 'success' => ['{"type":"action_success","data":{},"requestId":"r"}'];
        yield 'error' => ['{"type":"action_error","data":{"requestId":"r"},"outcome":"fail"}'];
        yield 'window' => ['{"type":"table_window","data":{}}'];
        yield 'refusal' => ['{"type":"table_window_refused","data":{}}'];
    }

    /** @param string $reply Correlated action or viewport reply */
    #[DataProvider('urgentReplies')]
    public function testDelayedReplyOpensGraceAtItsActualArrival(string $reply): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        $client->now = 900;
        $client->sendFrame($reply);
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => $reply]], $this->readFrames($peer));
        $client->now = 1899;
        $client->sendFrame('reply grace');
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'reply grace']], $this->readFrames($peer));
        $client->now = 1900;
        $client->sendFrame('no longer immediate');
        $client->write();
        self::assertSame([], $this->readFrames($peer));
    }

    public function testLiveMirrorPreservesAnOpenDeadlineAndChangesTheNextOne(): void
    {
        [$client, $peer] = $this->connection();
        $mirror = new DeliverySettingsMirror();
        $client->setDeliverySettingsMirror($mirror);
        $this->prime($client, $peer);
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        $mirror->onDeliverySettings($worker, 200);
        $client->now = 200;
        $client->sendFrame('old deadline');
        $client->write();
        self::assertSame([], $this->readFrames($peer));
        $client->now = 1000;
        $client->write();
        self::assertCount(1, $this->readFrames($peer));
        $client->now = 1100;
        $client->sendFrame('new deadline');
        $client->write();
        self::assertSame([], $this->readFrames($peer));
        $client->now = 1200;
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'new deadline']], $this->readFrames($peer));
    }

    public function testPongAndBinaryBypassCadenceWithoutMakingApplicationTextUrgent(): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        $client->now = 100;
        $client->sendFrame('waiting');
        $client->feed("\x89\x81abcd" . ('p' ^ 'a'));
        $client->sendFrameBinary("\x00\xff");
        $client->write();
        self::assertSame([['opcode' => 10, 'text' => 'p'], ['opcode' => 2, 'text' => "\x00\xff"]], $this->readFrames($peer));
        self::assertTrue($client->hasPendingWrite());
        $client->now = 1000;
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'waiting']], $this->readFrames($peer));
    }

    public function testOpaqueTextAndPrebuiltBatchRemainOrderedPackageBoundaries(): void
    {
        [$client, $peer] = $this->connection();
        $texts = ['{"type":"toast","data":1}', 'opaque', '{"notAnEnvelope":true}',
            '{"type":"signal_batch","data":{"frames":[{"type":"toast","data":2}]}}', '{"type":"toast","data":3}'];
        foreach ($texts as $text) {
            $client->sendFrame($text);
        }
        $client->write();
        self::assertSame($texts, array_column($this->readFrames($peer), 'text'));
    }

    public function testShutdownForcesCompletedDeliveryAndWaitsForPartialTransportWrites(): void
    {
        [$client, $peer] = $this->connection(partial: true);
        $this->prime($client, $peer);
        $client->now = 100;
        $client->sendFrame(str_repeat('x', 300));
        $client->closeOnceWritten();
        self::assertFalse($client->shouldClose());
        $client->write();
        self::assertFalse($client->shouldClose());
        self::assertTrue($client->hasPendingWrite());
        for ($turn = 0; $turn < 20 && $client->hasPendingWrite(); $turn++) {
            $client->write();
        }
        self::assertTrue($client->shouldClose());
        self::assertSame([['opcode' => 1, 'text' => str_repeat('x', 300)]], $this->readFrames($peer));
    }

    public function testRotationWaitsForApplicationQueueAsWellAsTransportBytes(): void
    {
        [$client, $peer] = $this->connection(partial: true);
        $dropper = new DeliveryTestDropper();
        $client->setConnectionDropper($dropper);
        $this->prime($client, $peer);
        new ReflectionProperty(WebSocketClient::class, 'pendingRotationDrops')->setValue($client, ['sibling']);
        $client->now = 100;
        $response = str_repeat('response before drops', 5);
        $client->sendFrame($response);
        $client->write();
        self::assertSame([], $dropper->batches);
        $client->now = 1000;
        $client->write();
        self::assertSame([], $dropper->batches);
        self::assertTrue($client->hasPendingWrite());
        for ($turn = 0; $turn < 20 && $client->hasPendingWrite(); $turn++) {
            $client->write();
        }
        self::assertSame([['sibling']], $dropper->batches);
        self::assertSame([['opcode' => 1, 'text' => $response]], $this->readFrames($peer));
    }

    public function testCloseDiscardsPendingDeliveryBeforeAProjectCloseHook(): void
    {
        [$client, $peer] = $this->connection();
        $client->sendFrame('discard');
        $client->close();
        self::assertFalse($client->hasPendingWrite());
        self::assertSame([], $this->readFrames($peer));
        [$fresh, $freshPeer] = $this->connection();
        $fresh->sendFrame('fresh');
        $fresh->write();
        self::assertSame([['opcode' => 1, 'text' => 'fresh']], $this->readFrames($freshPeer));
    }

    /** @return array{DeliveryTestClient, Socket} Real connected client/browser sockets */
    private function connection(bool $partial = false): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new DeliveryTestClient($pair[0], $partial ? new DeliveryTestPartialTransport($pair[0]) : null);
        $this->connections[] = [$client, $pair[1]];

        return [$client, $pair[1]];
    }

    private function prime(DeliveryTestClient $client, Socket $peer): void
    {
        $client->sendFrame('prime');
        $client->write();
        self::assertSame([['opcode' => 1, 'text' => 'prime']], $this->readFrames($peer));
    }

    /** @return list<array{opcode: int, text: string}> Complete RFC frames actually written to the browser socket */
    private function readFrames(Socket $peer): array
    {
        $bytes = '';
        while (($chunk = (new PlainSocketTransport($peer))->read(65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
        $frames = [];
        while ($bytes !== '') {
            self::assertGreaterThanOrEqual(2, strlen($bytes));
            $opcode = ord($bytes[0]) & 15;
            $length = ord($bytes[1]);
            $offset = 2;
            if ($length === 126) {
                $length = unpack('n', substr($bytes, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                $length = unpack('J', substr($bytes, 2, 8))[1];
                $offset = 10;
            }
            self::assertGreaterThanOrEqual($offset + $length, strlen($bytes));
            $frames[] = ['opcode' => $opcode, 'text' => substr($bytes, $offset, $length)];
            $bytes = substr($bytes, $offset + $length);
        }

        return $frames;
    }
}

/** Real client with only time and application hooks controlled by the test. */
final class DeliveryTestClient extends WebSocketClient
{
    public int $now = 0;
    /** @var list<DeliveryFrame> Identity observations at the prepared-frame boundary */
    public array $prepared = [];

    public function __construct(Socket $socket, ?SocketTransportInterface $transport = null)
    {
        parent::__construct($socket, $transport);
        $this->handshakeCompleted = true;
        $this->acceptKey = 'delivery-' . spl_object_id($this);
    }

    public function sendDeliveryFrame(DeliveryFrame $frame): void
    {
        $this->prepared[] = $frame;
        parent::sendDeliveryFrame($frame);
    }

    public function acceptJson(string $json): void
    {
        $this->onFrame($json);
    }

    public function feed(string $bytes): void
    {
        $this->readBuffer .= $bytes;
        $this->processReadBuffer();
    }

    protected function deliveryNowMs(): int
    {
        return $this->now;
    }

    protected function onActionValidated(string $actionName): void
    {
        if ($actionName !== 'allowed') {
            throw new AgentUnknownActionException($actionName);
        }
    }

    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }

    protected function onClose(): void
    {
    }
}

/** A transport accepting a bounded slice per call, while still writing to the real socket. */
final class DeliveryTestPartialTransport implements SocketTransportInterface
{
    private PlainSocketTransport $plain;

    public function __construct(Socket $socket)
    {
        $this->plain = new PlainSocketTransport($socket);
    }

    public function read(int $length): string|false
    {
        return $this->plain->read($length);
    }
    public function write(string $data): int|false
    {
        return $this->plain->write(substr($data, 0, 32));
    }
    public function close(): void
    {
        $this->plain->close();
    }
    public function isEndOfStream(): bool
    {
        return $this->plain->isEndOfStream();
    }
    public function needsHandshake(): bool
    {
        return false;
    }
    public function advanceHandshake(): int|bool
    {
        return true;
    }
    public function verifiedPeerName(): ?string
    {
        return null;
    }
}

final class DeliveryTestDropper implements ConnectionDropper
{
    /** @var list<list<string>> Completed rotation drops */
    public array $batches = [];
    public function dropSessionConnections(array $acceptKeys): void
    {
        $this->batches[] = $acceptKeys;
    }
    public function dropWebSocketConnection(string $acceptKey): bool
    {
        return false;
    }
}
