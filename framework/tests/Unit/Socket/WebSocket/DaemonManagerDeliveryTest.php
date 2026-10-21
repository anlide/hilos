<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\Delivery\DeliveryQueue;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

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
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        }
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
            $bytes = (new PlainSocketTransport($peer))->read(4096);
            if ($client->prepared === []) {
                self::assertFalse($bytes);
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
        self::assertFalse($otherBrowser->read(4096));

        // A late session answer starts grace at the answer, not at the socket's opening.
        $client->now = 1099;
        $client->sendFrame('{"type":"late_session_state","data":{}}');
        $client->write();
        self::assertIsString($browser->read(4096));
        $client->now = 1100;
        $client->sendFrame('{"type":"background_state","data":{}}');
        $client->write();
        self::assertFalse($browser->read(4096));

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
        self::assertFalse($browser->read(4096));
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
        self::assertFalse(new PlainSocketTransport($this->connections[0][1])->read(4096));

        $this->complete($path, $client->acceptKey, 'old');
        self::assertTrue($queue->isHeld());
        $client->write();
        self::assertFalse(new PlainSocketTransport($this->connections[0][1])->read(4096));
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
        self::assertFalse(new PlainSocketTransport($this->connections[0][1])->read(4096));
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
        self::assertFalse(new PlainSocketTransport($this->connections[0][1])->read(4096));
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
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    public function attach(WebSocketClient $client): void
    {
        $this->clients[] = $client;
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
