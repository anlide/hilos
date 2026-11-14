<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Generator;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\CommandSignalCancellation;
use Hilos\Core\Daemon\BrowserDeliveryTarget;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\LocalClientSignalBatch;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\Delivery\DeliveryQueue;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

require_once __DIR__ . '/WebSocketClientDeliveryTest.php';

/** The same immutable prepared frame must reach every recipient on every master delivery door. */
final class DaemonManagerDeliveryTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    /** @var list<array{DeliveryTestClient, Socket}> Real browser connections */
    private array $connections = [];
    private DeliveryTestManager $manager;
    private DeliveryTestServer $server;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $this->manager = new DeliveryTestManager();
        $this->server = new DeliveryTestServer();
        $this->manager->registerServer($this->server);
        $this->manager->registerServer(new DeliveryTestWorkerServer());
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

    /** @return iterable<string, array{string, bool}> Local dispatch and forwarded fan-out for every addressing form */
    public static function fanouts(): iterable
    {
        foreach ([SignalTypeConstants::WS_ALL_CONNECTED, SignalTypeConstants::WS_ALL,
            SignalTypeConstants::WS_GROUP, SignalTypeConstants::WS_SESSION, SignalTypeConstants::WS_USER] as $type) {
            yield $type . '-local' => [$type, false];
            yield $type . '-forwarded' => [$type, true];
        }
    }

    #[DataProvider('fanouts')]
    public function testOnePreparationIsReusedWithAddressingFilters(string $type, bool $forwarded): void
    {
        $first = $this->connect('session-a', 'g');
        $second = $this->connect('session-a', 'g');
        $excluded = $this->connect('session-b', 'g');
        $outsider = $this->connect(null, 'other');
        $payload = new DeliveryTestPayload();
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType($type),
            new SignalName('delivery_marker'),
            new WebSocketSignalData(
                data: $payload,
                targetAcceptKey: $first->acceptKey,
                targetSessionTokenHash: 'session-a',
                targetGroup: 'g',
                excludeAcceptKey: $excluded->acceptKey,
            ),
        );
        if ($forwarded) {
            $this->manager->deliverFanoutToClients('peer', $signal);
        } else {
            Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        self::assertSame(1, $payload->encodes);
        self::assertSame(1, $this->manager->encodes);
        self::assertCount(1, $first->prepared);
        if ($type === SignalTypeConstants::WS_USER) {
            self::assertSame([], $second->prepared);
        } else {
            self::assertCount(1, $second->prepared);
            self::assertSame($first->prepared[0], $second->prepared[0]);
        }
        self::assertSame([], $excluded->prepared);
        self::assertCount(in_array($type, [SignalTypeConstants::WS_ALL_CONNECTED, SignalTypeConstants::WS_ALL], true) ? 1 : 0, $outsider->prepared);
        self::assertSame('{"type":"delivery_marker","data":{"value":42}}', $first->prepared[0]->json);
        foreach ($this->connections as [$client, $peer]) {
            $client->write();
            $transport = new PlainSocketTransport($peer);
            $bytes = $transport->read(4096);
            if ($client->prepared === []) {
                self::assertSame('', $bytes);
                self::assertFalse($transport->isEndOfStream());
            } else {
                self::assertStringContainsString($first->prepared[0]->json, $bytes);
            }
        }
    }

    public function testRemoteAddressedDeliveryUsesThePreparedBoundary(): void
    {
        $client = $this->connect(null, 'g');
        $payload = new DeliveryTestPayload();
        $this->manager->deliverSignalToClient($client->acceptKey, new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('delivery_marker'), new WebSocketSignalData($payload, targetAcceptKey: $client->acceptKey),
        ));
        self::assertSame(1, $payload->encodes);
        self::assertSame(1, $this->manager->encodes);
        self::assertCount(1, $client->prepared);
        self::assertTrue($client->hasPendingWrite());
    }

    /** @return iterable<string, array{string}> Local and serialized addressed receive paths */
    public static function completionPaths(): iterable
    {
        yield 'local' => ['local'];
        yield 'worker' => ['worker'];
        yield 'peer' => ['peer'];
    }

    /** @param string $path Master dispatch or serialized worker/peer delivery */
    #[DataProvider('completionPaths')]
    public function testSessionAnswerDoesNotDelayTheBrowsersFirstPageSubscribe(string $path): void
    {
        $client = $this->connect(null, 'g');
        $other = $this->connect(null, 'g');
        $browser = new PlainSocketTransport($this->connections[0][1]);
        $otherBrowser = new PlainSocketTransport($this->connections[1][1]);
        foreach ([$client, $other] as $socket) {
            $socket->sendFrame('{"type":"hilos_session_toasts","data":{}}');
            $socket->write();
            $socket->now = 100;
            $socket->sendFrame('{"type":"guest_identity","data":{}}');
        }
        self::assertIsString($browser->read(4096));
        self::assertIsString($otherBrowser->read(4096));

        // The sessions holder answers first; the project's identity arrives in a later
        // worker message. The browser cannot send page_subscribe until it receives this.
        $this->deliver($path, new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('project_session_answer'),
            new WebSocketSignalData(new HandshakeResponseSignalData(), targetAcceptKey: $client->acceptKey),
        ));
        $client->write();
        $other->write();
        $bytes = $browser->read(4096);
        self::assertIsString($bytes, 'The session answer must leave before the 1000 ms cadence deadline.');
        self::assertStringContainsString('signal_batch', $bytes);
        self::assertStringContainsString('guest_identity', $bytes);
        self::assertStringContainsString('project_session_answer', $bytes);
        self::assertSame('', $otherBrowser->read(4096));
        self::assertFalse($otherBrowser->isEndOfStream());

        // A late session answer starts grace at the answer, not at the socket's opening.
        $client->now = 1099;
        $client->sendFrame('{"type":"late_session_state","data":{}}');
        $client->write();
        self::assertIsString($browser->read(4096));
        $client->now = 1100;
        $client->sendFrame('{"type":"background_state","data":{}}');
        $client->write();
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());

        // Once session-gated navigation can leave the browser, even a master refusal
        // completes immediately with accumulated state rather than adding another window.
        $client->acceptJson('{"type":"page_subscribe","page":"unserved"}');
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        $client->write();
        $bytes = $browser->read(4096);
        self::assertIsString($bytes);
        self::assertStringContainsString('background_state', $bytes);
        self::assertStringContainsString('subscription_page_error', $bytes);
        self::assertStringNotContainsString('hilos_delivery_complete', $bytes);
    }

    /** @param string $path Master dispatch or serialized worker/peer delivery */
    #[DataProvider('completionPaths')]
    public function testSessionAnswerCannotReleaseAnIncompletePageSubscription(string $path): void
    {
        $client = $this->connect(null, 'g');
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        $token = Hilos::$sr->getNextQueuedSignal()->data->deliveryId;
        $client->sendFrame('{"type":"page_response","data":{"page":"p","payload":{}}}');
        $this->deliver($path, new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('project_session_answer'),
            new WebSocketSignalData(new HandshakeResponseSignalData(), targetAcceptKey: $client->acceptKey),
        ));
        $client->write();
        $browser = new PlainSocketTransport($this->connections[0][1]);
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());
        $client->sendFrame('{"type":"table_facet_counts","data":{}}');
        $this->complete($path, $client->acceptKey, $token);
        $client->write();
        $bytes = $browser->read(4096);
        self::assertIsString($bytes);
        self::assertStringContainsString('page_response', $bytes);
        self::assertStringContainsString('project_session_answer', $bytes);
        self::assertStringContainsString('table_facet_counts', $bytes);
        self::assertStringNotContainsString('hilos_delivery_complete', $bytes);
    }

    #[DataProvider('completionPaths')]
    public function testCompletionReleasesOnlyItsMatchingQueueBeforeEncoding(string $path): void
    {
        $client = $this->connect(null, 'g');
        $other = $this->connect(null, 'g');
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        $current = Hilos::$sr->getNextQueuedSignal()->data->deliveryId;
        $other->acceptJson('{"type":"page_subscribe","page":"p"}');
        Hilos::$sr->getNextQueuedSignal();
        $queue = new ReflectionProperty(WebSocketClient::class, 'deliveryQueue')->getValue($client);
        $otherQueue = new ReflectionProperty(WebSocketClient::class, 'deliveryQueue')->getValue($other);
        $client->sendFrame('{"type":"page_response","data":{"page":"p","payload":{}}}');
        $client->sendFrame('{"type":"table_facet_counts","data":{}}');
        $client->write();
        self::assertTrue($queue->isHeld());
        $browser = new PlainSocketTransport($this->connections[0][1]);
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());

        $this->complete($path, $client->acceptKey, 'old');
        self::assertTrue($queue->isHeld());
        $client->write();
        $browser = new PlainSocketTransport($this->connections[0][1]);
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());
        $client->now = 500;
        $this->complete($path, $client->acceptKey, $current);
        self::assertFalse($queue->isHeld());
        self::assertTrue($otherQueue->isHeld());
        self::assertSame(0, $this->manager->encodes, 'A completion never reaches the browser encoder.');
        self::assertCount(2, $client->prepared);
        $client->write();
        $bytes = new PlainSocketTransport($this->connections[0][1])->read(4096);
        self::assertIsString($bytes);
        self::assertStringContainsString('signal_batch', $bytes);
        self::assertStringContainsString('page_response', $bytes);
        self::assertStringContainsString('table_facet_counts', $bytes);
        self::assertStringNotContainsString('hilos_delivery_complete', $bytes);
        self::assertStringNotContainsString($current, $bytes);
        $client->now = 700;
        $this->complete($path, $client->acceptKey, $current);
        self::assertSame(0, $this->manager->encodes);
        $client->now = 1500;
        $client->sendFrame('past original grace');
        $client->write();
        self::assertSame('past original grace', substr(new PlainSocketTransport($this->connections[0][1])->read(4096), 2));
        // The quiet gap above is a full window; a second frame inside that window must wait.
        $client->now = 1600;
        $this->complete($path, $client->acceptKey, $current);
        $client->sendFrame('duplicate cannot reopen grace');
        $client->write();
        $browser = new PlainSocketTransport($this->connections[0][1]);
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());
    }

    public function testMasterEarlyRefusalCompletesOnlyAfterItsQueuedError(): void
    {
        $client = $this->connect(null, 'g');
        $client->acceptJson('{"type":"page_subscribe","page":"unserved"}');
        $queue = new ReflectionProperty(WebSocketClient::class, 'deliveryQueue')->getValue($client);
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        self::assertFalse($queue->isHeld());
        self::assertSame(1, $this->manager->encodes);
        self::assertCount(1, $client->prepared);
        $client->write();
        $bytes = new PlainSocketTransport($this->connections[0][1])->read(4096);
        self::assertIsString($bytes);
        self::assertStringContainsString('subscription_page_error', $bytes);
        self::assertStringNotContainsString('signal_batch', $bytes);
        self::assertStringNotContainsString('hilos_delivery_complete', $bytes);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}> Structural recovery identities */
    public static function recoveryParams(): iterable
    {
        yield 'same nested maps in another order' => [
            ['id' => 1, 'options' => ['a' => true, 'b' => [1, '2']]],
            ['options' => ['b' => [1, '2'], 'a' => true], 'id' => 1], true,
        ];
        yield 'new id' => [['id' => 2], ['id' => 1], false];
        yield 'integer and string' => [['id' => 1], ['id' => '1'], false];
        yield 'integer and boolean' => [['id' => 1], ['id' => true], false];
        yield 'boolean and null' => [['id' => false], ['id' => null], false];
        yield 'null and absent' => [['id' => null], [], false];
        yield 'array order' => [['id' => [1, 2]], ['id' => [2, 1]], false];
        yield 'array and map' => [['id' => [1, 2]], ['id' => ['a' => 1, 'b' => 2]], false];
        yield 'nested scalar types' => [['id' => ['value' => 1]], ['id' => ['value' => '1']], false];
        yield 'no params' => [[], [], true];
    }

    /**
     * @param array<string, mixed> $current Accepted subscription params
     * @param array<string, mixed> $recovery Delayed control params
     * @param bool $matching Whether recovery names the current subscription
     */
    #[DataProvider('recoveryParams')]
    public function testRecoveryCancelsOnlyTheCurrentStructuralIdentity(array $current, array $recovery, bool $matching): void
    {
        $client = $this->connect(null, 'g');
        $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => 'p', 'params' => $current], JSON_THROW_ON_ERROR));
        $request = Hilos::$sr->getNextQueuedSignal();
        Hilos::$sr->subscribeToPage('p', $request->data);
        new ReflectionMethod(DaemonManager::class, 'acceptPageDeliveryControl')->invoke($this->manager, new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::PAGE_RESUBSCRIBE),
            new WebSocketSignalData(new PageResubscribeSignalData('p', $recovery), targetAcceptKey: $client->acceptKey),
        ));
        self::assertSame(!$matching, $client->isDeliveryPending('p', $request->data->deliveryId));
    }

    #[DataProvider('completionPaths')]
    public function testCompletionWithoutATrackedQueueNeverReachesBrowserBytes(string $path): void
    {
        $client = $this->connect(null, 'g');
        $this->complete($path, $client->acceptKey, 'absent');
        self::assertSame(0, $this->manager->encodes);
        self::assertSame([], $client->prepared);
        $client->write();
        $browser = new PlainSocketTransport($this->connections[0][1]);
        self::assertSame('', $browser->read(4096));
        self::assertFalse($browser->isEndOfStream());
    }

    private function complete(string $path, string $acceptKey, string $token): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData('p', $token), targetAcceptKey: $acceptKey),
        );
        $this->deliver($path, $signal);
    }

    /**
     * @param string $path Local dispatch, worker IPC, or addressed peer transport
     * @param SignalDTO $signal Prepared worker signal before transport serialization
     */
    private function deliver(string $path, SignalDTO $signal): void
    {
        $acceptKey = $signal->data->targetAcceptKey;
        if ($path === 'worker') {
            $frame = new WorkerAgentMessageDTO('owner', $signal);
            $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
            self::assertInstanceOf(WorkerAgentMessageDTO::class, $restored);
            $signal = $restored->signal;
        } elseif ($path === 'peer') {
            $frame = new PeerClientSignalDTO('owner-node', 'socket-node', $acceptKey, $signal);
            $restored = PeerDTO::fromWire($frame->toJson());
            self::assertInstanceOf(PeerClientSignalDTO::class, $restored);
            $this->manager->deliverSignalToClient($acceptKey, $restored->signal);
            return;
        }
        Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
    }

    public function testAcceptConnectionPassesTheRegisteredLiveMirrorToTheClient(): void
    {
        $server = new DeliveryTestServer();
        $mirror = new DeliverySettingsMirror();
        $server->setDeliverySettingsMirror($mirror);
        self::assertTrue($server->start());
        self::assertTrue(socket_getsockname($server->getSocket(), $address, $port));
        $browser = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($browser);
        try {
            self::assertTrue(socket_connect($browser, $address, $port));
            $client = $server->acceptConnection();
            self::assertInstanceOf(DeliveryTestClient::class, $client);
            self::assertSame($mirror, new ReflectionProperty(WebSocketClient::class, 'deliverySettingsMirror')->getValue($client));
            $server->dropClient($client);
        } finally {
            socket_close($browser);
            $server->stop();
        }
    }

    public function testSessionCursorVisitsOnlyIndexedCandidatesInConnectionOrder(): void
    {
        for ($index = 0; $index < 65; $index++) {
            $this->connect('other-session', 'g');
        }
        $target = $this->connect('target-session', 'g');
        $last = $this->connect('target-session', 'g');
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_SESSION),
            new SignalName('marker'), new WebSocketSignalData(new SignalData(), targetSessionTokenHash: 'target-session'));
        $frame = DeliveryFrame::fromJson('{"type":"marker","data":{}}');
        $walk = new ReflectionMethod(DaemonManager::class, 'clientFrameDelivery')->invoke(
            $this->manager, $this->server, $frame, null, 'target-session',
        );
        $dispatch = new SignalDispatch($signal, static fn() => $walk);
        $dispatch->runPortion(1);
        self::assertSame([$frame], $target->prepared);
        self::assertSame([], $last->prepared);
        $future = $this->connect('target-session', 'g');
        $dispatch->runPortion(1);
        self::assertSame([$frame], $last->prepared);
        $dispatch->runPortion(64);
        self::assertFalse($dispatch->hasWork());
        self::assertSame([$frame], $target->prepared);
        self::assertSame([], $future->prepared);
        foreach (array_slice($this->connections, 0, 65) as [$client]) {
            self::assertSame([], $client->prepared);
        }
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testClientCursorRetainsSnapshotAndRejectsDepartedOrReplacedSockets(): void
    {
        $first = $this->connect(null, 'g');
        $departed = $this->connect(null, 'g');
        $last = $this->connect(null, 'g');
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('marker'), new WebSocketSignalData(new SignalData()));
        $frame = DeliveryFrame::fromJson('{"type":"marker","data":{}}');
        $dispatch = new SignalDispatch($signal, fn() => new ReflectionMethod(DaemonManager::class, 'clientFrameDelivery')->invoke(
            $this->manager, $this->server, $frame,
        ));
        $dispatch->runPortion(1);
        self::assertSame([$frame], $first->prepared);
        $this->server->removeClient($departed);
        $replacement = $this->connect(null, 'g');
        $replacement->acceptKey = $departed->acceptKey;
        $dispatch->runPortion(1);
        self::assertSame([], $departed->prepared);
        self::assertSame([], $replacement->prepared);
        $dispatch->runPortion(1);
        self::assertSame([$frame], $last->prepared);
        $dispatch->runPortion(64);
        self::assertFalse($dispatch->hasWork());
        self::assertSame([], $replacement->prepared);
        self::assertSame([$frame], $first->prepared);
    }

    public function testAddressedLookupAndSessionCollectionBothAvoidTheUnrelatedRoster(): void
    {
        for ($index = 0; $index < 65; $index++) {
            $this->connect('other', 'g');
        }
        $target = $this->connect('target', 'g');
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('marker'), new WebSocketSignalData(new SignalData(), targetAcceptKey: $target->acceptKey));
        $lookup = new ReflectionMethod(DaemonManager::class, 'clientLookup')->invoke($this->manager, $this->server, $target->acceptKey);
        $keys = new ReflectionMethod(DaemonManager::class, 'sessionAcceptKeyCandidates')->invoke($this->manager, $this->server, 'target');
        $lookupDispatch = new SignalDispatch($signal, static fn() => $lookup);
        $keysDispatch = new SignalDispatch($signal, static fn() => $keys);
        $lookupDispatch->runPortion(1);
        $keysDispatch->runPortion(1);
        self::assertFalse($lookupDispatch->hasWork());
        self::assertSame($target, $lookup->getReturn()?->getClient());
        self::assertSame(0, $this->server->rosterCaptures, 'Both addressing forms use their scalar indexes');
        self::assertTrue($keysDispatch->hasWork());
        $this->connect('target', 'g');
        $keysDispatch->runPortion(64);
        self::assertFalse($lookupDispatch->hasWork());
        self::assertFalse($keysDispatch->hasWork());
        self::assertSame($target, $lookup->getReturn()?->getClient());
        self::assertSame([$target->acceptKey], $keys->getReturn());
    }

    public function testSessionIndexMergesLateHandshakesWithoutChangingTransportOrderOrCutoff(): void
    {
        $first = $this->connect('target', 'g');
        $handshaking = $this->connect(null, 'g');
        $handshaking->acceptKey = '';
        $last = $this->connect('target', 'g');
        $clients = $this->server->sessionClientCandidates('target');
        self::assertSame($first, $clients->current());
        new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($handshaking, 'target');
        $handshaking->acceptKey = 'late-handshake';
        $this->connect('target', 'g');
        $clients->next();
        self::assertSame($handshaking, $clients->current());
        $clients->next();
        self::assertSame($last, $clients->current());
        $clients->next();
        self::assertFalse($clients->valid());
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testSessionIndexCanFindItsFirstMemberInAnOriginalUnfinishedHandshake(): void
    {
        $handshaking = $this->connect(null, 'g');
        $handshaking->acceptKey = '';
        $clients = $this->server->sessionClientCandidates('target');
        new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($handshaking, 'target');
        $handshaking->acceptKey = 'first-member';
        self::assertSame([$handshaking], iterator_to_array($clients, false));
        $empty = $this->server->sessionClientCandidates('absent');
        $this->connect('absent', 'g');
        self::assertSame([], iterator_to_array($empty, false));
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testSessionCursorDoesNotKeepDepartedPayloadsOrAnObsoleteSessionBucket(): void
    {
        $original = $this->connect('target', 'g');
        $clients = $this->server->sessionClientCandidates('target');
        $reference = WeakReference::create($original);
        $this->server->removeClient($original);
        $original->close();
        [, $peer] = array_pop($this->connections);
        socket_close($peer);
        unset($original);
        self::assertNull($reference->get(), 'An unadvanced session cursor must retain only scalar positions');
        self::assertSame([], new ReflectionProperty(WebSocketServer::class, 'sessionClients')->getValue($this->server));
        $this->connect('target', 'g');
        self::assertSame([], iterator_to_array($clients, false));
    }

    public function testIndexedTargetRejectsRekeyDepartureAndSameKeyReplacement(): void
    {
        $client = $this->connect(null, 'g');
        $key = $client->acceptKey;
        $target = $this->server->clientTarget($key);
        self::assertSame($client, $target?->getClient());
        $client->acceptKey = 'other-key';
        self::assertNull($this->server->clientTarget($key));
        self::assertNull($target->getClient());
        self::assertSame($client, $this->server->clientTarget('other-key')?->getClient());
        $client->acceptKey = $key;
        self::assertNull($target->getClient(), 'Returning to an old key creates a new address incarnation');
        $current = $this->server->clientTarget($key);
        $replacement = $this->connect(null, 'g');
        $replacement->acceptKey = $key;
        self::assertNull($current->getClient());
        self::assertSame($replacement, $this->server->clientTarget($key)?->getClient());
        $this->server->removeClient($client);
        self::assertSame($replacement, $this->server->clientTarget($key)?->getClient());
        $current = $this->server->clientTarget($key);
        $this->server->removeClient($replacement);
        self::assertNull($current->getClient());
        self::assertNull($this->server->clientTarget($key));
        $this->server->attach($replacement);
        self::assertNull($current->getClient());
        self::assertSame($replacement, $this->server->clientTarget($key)?->getClient());
        $replacement->markShouldClose();
        self::assertNull($this->server->clientTarget($key));
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testIndexedLookupCapturesBeforeYieldAndCannotFindALaterReplacement(): void
    {
        $client = $this->connect(null, 'g');
        $lookup = new ReflectionMethod(DaemonManager::class, 'clientLookup')->invoke($this->manager, $this->server, $client->acceptKey);
        $this->server->removeClient($client);
        $replacement = $this->connect(null, 'g');
        $replacement->acceptKey = $client->acceptKey;
        foreach ($lookup as $_) {
        }
        self::assertNull($lookup->getReturn());
        self::assertSame($replacement, $this->server->clientTarget($client->acceptKey)?->getClient());
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testEmptyMaintenanceReservationsBelongToDelivery(): void
    {
        $steps = 0;
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL),
            new SignalName('marker'), new WebSocketSignalData(new SignalData()));
        $dispatch = new SignalDispatch($signal, static function () use (&$steps): Generator {
            yield;
            for ($index = 0; $index < 200; $index++) {
                $steps++;
                yield;
            }
        });
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->manager);
        $state->active = $dispatch;
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        self::assertSame(64, $steps);
        self::assertTrue($dispatch->hasWork());
    }

    public function testProtectedBroadcastRetainsOneFifoObligationAndEncodesOnceAcrossPortions(): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('before'), new WebSocketSignalData(new SignalData()));
        $state = new ProtectedModeStateSignalData(active: true);
        $reference = WeakReference::create($state);
        $this->manager->notifyProtectedModeState($state, null, null);
        unset($state);
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('after'), new WebSocketSignalData(new SignalData()));
        self::assertSame(3, Hilos::$sr->getQueuedSignalCount(), 'Admission cannot expand the transport roster synchronously');
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        for ($portion = 0; $portion < 32 && count($clients[0]->prepared) < 2; $portion++) {
            $dispatch->invoke($this->manager);
        }
        self::assertCount(2, $clients[0]->prepared);
        self::assertCount(1, $clients[129]->prepared, 'The later physical recipients remain retained');
        self::assertNotNull($reference->get(), 'The original batch still owns its actual payload');
        $future = $this->connect(null, 'g');
        for ($portion = 0; $portion < 64 && new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch')
            ->invoke($this->manager); $portion++) {
            $dispatch->invoke($this->manager);
        }
        self::assertNull($reference->get(), 'Completed physical delivery releases its original payload without GC');
        self::assertSame(3, $this->manager->encodes);
        foreach ($clients as $client) {
            self::assertSame(['before', SignalTypeConstants::PROTECTED_MODE, 'after'], array_map(
                static fn(DeliveryFrame $frame): string => json_decode($frame->json, true)['type'], $client->prepared,
            ));
            self::assertSame($clients[0]->prepared[1], $client->prepared[1]);
        }
        self::assertCount(1, $future->prepared);
        self::assertSame('after', json_decode($future->prepared[0]->json, true)['type']);
        self::assertSame(2, $this->server->rosterCaptures, 'Only the two ordinary all-connected broadcasts use that entrance');
    }

    public function testFinalBrowserPrefixIncludesTheWholeProtectedBatchAndNotLaterNotifications(): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $this->manager->notifyProtectedModeState(new ProtectedModeStateSignalData(active: true), null, null);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($this->manager, microtime(true));
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $release = new ReflectionMethod(DaemonManager::class, 'releaseBrowsersOnceWorkersLeft');
        $released = new ReflectionProperty(DaemonManager::class, 'browsersReleased');
        $dispatch->invoke($this->manager);
        $release->invoke($this->manager);
        self::assertFalse($released->getValue($this->manager));
        self::assertSame([], $clients[129]->prepared);
        for ($portion = 0; $portion < 32 && !$released->getValue($this->manager); $portion++) {
            $this->manager->notifyProtectedModeState(new ProtectedModeStateSignalData(active: false), null, null);
            $dispatch->invoke($this->manager);
            $release->invoke($this->manager);
        }
        self::assertTrue($released->getValue($this->manager));
        self::assertTrue(Hilos::$sr->hasQueuedSignals(), 'Later batches cannot extend the captured complete prefix');
        foreach ($clients as $client) {
            self::assertNotEmpty($client->prepared);
            self::assertTrue(json_decode($client->prepared[0]->json, true)['data']['active']);
            $client->write();
            self::assertTrue($client->shouldClose());
        }
    }

    public function testALaterLocalBatchFailureCannotFailTheCapturedBrowserPrefix(): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $this->manager->notifyProtectedModeState(new ProtectedModeStateSignalData(active: true), null, null);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($this->manager, microtime(true));
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $release = new ReflectionMethod(DaemonManager::class, 'releaseBrowsersOnceWorkersLeft');
        $dispatch->invoke($this->manager);
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->manager);
        $prefix = $state->browsers;
        self::assertFalse($prefix->complete);
        $failure = new RuntimeException('later local batch failed');
        $targets = static function () use ($failure): Generator {
            yield null;
            throw $failure;
        };
        Hilos::$sr->queueLocalClientSignals(new LocalClientSignalBatch(new SignalName('later'), new SignalData(), $targets(),
            static fn(int $slot): ?BrowserDeliveryTarget => null));
        $failures = [];
        for ($portion = 0; $portion < 64 && (!$prefix->complete || $failures === []); $portion++) {
            try {
                $dispatch->invoke($this->manager);
            } catch (RuntimeException $actual) {
                $failures[] = $actual;
            }
            $release->invoke($this->manager);
        }
        self::assertSame([$failure], $failures);
        self::assertFalse($prefix->failed);
        self::assertTrue($prefix->complete);
        self::assertTrue(new ReflectionProperty(DaemonManager::class, 'browsersReleased')->getValue($this->manager));
        foreach ($clients as $client) {
            self::assertCount(1, $client->prepared);
            self::assertTrue(json_decode($client->prepared[0]->json, true)['data']['active']);
        }
    }

    public function testAnUnconsumedLocalBatchCannotKeepItsOriginalServerOrRosterAlive(): void
    {
        $server = new DeliveryTestServer();
        $client = $this->connect(null, 'g');
        $server->attach($client);
        $batch = $server->localClientSignals(new SignalName('marker'), new SignalData(), static fn(): bool => true);
        $reference = WeakReference::create($server);
        unset($server);
        self::assertNull($reference->get(), 'A queued scalar cohort must not retain its former transport owner');
        self::assertNull($batch->consumeTarget());
        self::assertFalse($batch->isComplete());
        self::assertNull($batch->consumeTarget());
        self::assertTrue($batch->isComplete());
        self::assertSame([], $client->prepared);
    }

    public function testProtectedAdmissionRejectsRekeyedAndReplacementTransportsBeforeTheirVisit(): void
    {
        $rekeyed = $this->connect('target', 'g');
        $departed = $this->connect('target', 'g');
        $survivor = $this->connect('target', 'g');
        $this->manager->notifyProtectedModeSessionState(new ProtectedModeStateSignalData(active: true), 'target');
        $originalKey = $rekeyed->acceptKey;
        $rekeyed->acceptKey = 'changed';
        $rekeyed->acceptKey = $originalKey;
        $this->server->removeClient($departed);
        $replacement = $this->connect('target', 'g');
        $replacement->acceptKey = $departed->acceptKey;
        for ($portion = 0; $portion < 8; $portion++) {
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        }
        self::assertSame([], $rekeyed->prepared);
        self::assertSame([], $departed->prepared);
        self::assertSame([], $replacement->prepared);
        self::assertCount(1, $survivor->prepared);
        self::assertSame(SignalTypeConstants::PROTECTED_MODE, json_decode($survivor->prepared[0]->json, true)['type']);
        self::assertSame(0, $this->server->rosterCaptures);
    }

    public function testBudgetedBroadcastLoadReturnsToNativeIoAndDueOwners(): void
    {
        $clients = [];
        for ($index = 0; $index < 256; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        for ($broadcast = 0; $broadcast < 8; $broadcast++) {
            Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL),
                new SignalName('marker-' . $broadcast), new WebSocketSignalData(new DeliveryTestPayload()));
        }
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->manager);
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $reads = 0;
        $due = 0;
        $loop->registerRead($pair[0], static function () use ($pair, &$reads): void {
            self::assertSame('x', socket_read($pair[0], 1));
            $reads++;
        });
        $timer = $loop->createTimer(static function () use (&$due): void {
            $due++;
        });
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
        $iterations = 0;
        try {
            while ($runnable->invoke($this->manager)) {
                self::assertLessThan(512, $iterations, 'The finite WS_ALL cohort must keep progressing');
                socket_write($pair[1], 'x');
                $timer->armAt(0.0);
                $loop->tick();
                self::assertSame($iterations + 1, $reads);
                self::assertSame($iterations + 1, $due);
                $dispatch->invoke($this->manager);
                $iterations++;
            }
            self::assertGreaterThan(1, $iterations, 'The load must yield between guarded dispatch budgets');
            self::assertSame($iterations, $reads);
            self::assertSame($iterations, $due);
            self::assertSame(8, $this->manager->encodes);
            self::assertSame(0, $this->server->rosterCaptures, 'WS_ALL addresses every original member through the index');
            foreach ($clients as $client) {
                self::assertCount(8, $client->prepared);
                foreach ($client->prepared as $index => $frame) {
                    self::assertSame($clients[0]->prepared[$index], $frame);
                    self::assertSame('marker-' . $index, json_decode($frame->json, true)['type']);
                }
            }
        } finally {
            $timer->close();
            $loop->unregister($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->manager);
            $loop->close();
        }
    }

    /**
     * Agents stopping and starting by the dozen release far more between two master rounds than a
     * round's retirement share retires, and the backlog took the master past its memory limit. A
     * round retires a bounded portion of what lies beyond the bound first, also when its own signal
     * work outlasts its deadline and it gets one share; the excess keeps the dispatch cued, and the
     * rounds bring the shared queue down to the bound. The rest stays with the share and keeps the
     * dispatch cued.
     */
    public function testAMasterRoundRetiresABurstBeyondTheBoundBeforeItsShare(): void
    {
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->manager);
        $arena = RtRowArena::process();
        try {
            $burst = [];
            for ($agent = 0; $agent < RtRowArena::SHARED_BACKLOG_RELEASES + 1024; $agent++) {
                $burst[] = RtRowValues::fromNative(['agent' => $agent, 'state' => 'frozen']);
            }
            foreach ($burst as $values) {
                $values->release();
            }
            $burst = [];
            self::assertGreaterThanOrEqual(RtRowArena::SHARED_BACKLOG_RELEASES + 1024, $arena->retention()->pendingReleases);

            $round = false;
            $held = false;
            new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($this->manager,
                static function () use (&$round, &$held): Generator {
                    while (!$round) {
                        yield;
                    }
                    // Longer than the round's 2 ms budget: the round ends after its first share.
                    usleep(3000);
                    $held = true;
                });
            self::assertFalse($held, 'The effect waits for the round');
            $round = true;
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            $pages = $arena->retention()->pages;
            $dispatch->invoke($this->manager);

            self::assertTrue($held, 'The round\'s own signal work outlasted its deadline');
            $retired = $pages - $arena->retention()->pages;
            self::assertGreaterThan(0, $retired, 'The round retired a portion of the burst');
            self::assertLessThanOrEqual(RtRowArena::EXCESS_RETIREMENT_STEPS + RtWorkBudget::DEFAULT_STEPS, $retired,
                'The round spent no more than its excess retirement and its share');
            for ($rounds = 1; $arena->retention()->pendingReleases > RtRowArena::SHARED_BACKLOG_RELEASES; $rounds++) {
                self::assertLessThan(64, $rounds, 'The rounds bring the queue down to its bound');
                self::assertTrue($loop->hasReadyWork(), 'The excess keeps the dispatch cued');
                $dispatch->invoke($this->manager);
            }
            self::assertTrue($arena->hasPendingRetirement(), 'The rest is left to the share');
            self::assertTrue($loop->hasReadyWork(), 'The rest keeps the dispatch cued');
        } finally {
            while ($arena->hasPendingRetirement()) {
                $arena->advanceRetirement(new RtWorkBudget());
            }
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->manager);
            $loop->close();
        }
    }

    /** @return iterable<string, array{bool}> The ordinary router and one-hop receiving paths */
    public static function dispatchOrigins(): iterable
    {
        yield 'ordinary' => [false];
        yield 'forwarded' => [true];
    }

    /** @param bool $forwarded Whether the signal was received as a one-hop peer fan-out */
    #[DataProvider('dispatchOrigins')]
    public function testActualSignalLifetimeKeepsOnePreparedFrameAcrossPhysicalPortions(bool $forwarded): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $payload = new DeliveryTestPayload();
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('delivery_marker'), new WebSocketSignalData($payload));
        $dispatch = $forwarded
            ? new ReflectionMethod(DaemonManager::class, 'createClientFanoutDispatch')->invoke($this->manager, $signal, $this->server)
            : $this->createDispatch($signal);
        $this->advanceDispatch($dispatch, 64);
        $delivered = count(array_filter($clients, static fn(DeliveryTestClient $client): bool => $client->prepared !== []));
        self::assertGreaterThan(0, $delivered);
        self::assertLessThanOrEqual(64, $delivered);
        self::assertSame(SignalDispatchPhase::Destinations, $dispatch->phase);
        while ($dispatch->hasWork()) {
            $this->advanceDispatch($dispatch, 64);
        }
        foreach ($clients as $client) {
            self::assertCount(1, $client->prepared);
            self::assertSame($clients[0]->prepared[0], $client->prepared[0]);
        }
        self::assertSame(1, $this->manager->encodes);
        self::assertSame(1, $payload->encodes);
        self::assertSame(SignalDispatchPhase::Complete, $dispatch->phase);
        self::assertNull($dispatch->frame);
        self::assertNull($dispatch->destinations);
        self::assertNull($dispatch->physical);
    }

    public function testGroupRejoinDuringThePhysicalLookupCannotReceiveTheOldSignal(): void
    {
        for ($index = 0; $index < 65; $index++) {
            $this->connect(null, 'other');
        }
        $target = $this->connect(null, 'g');
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_GROUP),
            new SignalName('delivery_marker'), new WebSocketSignalData(new DeliveryTestPayload(), targetGroup: 'g'));
        $dispatch = $this->createDispatch($signal);
        while ($dispatch->physical === null) {
            self::assertTrue($dispatch->hasWork());
            $this->advanceDispatch($dispatch, 1);
        }
        self::assertTrue(Hilos::$sr->isCurrentClientDestination($dispatch->destinations->current()));
        $this->advanceDispatch($dispatch, 1);
        self::assertSame([], $target->prepared);
        Hilos::$sr->unsubscribeFromGroup('g', new WebSocketGroupUnsubscribeSignalDTO($target->acceptKey, 'g'));
        Hilos::$sr->subscribeToGroup('g', new WebSocketGroupSubscribeSignalDTO($target->acceptKey, 'g'));
        self::assertFalse(Hilos::$sr->isCurrentClientDestination($dispatch->destinations->current()));
        while ($dispatch->hasWork()) {
            $this->advanceDispatch($dispatch, 64);
        }
        self::assertSame([], $target->prepared);
        self::assertEquals([new WebSocketDestination($target->acceptKey)], Hilos::$sr->localClientDestinations($signal));
    }

    public function testConnectionCloseKeepsItsSubscriptionUntilTheTerminalPhaseFinishes(): void
    {
        $client = $this->connect(null, 'g');
        $original = Hilos::$sr->pageSubscription($client->acceptKey);
        $dispatch = $this->createDispatch(new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE), new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO($client->acceptKey)));
        while ($dispatch->phase !== SignalDispatchPhase::Terminal) {
            self::assertTrue($dispatch->hasWork());
            $this->advanceDispatch($dispatch, 1);
            self::assertSame($original, Hilos::$sr->pageSubscription($client->acceptKey));
        }
        while ($dispatch->hasWork()) {
            $this->advanceDispatch($dispatch, 1);
        }
        self::assertNull(Hilos::$sr->pageSubscription($client->acceptKey));
        self::assertSame([], Hilos::$sr->localClientDestinations(new SignalDTO(new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_GROUP), new SignalName('marker'),
            new WebSocketSignalData(new SignalData(), targetGroup: 'g'))));
    }

    public function testANewBrowserNavigationCancelsTheActualSignalBetweenItsPortions(): void
    {
        $client = $this->connect(null, 'g');
        $original = Hilos::$sr->pageSubscription($client->acceptKey);
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_SUBSCRIBE, $signal->signalType->getType());
        $dispatch = $this->createDispatch($signal);
        while ($dispatch->phase === SignalDispatchPhase::Admission) {
            self::assertTrue($dispatch->hasWork());
            $this->advanceDispatch($dispatch, 1);
        }
        self::assertSame(SignalDispatchPhase::Preparation, $dispatch->phase);
        $client->acceptJson('{"type":"page_subscribe","page":"other"}');
        $this->advanceDispatch($dispatch, 64);
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        self::assertFalse($dispatch->hasWork());
        self::assertSame($original, Hilos::$sr->pageSubscription($client->acceptKey));
        self::assertSame('other', $client->pendingPageSubscribe()?->page);
        self::assertSame([], $client->prepared);
    }

    public function testPendingCancellationHidesItsOriginalPageBeforePortionedReleaseAndPreservesOtherPendingPages(): void
    {
        for ($index = 0; $index < 260; $index++) {
            $this->trackPending($index < 130 ? 'live-' . $index : 'cancel-pending', 'p', 'pending-' . $index, ['id' => 1]);
        }
        $this->trackPending('cancel-pending', 'q', 'other-page', ['id' => 2]);
        $this->trackPending('cancel-pending', 'p', 'last-p', ['id' => 3]);
        $last = WeakReference::create(new ReflectionProperty(DaemonManager::class, 'pendingPageDeliveries')
            ->getValue($this->manager)['cancel-pending']['last-p']);
        new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($this->manager, static function (): Generator {
            yield;
            for ($step = 0; $step < 1024; $step++) {
                yield;
            }
        });
        new ReflectionMethod(DaemonManager::class, 'cancelPendingPageDeliveries')->invoke($this->manager, 'cancel-pending', 'p');
        self::assertNotNull($last->get(), 'Pending payload release must wait for its bounded physical step');
        $abandoned = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION),
            new SignalName('p'), new WebSocketPageUpdateSubscriptionSignalDTO('cancel-pending', 'p', deliveryId: 'last-p'));
        self::assertTrue(new ReflectionMethod(DaemonManager::class, 'isAbandonedPageDelivery')->invoke($this->manager, $abandoned, true));
        $current = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::PAGE_RESUBSCRIBE),
            new WebSocketSignalData(new PageResubscribeSignalData('q', ['id' => 2]), targetAcceptKey: 'cancel-pending'));
        self::assertFalse(new ReflectionMethod(DaemonManager::class, 'isStalePageRecovery')->invoke($this->manager, $current));
        $this->trackPending('cancel-pending', 'p', 'future', ['id' => 4]);
        for ($portion = 0; $portion < 128; $portion++) {
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        }
        self::assertNull($last->get());
        $ledger = new ReflectionProperty(DaemonManager::class, 'pendingPageDeliveries')->getValue($this->manager);
        self::assertCount(131, $ledger);
        self::assertSame(['other-page', 'future'], array_keys($ledger['cancel-pending']));
        self::assertSame(['id' => 4], $ledger['cancel-pending']['future']->params);
    }

    public function testAnUnadvancedRecoveryCursorCannotPinAPhysicallyCanceledPendingPayload(): void
    {
        $this->trackPending('cancel-pending', 'p', 'old', ['id' => 1]);
        $reference = WeakReference::create(new ReflectionProperty(DaemonManager::class, 'pendingPageDeliveries')
            ->getValue($this->manager)['cancel-pending']['old']);
        $recovery = new ReflectionMethod(DaemonManager::class, 'pendingPageRecovery')->invoke($this->manager, ['pending_owner']);
        $recovery->current();
        new ReflectionMethod(DaemonManager::class, 'cancelPendingPageDeliveries')->invoke($this->manager, 'cancel-pending');
        self::assertNull($reference->get(), 'A scalar recovery cohort must not retain a completed original record');
        self::assertTrue($recovery->valid());
        while ($recovery->valid()) {
            $recovery->next();
        }
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
    }

    /** @param bool $forwarded Whether the receiving peer entry must queue without delivering */
    #[DataProvider('dispatchOrigins')]
    public function testActualMasterRetainsItsPhysicalRemainderAndWakesOnlyTheGuardedPhase(bool $forwarded): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->manager);
        try {
            $payload = new DeliveryTestPayload();
            $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('delivery_marker'), new WebSocketSignalData($payload));
            if ($forwarded) {
                $this->manager->deliverFanoutToClients('peer', $signal);
            } else {
                Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
            }
            self::assertTrue($loop->hasReadyWork());
            $loop->tick();
            self::assertSame(0, $payload->encodes, 'Native ready callbacks must not deliver');
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
            $sent = count(array_filter($clients, static fn(DeliveryTestClient $client): bool => $client->prepared !== []));
            self::assertGreaterThan(0, $sent);
            self::assertLessThanOrEqual(64, $sent);
            self::assertSame([], $clients[129]->prepared);
            self::assertTrue($loop->hasReadyWork());
            $loop->tick();
            self::assertSame($sent, count(array_filter($clients, static fn(DeliveryTestClient $client): bool => $client->prepared !== [])));
            for ($portion = 0; $portion < 16; $portion++) {
                new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
            }
            self::assertFalse($loop->hasReadyWork());
            foreach ($clients as $client) {
                self::assertCount(1, $client->prepared);
                self::assertSame($clients[0]->prepared[0], $client->prepared[0]);
            }
            self::assertSame(1, $payload->encodes);
            self::assertSame(1, $this->manager->encodes);
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->manager);
            $loop->close();
        }
    }

    public function testAnOldManagerDetachLeavesTheReplacementManagersQueueCueAlive(): void
    {
        $router = Hilos::$sr;
        $oldLoop = new EventLoop();
        $newLoop = new EventLoop();
        $attach = new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop');
        $detach = new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop');
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $oldLoop);
        $attach->invoke($this->manager);
        $replacement = new DeliveryTestManager();
        Hilos::$sr = $router;
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($replacement, $newLoop);
        $attach->invoke($replacement);
        try {
            $detach->invoke($this->manager);
            $payload = new DeliveryTestPayload();
            $router->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('replacement'), new WebSocketSignalData($payload));
            self::assertFalse($oldLoop->hasReadyWork());
            self::assertTrue($newLoop->hasReadyWork());
            $newLoop->tick();
            self::assertSame(0, $payload->encodes);
            self::assertSame(1, $router->getQueuedSignalCount());
            $detach->invoke($this->manager);
            $router->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('later'), new WebSocketSignalData($payload));
            self::assertTrue($newLoop->hasReadyWork());
            self::assertSame(2, $router->getQueuedSignalCount());
        } finally {
            $detach->invoke($replacement);
            $oldLoop->close();
            $newLoop->close();
        }
    }

    public function testFacadeReplacementAndLoopRebindPreserveTheExactRetainedDelivery(): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $loop = new EventLoop();
        $attach = new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop');
        $detach = new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop');
        $drain = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $loop);
        $attach->invoke($this->manager);
        $original = Hilos::$sr;
        try {
            foreach (['first', 'second'] as $name) {
                $original->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                    new SignalName($name), new WebSocketSignalData(new SignalData()));
            }
            $drain->invoke($this->manager);
            self::assertCount(1, $clients[0]->prepared);
            self::assertSame([], $clients[129]->prepared);
            $retained = new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($this->manager)->active;
            self::assertNotNull($retained);
            Hilos::$sr = new SignalRouter();
            $drain->invoke($this->manager);
            self::assertSame(Hilos::$sr, new ReflectionProperty(DaemonManager::class, 'signalDispatchRouter')->getValue($this->manager));
            $detach->invoke($this->manager);
            self::assertFalse($loop->hasReadyWork());
            $attach->invoke($this->manager);
            self::assertTrue($loop->hasReadyWork());
            self::assertSame($retained, new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($this->manager)->active);
            Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('third'), new WebSocketSignalData(new SignalData()));
            for ($portion = 0; $portion < 64; $portion++) {
                $drain->invoke($this->manager);
            }
            foreach ($clients as $client) {
                self::assertSame(['first', 'second', 'third'], array_map(
                    static fn(DeliveryFrame $frame): string => json_decode($frame->json, true)['type'], $client->prepared,
                ));
            }
            self::assertFalse($loop->hasReadyWork());
            $original->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('detached'), new WebSocketSignalData(new SignalData()));
            self::assertFalse($loop->hasReadyWork(), 'A retired router no longer owns the native cue');
            Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                new SignalName('active'), new WebSocketSignalData(new SignalData()));
            self::assertTrue($loop->hasReadyWork());
        } finally {
            $detach->invoke($this->manager);
            $loop->close();
        }
    }

    /** @param bool $forwarded Whether the final captured lifetime arrived over a peer */
    #[DataProvider('dispatchOrigins')]
    public function testFinalBrowserPrefixWaitsForPhysicalDeliveryWithoutASecondWorkerWave(bool $forwarded): void
    {
        $clients = [];
        for ($index = 0; $index < 130; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        $workers = new ReflectionMethod(DaemonManager::class, 'findWorkerServer')->invoke($this->manager);
        self::assertTrue($workers->isReadyToShutdown());
        self::assertNull($workers->pendingStopDispatch());
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName('final_browser_marker'), new WebSocketSignalData(new SignalData(['final' => true])));
        if ($forwarded) {
            $this->manager->deliverFanoutToClients('peer', $signal);
        } else {
            Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($this->manager, microtime(true));
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $release = new ReflectionMethod(DaemonManager::class, 'releaseBrowsersOnceWorkersLeft');
        $released = new ReflectionProperty(DaemonManager::class, 'browsersReleased');
        $dispatch->invoke($this->manager);
        $release->invoke($this->manager);
        self::assertFalse($released->getValue($this->manager));
        self::assertSame([], $clients[129]->prepared);
        for ($portion = 0; $portion < 16 && !$released->getValue($this->manager); $portion++) {
            for ($index = 0; $index < 10; $index++) {
                Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                    new SignalName('later_browser_marker'), new WebSocketSignalData(new SignalData()));
            }
            $dispatch->invoke($this->manager);
            $release->invoke($this->manager);
        }
        self::assertTrue($released->getValue($this->manager));
        self::assertTrue(Hilos::$sr->hasQueuedSignals(), 'Later intake cannot extend the captured browser boundary');
        foreach ($clients as $client) {
            self::assertNotEmpty($client->prepared);
            self::assertSame('{"type":"final_browser_marker","data":{"final":true}}', $client->prepared[0]->json);
            $client->write();
            self::assertTrue($client->shouldClose(), 'closeOnceWritten follows the complete physical prefix');
        }
    }

    /** @return iterable<string, array{string}> Ways an incomplete packet loses its original lifetime */
    public static function discardedPackets(): iterable
    {
        yield 'page cancellation' => ['cancel'];
        yield 'subscription replacement' => ['replace'];
        yield 'socket close' => ['close'];
    }

    /** @param string $ending Operation retiring the original packet */
    #[DataProvider('discardedPackets')]
    public function testDiscardedPacketsReleaseActualFramesInGuardedPortions(string $ending): void
    {
        $client = $this->connect(null, 'g');
        $client->acceptJson('{"type":"page_subscribe","page":"p"}');
        $original = $client->pendingPageSubscribe();
        self::assertSame($original, Hilos::$sr->getNextQueuedSignal()->data);
        $references = [];
        for ($index = 0; $index < 260; $index++) {
            $frame = DeliveryFrame::fromJson('{"type":"toast","data":{"old":' . $index . '}}');
            $references[] = WeakReference::create($frame);
            $client->sendDeliveryFrame($frame);
        }
        unset($frame);
        $client->prepared = [];
        match ($ending) {
            'cancel' => $client->cancelDeliveries('p'),
            'replace' => $client->acceptJson('{"type":"page_subscribe","page":"p"}'),
            'close' => $client->close(),
        };
        $queue = new ReflectionProperty(WebSocketClient::class, 'deliveryQueue')->getValue($client);
        self::assertFalse($client->isDeliveryPending('p', $original->deliveryId));
        self::assertFalse($queue->hasFrames());
        self::assertSame(0, $queue->getPendingBytes());
        self::assertNotNull($references[0]->get(), 'The admission must leave physical release to bounded guarded effects');
        if ($ending !== 'close') {
            if ($ending === 'cancel') {
                $client->acceptJson('{"type":"page_subscribe","page":"p"}');
            }
            $replacement = $client->pendingPageSubscribe();
            self::assertSame($replacement, Hilos::$sr->getNextQueuedSignal()->data);
            $client->sendFrame('{"type":"toast","data":{"new":true}}');
            $client->completeDelivery('p', $original->deliveryId);
            self::assertTrue($queue->isHeld(), 'An old completion cannot release the replacement packet');
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        for ($portion = 0; $portion < 64; $portion++) {
            $dispatch->invoke($this->manager);
        }
        foreach ($references as $reference) {
            self::assertNull($reference->get(), 'Actual frame release needs neither GC nor the disappearance of the socket');
        }
        if ($ending !== 'close') {
            self::assertTrue($queue->isHeld());
            $client->completeDelivery('p', $replacement->deliveryId);
            self::assertSame(['{"type":"toast","data":{"new":true}}'], $queue->drain(0));
        }
    }

    /** @return iterable<string, array{string}> Indexed page invalidation and whole-socket endings */
    public static function discardedRequests(): iterable
    {
        yield 'one page' => ['page'];
        yield 'all pages' => ['all'];
        yield 'socket close' => ['close'];
    }

    /** @param string $ending Original request cohort being abandoned */
    #[DataProvider('discardedRequests')]
    public function testRequestCancellationReleasesOnlyItsOriginalIndexedCohort(string $ending): void
    {
        $client = $this->connect(null, 'g');
        $references = [];
        $tokens = [];
        for ($index = 0; $index < 260; $index++) {
            $page = $index % 2 === 0 ? 'p' : 'other';
            $client->acceptJson('{"type":"page_update_subscription","page":"' . $page . '"}');
            $request = Hilos::$sr->getNextQueuedSignal()->data;
            $references[] = WeakReference::create($request);
            $tokens[] = [$page, $request->deliveryId];
        }
        unset($request);
        match ($ending) {
            'page' => $client->cancelDeliveries('p'),
            'all' => $client->cancelDeliveries(),
            'close' => $client->close(),
        };
        foreach ($tokens as [$page, $token]) {
            self::assertSame($ending === 'page' && $page === 'other', $client->isDeliveryPending($page, $token));
        }
        self::assertNotNull($references[0]->get(), 'Immediate invalidation cannot destroy the entire original request roster');
        if ($ending !== 'close') {
            $client->acceptJson('{"type":"page_update_subscription","page":"p"}');
            $future = Hilos::$sr->getNextQueuedSignal()->data;
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        for ($portion = 0; $portion < 64; $portion++) {
            $dispatch->invoke($this->manager);
        }
        foreach ($references as $index => $reference) {
            if ($ending === 'page' && $index % 2 !== 0) {
                self::assertNotNull($reference->get());
            } else {
                self::assertNull($reference->get(), 'Actual request payloads must leave without GC');
            }
        }
        if ($ending !== 'close') {
            self::assertTrue($client->isDeliveryPending('p', $future->deliveryId), 'Cleanup cannot touch a newer same-page lifetime');
            $client->completeDelivery('p', $tokens[0][1]);
            self::assertTrue($client->isDeliveryPending('p', $future->deliveryId));
        }
    }

    /** @return iterable<string, array{string}> Independent page and group state endings */
    public static function discardedSubscriptionState(): iterable
    {
        yield 'page only' => ['page'];
        yield 'groups only' => ['groups'];
        yield 'connection' => ['all'];
    }

    /** @param string $ending Subscription state whose exact original payloads must leave */
    #[DataProvider('discardedSubscriptionState')]
    public function testSubscriptionCleanupDetachesNestedStateBeforeReleasingOriginalPayloads(string $ending): void
    {
        $client = $this->connect(null, 'g');
        $key = $client->acceptKey;
        $viewports = [];
        for ($index = 0; $index < 260; $index++) {
            $viewport = new TableViewportSubscription('table-' . $index);
            $viewports[] = WeakReference::create($viewport);
            Hilos::$sr->setTableViewport($key, $viewport);
            Hilos::$sr->subscribeToGroup('group-' . $index, new WebSocketGroupSubscribeSignalDTO($key, 'group-' . $index));
        }
        unset($viewport);
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        $memberships = new ReflectionProperty(SubscriptionRegistry::class, 'groups')->getValue($registry)[$key];
        $groups = [];
        foreach ($memberships as $name => $membership) {
            $groups[$name] = WeakReference::create($membership);
        }
        unset($memberships, $membership);
        match ($ending) {
            'page' => Hilos::$sr->unsubscribeFromPage('p', new WebSocketPageUnsubscribeSignalDTO($key)),
            'groups' => Hilos::$sr->unsubscribeFromAllGroups($key),
            'all' => Hilos::$sr->unsubscribeFromAll($key),
        };
        self::assertCount($ending === 'groups' ? 260 : 0, Hilos::$sr->getTableViewports($key));
        self::assertSame($ending === 'page' ? 'group-0' : null, Hilos::$sr->groupSubscriptionName($key, 'group-0'));
        if ($ending !== 'groups') {
            self::assertNotNull($viewports[0]->get(), 'Page invalidation must not synchronously destroy every table window');
            Hilos::$sr->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO($key, 'p'));
            $replacement = new TableViewportSubscription('table-0');
            Hilos::$sr->setTableViewport($key, $replacement);
        }
        if ($ending !== 'page') {
            self::assertNotNull($groups['group-0']->get(), 'Leaving every group must release payloads in guarded portions');
            Hilos::$sr->subscribeToGroup('group-0', new WebSocketGroupSubscribeSignalDTO($key, 'group-0'));
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        for ($portion = 0; $portion < 64; $portion++) {
            $dispatch->invoke($this->manager);
        }
        foreach ($viewports as $viewport) {
            if ($ending === 'groups') {
                self::assertNotNull($viewport->get());
            } else {
                self::assertNull($viewport->get());
            }
        }
        foreach ($groups as $group) {
            if ($ending === 'page') {
                self::assertNotNull($group->get());
            } else {
                self::assertNull($group->get());
            }
        }
        if ($ending !== 'groups') {
            self::assertSame($replacement, Hilos::$sr->getTableViewport($key, 'table-0'));
        }
        self::assertSame('group-0', Hilos::$sr->groupSubscriptionName($key, 'group-0'));
    }

    /** @return iterable<string, array{bool, bool, bool}|array{bool, bool, bool, bool}> Original lifetimes under later ready work */
    public static function effectFairnessEntrances(): iterable
    {
        yield 'local input' => [false, true, false];
        yield 'peer with workers' => [true, true, false];
        yield 'peer without workers' => [true, false, false];
        yield 'local input past later failure' => [false, true, true];
        yield 'peer with workers past later failure' => [true, true, true];
        yield 'peer without workers past later failure' => [true, false, true];
        yield 'local input past later cancellations' => [false, true, false, true];
        yield 'peer with workers past later cancellations' => [true, true, false, true];
        yield 'peer without workers past later cancellations' => [true, false, false, true];
        yield 'local input past later cancellations and failure' => [false, true, true, true];
        yield 'peer with workers past later cancellations and failure' => [true, true, true, true];
        yield 'peer without workers past later cancellations and failure' => [true, false, true, true];
    }

    /**
     * @param bool $forwarded Whether the original raw arrives through the one-hop entrance
     * @param bool $workers Whether the node also has a worker server
     * @param bool $failLater Whether an unrelated later prerequisite fails under the same guard
     * @param bool $cancellations Whether later ready cancellations arrive faster than the whole portion budget
     */
    #[DataProvider('effectFairnessEntrances')]
    public function testFiniteSourcePrerequisitesAndRawPrefixFinishWhileLaterEffectsKeepArriving(
        bool $forwarded, bool $workers, bool $failLater, bool $cancellations = false,
    ): void
    {
        $clients = [];
        for ($index = 0; $index < 40; $index++) {
            $clients[] = $this->connect(null, 'g');
        }
        if (!$workers) {
            new ReflectionProperty(DaemonManager::class, 'servers')->setValue($this->manager, [$this->server]);
        }
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->manager);
        $memory = new UploadMemoryMaster('effect-fairness');
        $memory->setTestLimit(1);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($this->manager, $memory);
        $raw = null;
        $memory->enqueueTest('local', 'probe', null, 1,
            function ($delivery, SignalDTO $signal) use ($forwarded, &$raw): void {
                $raw = WeakReference::create($signal->data);
                $outgoing = new SignalDTO(new SignalSource(SignalSource::AGENT),
                    new SignalType(SignalTypeConstants::WS_ALL_CONNECTED), new SignalName('raw_probe'), new WebSocketSignalData($signal->data));
                if ($forwarded) {
                    $this->manager->deliverFanoutToClients('peer', $outgoing);
                } else {
                    Hilos::$sr->queueSignal($outgoing->signalSource, $outgoing->signalType, $outgoing->signalName, $outgoing->data);
                }
            });
        $queue = new ReflectionMethod(DaemonManager::class, 'queueSignalEffect');
        $journal = [];
        $queue->invoke($this->manager, function () use ($queue, &$journal): Generator {
            yield;
            for ($step = 0; $step < 100; $step++) {
                yield;
            }
            $journal[] = 'parent';
            $queue->invoke($this->manager, static function () use (&$journal): Generator {
                yield;
                for ($step = 0; $step < 130; $step++) {
                    yield;
                }
                $journal[] = 'child';
                yield;
            });
            yield;
        });
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->manager);
        $state->browsers = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($this->manager);
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $due = 0;
        $native = $loop->createReadyWork(static function () use (&$due): void { $due++; });
        $failure = $failLater ? new RuntimeException('unrelated later source failed') : null;
        $failures = [];
        try {
            for ($portion = 0; $portion < 128 && !$state->browsers->complete; $portion++) {
                $native->markReady();
                $loop->tick();
                self::assertSame($portion + 1, $due);
                $queue->invoke($this->manager, function () use ($queue, $portion, $failure, &$journal): Generator {
                    yield;
                    for ($step = 0; $step < 130; $step++) {
                        yield;
                    }
                    $journal[] = 'later-' . $portion;
                    if ($portion === 0 && $failure !== null) {
                        throw $failure;
                    }
                    $queue->invoke($this->manager, static function (): Generator {
                        yield;
                        for ($step = 0; $step < 130; $step++) {
                            yield;
                        }
                    });
                    yield;
                });
                if ($cancellations) {
                    for ($later = 0; $later < 70; $later++) {
                        $id = ++$state->commandCancellationSequence;
                        $cancellation = new CommandSignalCancellation($id, 'later-' . $id, 0);
                        $cancellation->holdsComplete = true;
                        $state->commandCancellations[$id] = $cancellation;
                    }
                    unset($cancellation);
                }
                try {
                    $dispatch->invoke($this->manager);
                } catch (RuntimeException $actual) {
                    self::assertSame($failure, $actual);
                    $failures[] = $actual;
                    self::assertFalse($state->browsers->failed, 'A later source prerequisite cannot fail the independent original prefix');
                }
                if ($clients[0]->prepared !== []) {
                    self::assertContains('child', $journal, 'A descendant prerequisite behind a later FIFO head must finish before delivery');
                }
            }
            self::assertTrue($state->browsers->complete, 'A continuing later producer must not starve the original finite prefix');
            self::assertSame($failLater ? [$failure] : [], $failures);
            self::assertFalse($state->browsers->failed);
            self::assertNotEmpty($state->effects, 'Fairness must be observed before the later producer stops');
            if ($cancellations) {
                self::assertNotEmpty($state->commandCancellations, 'Later cancellation debt is still arriving at original settlement');
            }
            self::assertNull($raw->get(), 'The actual original raw must die without an explicit GC pass');
            self::assertSame(0, $memory->inspect()['usedBytes'], 'The real manager afterDispatch settles the original raw receipt');
            self::assertSame('parent', $journal[0]);
            self::assertLessThan(array_search('child', $journal, true), array_search('later-0', $journal, true),
                'A required child never overtakes the source operation already queued ahead of it');
            foreach ($this->connections as [$client, $peer]) {
                self::assertCount(1, $client->prepared);
                self::assertSame('raw_probe', json_decode($client->prepared[0]->json, true)['type']);
                $client->write();
                self::assertStringContainsString($client->prepared[0]->json, new PlainSocketTransport($peer)->read(4096));
                self::assertSame('', new PlainSocketTransport($peer)->read(4096), 'The physical journal has no duplicate delivery');
            }
        } finally {
            $native->close();
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($this->manager);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->manager);
            $loop->close();
        }
    }

    public function testRealMasterAccountingReleasesTheCompletedRawWhileAnotherRawIsStillActive(): void
    {
        for ($index = 0; $index < 130; $index++) {
            $this->connect(null, 'g');
        }
        $memory = new UploadMemoryMaster('master-portions');
        $memory->setTestLimit(2);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($this->manager, $memory);
        $references = [];
        for ($index = 0; $index < 2; $index++) {
            $memory->enqueueTest('local', 'probe', null, 1, static function ($delivery, SignalDTO $signal) use (&$references): void {
                $references[] = WeakReference::create($signal->data);
                // Real retained physical fan-out owns the raw payload, rather than a missing-address roster search.
                Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
                    new SignalName('raw_probe'), new WebSocketSignalData($signal->data));
            });
        }
        self::assertSame(2, $memory->inspect()['usedBytes']);
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $dispatch->invoke($this->manager);
        self::assertNotNull($references[0]->get());
        self::assertNotNull($references[1]->get());
        self::assertSame(2, $memory->inspect()['usedBytes']);
        for ($portion = 0; $portion < 16 && $references[0]->get() !== null; $portion++) {
            $dispatch->invoke($this->manager);
        }
        self::assertNull($references[0]->get());
        self::assertNotNull($references[1]->get());
        self::assertSame(1, $memory->inspect()['usedBytes'], 'The actual manager finally-accounting sees settlement before the next raw finishes');
        for ($portion = 0; $portion < 16; $portion++) {
            $dispatch->invoke($this->manager);
        }
        self::assertNull($references[1]->get());
        self::assertSame(0, $memory->inspect()['usedBytes']);
    }

    /**
     * @param string $acceptKey Pending connection
     * @param string $page Pending page
     * @param string $deliveryId Exact attempt token
     * @param array<string, mixed> $params Original recovery parameters
     */
    private function trackPending(string $acceptKey, string $page, string $deliveryId, array $params): void
    {
        new ReflectionMethod(DaemonManager::class, 'trackPageDelivery')->invoke($this->manager,
            new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION),
                new SignalName($page), new WebSocketPageUpdateSubscriptionSignalDTO($acceptKey, $page, $params, deliveryId: $deliveryId)),
            new AgentDestination('pending_owner'));
    }

    /**
     * @param SignalDTO $signal Original envelope
     * @return SignalDispatch Actual production phase and physical continuation
     */
    private function createDispatch(SignalDTO $signal): SignalDispatch
    {
        return new ReflectionMethod(DaemonManager::class, 'createSignalDispatch')->invoke($this->manager, $signal, false,
            new ReflectionMethod(DaemonManager::class, 'findWorkerServer')->invoke($this->manager),
            $this->server, null, null, null);
    }

    /**
     * @param SignalDispatch $dispatch Actual production lifetime
     * @param int $steps Allowed bounded steps
     */
    private function advanceDispatch(SignalDispatch $dispatch, int $steps): void
    {
        new ReflectionMethod(DaemonManager::class, 'runSignalPortion')->invoke($this->manager, $dispatch, $steps);
    }

    private function connect(?string $session, string $group): DeliveryTestClient
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new DeliveryTestClient($pair[0]);
        new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($client, $session);
        $this->connections[] = [$client, $pair[1]];
        $this->server->attach($client);
        Hilos::$sr->subscribeToPage('p', new WebSocketPageSubscribeSignalDTO($client->acceptKey, 'p'));
        Hilos::$sr->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO($client->acceptKey, $group));

        return $client;
    }
}

final class DeliveryTestPayload extends SignalData
{
    public int $encodes = 0;

    public function toArray(): array
    {
        $this->encodes++;

        return ['value' => 42];
    }
}

final class DeliveryTestServer extends WebSocketServer
{
    public int $rosterCaptures = 0;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    public function attach(WebSocketClient $client): void
    {
        $this->addClient($client);
    }

    /** @return Generator<int, ?WebSocketClientInterface> Original roster, counting eager captures */
    public function clientCandidates(): Generator
    {
        $this->rosterCaptures++;
        return parent::clientCandidates();
    }

    protected function onStart(): void
    {
    }

    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new DeliveryTestClient($socket);
    }
}

final class DeliveryTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    protected function onStart(): void
    {
    }
}

final class DeliveryTestManager extends DaemonManager
{
    public int $encodes = 0;

    protected function encodeSignalFrame(SignalDTO $signal): ?string
    {
        $this->encodes++;

        return parent::encodeSignalFrame($signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DeliveryTestAgentManager();
    }
}

final class DeliveryTestAgentManager extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('no agents in transport tests');
    }
}
