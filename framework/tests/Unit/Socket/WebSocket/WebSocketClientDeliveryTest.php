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
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
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
    /** Outbound cap of one browser socket, as the contract states it. */
    private const int CAP = 8 * 1024 * 1024;

    /** RFC 6455 header of a frame whose payload needs the 64-bit length. */
    private const int LONG_HEADER_BYTES = 10;

    private const int MEBIBYTE = 1024 * 1024;

    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    /** @var list<array{DeliveryTestClient, Socket}> Connections and their browser ends */
    private array $connections = [];
    /** @var list<Socket> Browser ends of the connections a test server holds */
    private array $servedBrowsers = [];
    /** @var list<CapTestServer> Servers whose remaining connections close after the test */
    private array $servers = [];

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
        foreach ($this->servers as $server) {
            foreach ($server->heldClients() as $client) {
                $client->close();
            }
        }
        foreach ($this->servedBrowsers as $browser) {
            socket_close($browser);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
    }

    public function testViewportCorrelationSurvivesTheClientFrameParser(): void
    {
        [$client] = $this->connection();
        $client->acceptJson('{"type":"table_viewport","page":"p","tableKey":"t","limit":10,"requestId":"window:1"}');
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT, $signal?->signalType->getType());
        $this->assertSame('p', $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketTableViewportSignalDTO::class, $signal->data);
        $this->assertSame('window:1', $signal->data->requestId);
        $this->assertSame($client->acceptKey, $signal->data->acceptKey);
    }

    public function testAnEmptyViewportCorrelationIsAnInvalidClientFrame(): void
    {
        [$client] = $this->connection();
        $this->expectException(InvalidFrameException::class);
        $client->acceptJson('{"type":"table_viewport","page":"p","tableKey":"t","limit":10,"requestId":""}');
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

    public function testOutboundBytesCountTheQueueOnceAndFollowEveryPartialWrite(): void
    {
        [$client, $peer] = $this->connection(partial: true);
        $this->prime($client, $peer);
        $client->now = 100;
        $texts = ['{"type":"toast","data":1}', '{"type":"toast","data":2}', 'opaque', str_repeat('x', 300)];
        foreach ($texts as $text) {
            $client->sendFrame($text);
        }
        $queued = $client->outboundBytes();
        self::assertSame(0, $client->transportBytes());

        $client->now = 1000;
        $client->write();
        self::assertSame($client->transportBytes(), $client->outboundBytes());
        self::assertSame($queued - 32, $client->outboundBytes());
        $bytes = $this->pump($client, $peer);
        self::assertSame($queued, strlen($bytes));
        self::assertSame(0, $client->outboundBytes());
        self::assertSame([
            '{"type":"signal_batch","data":{"frames":[' . $texts[0] . ',' . $texts[1] . ']}}', $texts[2], $texts[3],
        ], array_column(self::parseFrames($bytes), 'text'));
    }

    /** @return iterable<string, array{bool}> Where the bytes wait: the delivery queue, or the transport buffer */
    public static function capPaths(): iterable
    {
        yield 'queue only' => [true];
        yield 'transport only' => [false];
    }

    /** @param bool $queued Whether the bytes wait in the delivery queue rather than the transport buffer */
    #[DataProvider('capPaths')]
    public function testExactlyTheCapStaysOpenAndIsDeliveredWhole(bool $queued): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        $client->now = 100;
        $payload = str_repeat('x', self::CAP - self::LONG_HEADER_BYTES);
        $this->sendLarge($client, $payload, $queued);
        self::assertFalse($client->shouldClose());
        self::assertSame(self::CAP, $client->outboundBytes());
        self::assertSame($queued ? 0 : self::CAP, $client->transportBytes());

        $client->now = 1000;
        $bytes = $this->pump($client, $peer);
        self::assertFalse($client->shouldClose());
        self::assertSame(self::CAP, strlen($bytes));
        $frames = self::parseFrames($bytes);
        self::assertSame([$queued ? 1 : 2], array_column($frames, 'opcode'));
        self::assertTrue($frames[0]['text'] === $payload);
    }

    /** @param bool $queued Whether the bytes wait in the delivery queue rather than the transport buffer */
    #[DataProvider('capPaths')]
    public function testOneByteOverTheCapClosesAndTheSocketTakesNothingMore(bool $queued): void
    {
        [$client, $peer] = $this->connection();
        $this->prime($client, $peer);
        $client->now = 100;
        $this->sendLarge($client, str_repeat('x', self::CAP - self::LONG_HEADER_BYTES + 1), $queued);
        self::assertTrue($client->shouldClose());
        self::assertSame(0, $client->transportBytes());

        $held = $client->outboundBytes();
        $client->sendFrame('late');
        $client->sendFrameBinary('late');
        self::assertSame($held, $client->outboundBytes());
        $client->now = 1000;
        $client->write();
        self::assertSame('', $this->readBytes($peer));
        $client->close();
        self::assertFalse($client->hasPendingWrite());
        self::assertSame(0, $client->outboundBytes());
    }

    public function testAHeldSubscriptionOverTheCapClosesWithoutReleasingItsIncompletePacket(): void
    {
        [$client, $peer] = $this->connection();
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        self::assertSame(SignalTypeConstants::PAGE_SUBSCRIBE, Hilos::$sr->getNextQueuedSignal()?->signalType->getType());
        $chunk = str_repeat('x', self::MEBIBYTE);
        // Seven framed mebibytes stay under the cap, the eighth takes the queue past it.
        for ($sent = 1; $sent <= 7; $sent++) {
            $client->sendFrame($chunk);
            $client->write();
        }
        self::assertFalse($client->shouldClose());
        self::assertSame(0, $client->transportBytes());
        self::assertSame('', $this->readBytes($peer));

        $client->sendFrame($chunk);
        self::assertTrue($client->shouldClose());
        $client->write();
        self::assertSame('', $this->readBytes($peer));
        $client->close();
        self::assertFalse($client->hasPendingWrite());
    }

    public function testABrowserThatAnswersPingsButReadsNothingIsDroppedWhileTheOtherSocketLives(): void
    {
        $server = new CapTestServer();
        $this->servers[] = $server;
        [$stuck, $stuckBrowser] = $this->handshaken($server);
        [$live, $liveBrowser] = $this->handshaken($server);
        $chunk = str_repeat('x', self::MEBIBYTE);
        $toasts = [];
        $received = [];
        for ($second = 40; $second < 60 && in_array($stuck, $server->heldClients(), true); $second++) {
            $stuck->now = $second * 1000;
            $live->now = $second * 1000;
            $before = $stuck->outboundBytes();
            $stuck->sendFrame($chunk);
            self::assertSame($before + self::LONG_HEADER_BYTES + self::MEBIBYTE > self::CAP, $stuck->shouldClose());
            $toasts[] = '{"type":"toast","data":' . $second . '}';
            $live->sendFrame(end($toasts));
            $server->onTick();
            $received = [...$received, ...$this->readFrames($liveBrowser)];
            if (in_array($stuck, $server->heldClients(), true)) {
                // An unsolicited pong: the browser keeps answering while it reads nothing it is sent.
                $pong = self::maskedFrame(0x8A, '');
                self::assertSame(strlen($pong), socket_write($stuckBrowser, $pong));
            }
        }
        self::assertSame([$live], $server->heldClients());
        $closes = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === SignalTypeConstants::CONNECTION_CLOSE) {
                self::assertInstanceOf(WebSocketCloseSignalDTO::class, $signal->data);
                $closes[] = $signal->data->acceptKey;
            }
        }
        self::assertSame([$stuck->acceptKey], $closes);

        $live->now = 61000;
        $toasts[] = '{"type":"toast","data":61}';
        $live->sendFrame(end($toasts));
        $server->onTick();
        $received = [...$received, ...$this->readFrames($liveBrowser)];
        $texts = array_column(array_filter($received, static fn (array $frame): bool => $frame['opcode'] === 1), 'text');
        self::assertSame($toasts, $texts);
        self::assertSame([$live], $server->heldClients());
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

    /**
     * @param DeliveryTestClient $client Connection the bytes go to
     * @param string $payload Payload past the 16-bit frame length
     * @param bool $queued Whether it waits in the delivery queue rather than the transport buffer
     */
    private function sendLarge(DeliveryTestClient $client, string $payload, bool $queued): void
    {
        if ($queued) {
            $client->sendFrame($payload);
        } else {
            $client->sendFrameBinary($payload);
        }
    }

    /** @return string Every byte the client writes until nothing is left, read by a browser that keeps up */
    private function pump(WebSocketClient $client, Socket $peer): string
    {
        $bytes = '';
        for ($turn = 0; $turn < 1000 && ($turn === 0 || $client->hasPendingWrite()); $turn++) {
            $client->write();
            $bytes .= $this->readBytes($peer);
        }
        self::assertFalse($client->hasPendingWrite());

        return $bytes;
    }

    /**
     * @param CapTestServer $server Server that holds the connection
     * @return array{CapTestClient, Socket} Connection whose real handshake completed at moment 0, its 101 and welcome read
     */
    private function handshaken(CapTestServer $server): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new CapTestClient($pair[0]);
        $server->seedClient($client);
        $this->servedBrowsers[] = $pair[1];
        $request = "GET /ws HTTP/1.1\r\nHost: localhost:8092\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
        self::assertSame(strlen($request), socket_write($pair[1], $request));
        $server->onTick();
        self::assertStringStartsWith('HTTP/1.1 101', $this->readBytes($pair[1]));
        self::assertSame(SignalTypeConstants::HANDSHAKE, Hilos::$sr->getNextQueuedSignal()?->signalType->getType());

        return [$client, $pair[1]];
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

    /** @return string Every byte waiting on the browser end */
    private function readBytes(Socket $peer): string
    {
        $bytes = '';
        while (($chunk = (new PlainSocketTransport($peer))->read(65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @return list<array{opcode: int, text: string}> Complete RFC frames actually written to the browser socket */
    private function readFrames(Socket $peer): array
    {
        return self::parseFrames($this->readBytes($peer));
    }

    /** @return list<array{opcode: int, text: string}> Complete RFC frames in the given bytes */
    private static function parseFrames(string $bytes): array
    {
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

    /** @return int Bytes the outbound cap is held against */
    public function outboundBytes(): int
    {
        return $this->outboundBackpressureBytes();
    }

    /** @return int Bytes framed in the transport buffer */
    public function transportBytes(): int
    {
        return strlen($this->writeBuffer);
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

/** Server whose browsers are handed to it by the test, ticked by its real loop and dropped through its real door. */
final class CapTestServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @param CapTestClient $client Browser this server now holds */
    public function seedClient(CapTestClient $client): void
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
        throw new SocketException('the delivery test accepts no clients');
    }
}

/** Real client through a real handshake, so its liveness runs; only its clock and peer address are the test's. */
final class CapTestClient extends WebSocketClient
{
    public int $now = 0;

    /** @return int Bytes the outbound cap is held against */
    public function outboundBytes(): int
    {
        return $this->outboundBackpressureBytes();
    }

    protected function deliveryNowMs(): int
    {
        return $this->now;
    }

    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /**
     * @param array<string, string> $headers Unused handshake headers
     * @param string $acceptKey Unused connection key
     * @param array<string, string> $cookies Unused cookies
     * @param ?string $clientIp Unused client address
     * @param RequestQueryParams $queryParams Unused query
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
