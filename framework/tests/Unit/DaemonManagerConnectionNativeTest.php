<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerConnectionsDeltaDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsSnapshotDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

/** Central transport facts feed the actual guarded master phase; every typed output is retained. */
final class DaemonManagerConnectionNativeTest extends TestCase
{
    private const string UPGRADE = "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: MDEyMzQ1Njc4OWFiY2RlZg==\r\nSec-WebSocket-Version: 13\r\n\r\n";

    private EventLoop $loop;
    private ConnectionNativeManager $manager;
    private ConnectionNativeServer $server;
    private ConnectionNativeMesh $peer;
    private PeerServer $transport;
    private ClusterClientLocation $index;
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRuntime;
    private string|false $previousEnabled;
    /** @var list<Socket> Browser endpoints closed after their server ends leave the native base */
    private array $browsers = [];
    /** @var list<array{ConnectionNativeServer, ConnectionNativeClient}> Exact seeded transports to retire */
    private array $clients = [];

    public function testActualUnkeyedAcceptAndUpgradeWakeOnlyTheNextConnectionPhase(): void
    {
        $this->pass();
        self::assertSame(1, $this->manager->diffVisits);
        self::assertSame(1, $this->server->enumerations);
        $this->server->start();
        $this->invoke('registerServerSocket', $this->server);
        socket_getsockname($this->server->getSocket(), $host, $port);
        $browser = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->browsers[] = $browser;
        self::assertTrue(socket_connect($browser, '127.0.0.1', $port));
        socket_set_nonblock($browser);
        $this->loop->runOnce();
        $client = $this->server->accepted;
        self::assertInstanceOf(ConnectionNativeClient::class, $client);
        $this->clients[] = [$this->server, $client];
        self::assertSame('', $client->acceptKey);
        self::assertSame(0, $this->server->localConnectionRevision());
        $this->pass();
        self::assertSame(1, $this->server->enumerations, 'Real unkeyed acceptance is not a keyed-set fact');

        socket_write($browser, self::UPGRADE);
        $this->pump();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $client->handshakeKey);
        self::assertSame($client->handshakeKey, $client->acceptKey);
        self::assertSame(1, $this->server->localConnectionRevision(), 'The actual production handshake invoked the central hook');
        self::assertSame([], $this->peer->frames, 'The native handshake hook never announces inline');
        $this->pass();
        // The random key is captured independently at onHandshake, before any announcement output.
        self::assertEquals([new PeerConnectionsDeltaDTO('local', [$client->handshakeKey], [])], $this->peer->frames);
        self::assertSame(2, $this->manager->diffVisits);
        self::assertSame(2, $this->server->enumerations);
        for ($pass = 0; $pass < 12; ++$pass) {
            $this->unrelatedWake();
            $this->pass();
        }
        self::assertSame(2, $this->manager->diffVisits);
        self::assertSame(2, $this->server->enumerations, 'Stable I/O never enters the real connected-client walk or diff');
        self::assertEquals([new PeerConnectionsDeltaDTO('local', [$client->handshakeKey], [])], $this->peer->frames);
    }

    public function testNativeUnkeyedEofLeavesNoLocalConnectionDebt(): void
    {
        [$client, $browser] = $this->open('');
        $this->pass();
        socket_shutdown($browser, 1);
        $this->pump();
        self::assertNull($client->getSocket());
        self::assertSame(1, $client->closes);
        $this->pass();
        self::assertSame(0, $this->server->localConnectionRevision());
        self::assertSame(1, $this->server->enumerations);
        self::assertSame([], $this->peer->frames);
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    public function testKeyChangeEmptyKeyAndAlreadyKeyedAdmissionUseTheExistingCentralHook(): void
    {
        [$client] = $this->open('a');
        self::assertSame(1, $this->server->localConnectionRevision());
        $this->pass();
        $client->acceptKey = 'a';
        $this->pass();
        self::assertSame(1, $this->manager->diffVisits);
        $client->acceptKey = 'b';
        self::assertSame(1, $this->manager->diffVisits, 'Key callbacks only change metadata');
        $this->pass();
        $client->acceptKey = '';
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', ['b'], ['a']),
            new PeerConnectionsDeltaDTO('local', [], ['b']),
        ], $this->peer->frames);
        self::assertSame(3, $this->server->localConnectionRevision());
        $this->server->dropClient($client);
        $this->pass();
        self::assertSame(3, $this->server->localConnectionRevision(), 'An unkeyed close is silent');
        self::assertSame(3, $this->server->enumerations);
    }

    public function testMixedChangesAndNumericAttachmentsKeepOneUnionBatchAndAnnouncedHandover(): void
    {
        [$old] = $this->open('old');
        $this->index->attachLocal('0');
        $this->pass();
        $this->server->dropClient($old);
        [$live] = $this->open('live');
        $this->open('live');
        $this->index->attachLocal('7');
        $this->index->attachLocal('7');
        $this->index->attachLocal('live');
        $this->index->detachLocal('missing');
        $this->index->detachLocal('0');
        $this->index->detachLocal('0');
        $this->manager->handOverConnections('remote');
        $this->pass();
        $this->manager->handOverConnections('remote');
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['0', 'old'], []),
            new PeerConnectionsSnapshotDTO('local', ['0', 'old']),
            new PeerConnectionsDeltaDTO('local', ['7', 'live'], ['0', 'old']),
            new PeerConnectionsSnapshotDTO('local', ['7', 'live']),
        ], $this->peer->frames);
        self::assertSame(4, $this->index->localAttachmentRevision());
        $this->index->attachLocal('7');
        $this->index->detachLocal('missing');
        $this->pass();
        self::assertSame(2, $this->server->enumerations, 'Idempotent test calls do not create new input');
        $this->index->attachLocal('temporary');
        $this->index->detachLocal('temporary');
        $live->acceptKey = 'temporary';
        $live->acceptKey = 'live';
        $this->pass();
        self::assertSame(3, $this->server->enumerations, 'A genuinely changed source is diffed once even if the final set agrees');
        self::assertCount(4, $this->peer->frames, 'Same final union sends nothing');
    }

    public function testRemoteLocationDeliveryAndSessionTrafficNeverInvalidateLocalInput(): void
    {
        [$client] = $this->open('a');
        $this->pass();
        $this->index->applySnapshot('remote', ['r']);
        $this->index->applyDelta('remote', ['s'], ['r']);
        $this->index->noteAddressedDelivery('a');
        $this->index->noteFanoutDelivery();
        $this->index->nodeFor('s');
        $this->index->forgetNode('remote');
        new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($client, 'session');
        $client->acceptKey = 'a';
        $client->sendFrameBinary('delivery');
        $this->pump();
        $this->pass();
        self::assertSame(1, $this->server->enumerations);
        self::assertSame(1, $this->manager->diffVisits);
        self::assertSame(1, $this->server->localConnectionRevision());
        self::assertSame(0, $this->index->localAttachmentRevision());
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
    }

    /** @return iterable<string, array{?string}> Physical close, EOF and malformed input */
    public static function terminalInput(): iterable
    {
        yield 'orderly close frame' => ["\x88\x80mask"];
        yield 'read EOF' => [null];
        yield 'read parser failure' => ["\x8b\x00"];
    }

    /** @param ?string $input Ready transport input; null means peer half-close */
    #[DataProvider('terminalInput')]
    public function testActualNativeCloseEofAndReadFailureEachRemoveAndAnnounceOnce(?string $input): void
    {
        [$client, $browser] = $this->open('a');
        $this->pass();
        if ($input === null) {
            socket_shutdown($browser, 1);
        } else {
            socket_write($browser, $input);
        }
        $this->pump();
        self::assertNull($client->getSocket());
        self::assertSame(1, $client->closes);
        self::assertSame(2, $this->server->localConnectionRevision());
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
        $this->pass();
        $this->server->removeClient($client);
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', [], ['a']),
        ], $this->peer->frames);
        self::assertSame(2, $this->server->enumerations);
        self::assertSame(2, $this->server->localConnectionRevision());
    }

    public function testStaleRemovalAndEscapedOldKeyHookCannotTouchTheReplacement(): void
    {
        [$old] = $this->open('shared');
        $escaped = new ReflectionProperty(WebSocketClient::class, 'acceptKeyChanged')->getValue($old);
        [$replacement] = $this->open('shared');
        $target = $this->server->clientTarget('shared');
        $this->pass();
        $this->server->dropClient($old);
        $this->pass();
        $revision = $this->server->localConnectionRevision();
        $this->server->removeClient($old);
        $old->acceptKey = 'escaped';
        $escaped($old, 'shared');
        $this->pass();
        self::assertSame($revision, $this->server->localConnectionRevision());
        self::assertSame($replacement, $target->getClient());
        self::assertNotNull($this->server->clientTarget('shared'));
        self::assertNull($this->server->clientTarget('escaped'));
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['shared'], [])], $this->peer->frames);
        $this->server->dropClient($replacement);
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['shared'], []),
            new PeerConnectionsDeltaDTO('local', [], ['shared']),
        ], $this->peer->frames);
    }

    public function testMutationAfterCapturedRosterAndAfterBroadcastBothRemainOwed(): void
    {
        $this->open('a');
        $this->server->afterCapture = function (): void { $this->open('b'); };
        $this->peer->afterDelta = function (): void { $this->index->attachLocal('7'); };
        $this->pass();
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
        $this->pump();
        self::assertFalse($this->loop->hasReadyWork());
        self::assertFalse($this->invoke('hasRunnableSignalDispatch'));
        self::assertTrue($this->invoke('hasRunnableConnectionChanges'), 'Captured revisions cannot be replaced with the latest after send');
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', ['7', 'b'], []),
        ], $this->peer->frames);
        self::assertSame(2, $this->server->enumerations);
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    public function testLastSourceEffectAfterConnectionPhaseLeavesRunnableDebtWithEmptyQueues(): void
    {
        $this->pass();
        $index = $this->index;
        $this->invoke('queueSignalEffect', static function () use ($index): Generator {
            for ($step = 0; $step < 128; ++$step) {
                yield;
            }
            $index->attachLocal('late-effect');
            yield from [];
        });
        self::assertSame([], $this->index->announcedLocalKeys());
        for ($portion = 0; $portion < 8 && $this->invoke('hasRunnableSignalDispatch'); ++$portion) {
            $this->pass();
        }
        $this->pump();
        self::assertFalse($this->invoke('hasRunnableSignalDispatch'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->invoke('hasRunnableConnectionChanges'));
        self::assertSame([], $this->peer->frames);
        $this->pass();
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['late-effect'], [])], $this->peer->frames);
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    public function testNullAndReplacedSourcesAreDormantOrDiffTheCurrentBasisWithoutSyntheticOpens(): void
    {
        [$client] = $this->open('a');
        Hilos::$cluster = null;
        $this->index->attachLocal('7');
        $this->pass();
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
        self::assertSame(0, $this->server->enumerations);
        Hilos::$cluster = new ClusterContext();
        $this->pass();
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'), 'An unregistered index has no runnable diff');
        Hilos::$cluster->registerClientConnections($this->index);
        $this->pass();
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['7', 'a'], [])], $this->peer->frames);
        $this->set('servers', [$this->server]);
        $client->acceptKey = 'b';
        $this->pass();
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'), 'Dirty without a mesh does not hot-spin');
        self::assertSame(1, $this->server->enumerations);
        $this->set('servers', [$this->server, $this->transport]);
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['7', 'a'], []),
            new PeerConnectionsDeltaDTO('local', ['b'], ['a']),
        ], $this->peer->frames);
        $this->transport = $this->newTransport();
        $this->set('servers', [$this->server, $this->transport]);
        $this->pass();
        self::assertCount(2, $this->peer->frames, 'A replacement mesh uses the existing announced index');
        $this->manager->handOverConnections('remote');
        $this->set('servers', [$this->transport]);
        $this->pass();
        $this->set('servers', [$this->server, $this->transport]);
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['7', 'a'], []),
            new PeerConnectionsDeltaDTO('local', ['b'], ['a']),
            new PeerConnectionsSnapshotDTO('local', ['7', 'b']),
            new PeerConnectionsDeltaDTO('local', [], ['b']),
            new PeerConnectionsDeltaDTO('local', ['b'], []),
        ], $this->peer->frames);
        Hilos::$cluster->registerClientConnections(new ClusterClientLocation('local'));
        $this->pass();
        self::assertEquals(new PeerConnectionsDeltaDTO('local', ['b'], []), $this->peer->frames[5]);
        self::assertCount(6, $this->peer->frames);
    }

    public function testReattachmentPreservesConsumedBasisAndDetachCannotBeRevivedByReconciliation(): void
    {
        [$client] = $this->open('a');
        $this->pass();
        $this->invoke('attachConnectionChangesEventLoop');
        $this->pass();
        self::assertSame(1, $this->server->enumerations);
        $this->invoke('detachConnectionChangesEventLoop');
        $this->invoke('attachConnectionChangesEventLoop');
        $this->pass();
        self::assertSame(1, $this->server->enumerations);
        $client->acceptKey = 'b';
        $this->invoke('detachConnectionChangesEventLoop');
        $this->pass();
        $this->invoke('reconcileConnectionChangesSources');
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
        self::assertSame(1, $this->server->enumerations);
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
    }

    public function testEqualRevisionServerAndFacadeReplacementUseExactCurrentIdentities(): void
    {
        $this->open('a');
        $this->pass();
        $this->server = new ConnectionNativeServer('127.0.0.1', 0);
        $this->set('servers', [$this->transport]);
        $this->manager->registerServer($this->server);
        $this->open('b');
        self::assertSame(1, $this->server->localConnectionRevision(), 'Equal scalar revisions do not identify equal sources');
        $this->pass();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerClientConnections($this->index);
        $this->pass();
        self::assertSame(3, $this->manager->diffVisits);
        self::assertSame(2, $this->server->enumerations, 'A new facade is eligible against the same announced basis');
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', ['b'], ['a']),
        ], $this->peer->frames);
    }

    public function testExplicitPeerHandshakeAndReconnectHandOverOnlyWholeAnnouncedNumericKeys(): void
    {
        $this->index->attachLocal('7');
        $this->index->attachLocal('0');
        $this->open('a');
        $this->pass();
        $revision = $this->index->localAttachmentRevision();
        $previous = [];
        foreach ([EnvConstants::CLUSTER_NODE_ID, EnvConstants::CLUSTER_NODE_NAME, EnvConstants::CLUSTER_NODE_ROLE] as $key) {
            $previous[$key->name] = getenv($key->name);
        }
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=local');
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . '=Local');
        putenv(EnvConstants::CLUSTER_NODE_ROLE->name . '=master');
        Hilos::$cluster->registerClientSignalSink($this->manager);
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $link = new PeerLink($socket, $this->transport, NodeIdentity::of('local', 'Local', NodeRole::Master, []),
            dialer: false, transport: new PlainSocketTransport($socket));
        try {
            $remote = NodeIdentity::of('remote', 'Remote', NodeRole::Master, []);
            $this->transport->onHandshakeComplete($link, $remote);
            $this->transport->onHandshakeComplete($link, $remote);
            self::assertSame($revision, $this->index->localAttachmentRevision(), 'Peer handshakes invent no local membership fact');
            $this->invoke('reconcileConnectionChangesSources');
            self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
            self::assertSame(1, $this->server->enumerations);
            $this->index->attachLocal('pending');
            $this->transport->onHandshakeComplete($link, $remote);
            self::assertEquals([
                new PeerConnectionsDeltaDTO('local', ['7', '0', 'a'], []),
                new PeerConnectionsSnapshotDTO('local', ['7', '0', 'a']),
                new PeerConnectionsSnapshotDTO('local', ['7', '0', 'a']),
                new PeerConnectionsSnapshotDTO('local', ['7', '0', 'a']),
            ], $this->peer->frames, 'A handshake reads announcedLocal, not the instant pending attachments');
        } finally {
            $link->close();
            putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
            foreach ($previous as $key => $value) {
                putenv($key . ($value === false ? '' : '=' . $value));
            }
        }
        $this->pass();
        self::assertEquals(new PeerConnectionsDeltaDTO('local', ['pending'], []), $this->peer->frames[4]);
        self::assertCount(5, $this->peer->frames);
    }

    public function testConsumedWeakOriginsDoNotRetainTheOldGraphOrMistakeCollectionForAbsence(): void
    {
        // Bare sources isolate the guard's weak consumption metadata from transport bindings.
        $this->server = new ConnectionNativeServer('127.0.0.1', 0);
        $this->transport = $this->newTransport();
        $this->set('servers', [$this->server, $this->transport]);
        $this->pass();
        $oldServer = WeakReference::create($this->server);
        $oldPeer = WeakReference::create($this->transport);
        $oldCluster = WeakReference::create(Hilos::$cluster);
        $oldIndex = WeakReference::create($this->index);
        $this->server = new ConnectionNativeServer('127.0.0.1', 0);
        $this->transport = $this->newTransport();
        $this->index = new ClusterClientLocation('local');
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerClientConnections($this->index);
        $this->set('servers', [$this->server, $this->transport]);
        $this->invoke('reconcileReaderInterestSources');
        $this->invoke('bindSourceInterestDeliverySources');
        self::assertNull($oldServer->get());
        self::assertNull($oldPeer->get());
        self::assertNull($oldCluster->get());
        self::assertNull($oldIndex->get());
        $this->invoke('reconcileConnectionChangesSources');
        self::assertTrue($this->invoke('hasRunnableConnectionChanges'));
        $this->pass();
        self::assertSame(1, $this->server->enumerations);
        self::assertSame([], $this->peer->frames);
        $this->set('servers', [$this->transport]);
        $this->invoke('reconcileConnectionChangesSources');
        self::assertTrue($this->invoke('hasRunnableConnectionChanges'), 'Collected former server differs from a truly absent server');
        $this->pass();
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    public function testReplacementDuringBroadcastCannotConsumeTheNewGraphsInput(): void
    {
        $this->open('a');
        $this->peer->afterDelta = function (): void {
            Hilos::$cluster = new ClusterContext();
            $this->index = new ClusterClientLocation('local');
            Hilos::$cluster->registerClientConnections($this->index);
            $this->index->attachLocal('7');
            $this->transport = $this->newTransport();
            $this->set('servers', [$this->server, $this->transport]);
        };
        $this->pass();
        $this->pump();
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->invoke('hasRunnableConnectionChanges'));
        $this->pass();
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', ['7', 'a'], []),
        ], $this->peer->frames);
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    public function testFailureDoesNotInventAnAckOrRetryAfterTheOriginalDiffAdvanced(): void
    {
        $this->open('a');
        $this->peer->afterDelta = static function (): void { throw new RuntimeException('contained transport refusal'); };
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertTrue($this->get('shouldExit'), 'The existing main guard contains the sender failure');
        self::assertSame(['a'], $this->index->announcedLocalKeys());
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'), 'No new delivery-success or retry protocol');
        $this->invoke('dispatchConnectionChanges');
        self::assertSame(1, $this->server->enumerations);
        $this->manager->handOverConnections('remote');
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsSnapshotDTO('local', ['a']),
        ], $this->peer->frames);
    }

    public function testRealShutdownKeepsKeysThroughListenerStopWorkersAndFinalWriteThenClosesOnce(): void
    {
        [$client, $browser] = $this->open('a');
        $workers = new ConnectionNativeWorkers();
        $this->manager->registerServer($workers);
        $this->server->start();
        $this->invoke('registerServerSocket', $this->server);
        $this->pass();
        $this->manager->handleShutdown();
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertFalse($this->server->isRunning());
        self::assertNotNull($client->getSocket());
        self::assertFalse($this->server->isReadyToShutdown());
        self::assertSame(1, $this->server->localConnectionRevision());
        self::assertEquals([new PeerConnectionsDeltaDTO('local', ['a'], [])], $this->peer->frames);
        $client->sendFrameBinary('final-worker-delivery');
        $workers->ready = true;
        $this->invoke('runGuardedIteration', microtime(true));
        $this->pump();
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertStringContainsString('final-worker-delivery', socket_read($browser, 1024));
        self::assertNull($client->getSocket());
        self::assertSame(1, $client->closes);
        self::assertTrue($this->server->isReadyToShutdown());
        self::assertEquals([
            new PeerConnectionsDeltaDTO('local', ['a'], []),
            new PeerConnectionsDeltaDTO('local', [], ['a']),
        ], $this->peer->frames);
        self::assertFalse($this->invoke('hasRunnableConnectionChanges'));
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = new ClusterContext();
        $this->index = new ClusterClientLocation('local');
        Hilos::$cluster->registerClientConnections($this->index);
        $this->loop = new EventLoop();
        $this->manager = new ConnectionNativeManager();
        $this->set('eventLoop', $this->loop);
        $this->server = new ConnectionNativeServer('127.0.0.1', 0);
        $this->peer = $this->manager->mesh;
        $this->transport = $this->newTransport();
        $this->manager->registerServer($this->server);
        $this->manager->registerServer($this->transport);
        $this->invoke('attachSignalDispatchEventLoop');
        $this->invoke('attachConnectionChangesEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachConnectionChangesEventLoop');
        foreach ($this->clients as [$server, $client]) {
            $server->dropClient($client);
        }
        $this->invoke('discardSignalDispatch');
        if ($this->server->getSocket() !== null) {
            $this->loop->unregister($this->server->getSocket());
            $this->server->stop();
        }
        $this->loop->cleanup();
        foreach ($this->browsers as $browser) {
            socket_close($browser);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /** @return PeerServer Actual registered source identity, without starting an external listener */
    private function newTransport(): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of('local', 'Local', NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param string $key Independently chosen connection input, before central admission
     * @return array{ConnectionNativeClient, Socket} Registered client and browser transport
     */
    private function open(string $key): array
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new ConnectionNativeClient($pair[0], $key);
        $this->browsers[] = $pair[1];
        $this->clients[] = [$this->server, $client];
        $this->server->seed($client);
        return [$client, $pair[1]];
    }

    /** Only already-ready input and finite native portions; no clock wait or production span change. */
    private function pump(): void
    {
        for ($portion = 0; $portion < 4; ++$portion) {
            $this->loop->tick();
        }
    }

    /** A real readable socket unrelated to browser membership. */
    private function unrelatedWake(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->loop->registerRead($pair[0], static function () use ($pair): void { socket_read($pair[0], 1); });
        try {
            socket_write($pair[1], 'x');
            $this->loop->runOnce();
        } finally {
            $this->loop->unregister($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
        }
    }

    /** The full ordinary master phase order, including connection guard and bounded source dispatch. */
    private function pass(): void
    {
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertFalse($this->get('shouldExit'), 'The actual main guard must complete');
    }

    /**
     * @param string $method Actual lifecycle or guarded phase
     * @param mixed ...$arguments Actual input
     * @return mixed Production result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }

    /**
     * @param string $field Binding to inspect
     * @return mixed Current production value
     */
    private function get(string $field): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $field)->getValue($this->manager);
    }

    /**
     * @param string $field Source to replace
     * @param mixed $value New incarnation
     */
    private function set(string $field, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $field)->setValue($this->manager, $value);
    }
}

final class ConnectionNativeManager extends DaemonManager
{
    public int $diffVisits = 0;
    public readonly ConnectionNativeMesh $mesh;

    /** Keeps the actual registered transport identity beside an unfiltered typed recording port. */
    public function __construct()
    {
        parent::__construct();
        $this->mesh = new ConnectionNativeMesh();
    }

    /** @param ?ClientMesh $mesh Exact live transport; both prerequisites are live on every counted parent diff */
    protected function announceConnectionChanges(?ClientMesh $mesh): void
    {
        ++$this->diffVisits;
        parent::announceConnectionChanges($mesh === null ? null : $this->mesh);
    }

    /**
     * @param ?ClientMesh $mesh Actual registered transport
     * @param string $nodeId Actual handshake/handover addressee
     */
    protected function sendConnectionsToNode(?ClientMesh $mesh, string $nodeId): void
    {
        parent::sendConnectionsToNode($mesh === null ? null : $this->mesh, $nodeId);
    }

    /** Polls the real base without starting the inert peer fixture listener. */
    protected function processEventLoop(): void
    {
        $this->eventLoop->tick();
    }

    /** @return SignalRouter Ordinary source and signal FIFO */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Actual report owner, with no fixture agent starts */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ConnectionNativeAgents();
    }
}

final class ConnectionNativeAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface No agent is started
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new RuntimeException('Connection fixture starts no agents');
    }
}

final class ConnectionNativeServer extends WebSocketServer
{
    public int $enumerations = 0;
    public ?ConnectionNativeClient $accepted = null;
    public ?Closure $afterCapture = null;

    /** @return array Actual roster capture; late admission cannot enter this already-captured array */
    public function getClients(): array
    {
        ++$this->enumerations;
        $clients = parent::getClients();
        $after = $this->afterCapture;
        $this->afterCapture = null;
        $after?->__invoke();
        return $clients;
    }

    /** @param ConnectionNativeClient $client Exact accepted transport, admitted through the central index */
    public function seed(ConnectionNativeClient $client): void
    {
        $this->addClient($client);
        $this->attachClient($client);
    }

    /** The actual listener needs no application start hook. */
    protected function onStart(): void
    {
    }

    /**
     * @param Socket $socket Actual accepted TCP socket
     * @return WebSocketClientInterface Real parser and handshake; key is still absent at acceptance
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return $this->accepted = new ConnectionNativeClient($socket);
    }
}

final class ConnectionNativeClient extends WebSocketClient
{
    public ?string $handshakeKey = null;
    public int $closes = 0;

    /**
     * @param Socket $socket Actual connected transport
     * @param ?string $key Optional already-keyed admission; null takes the real HTTP handshake
     */
    public function __construct(Socket $socket, ?string $key = null)
    {
        parent::__construct($socket);
        if ($key !== null) {
            $this->handshakeCompleted = true;
            $this->acceptKey = $key;
        }
    }

    /** Counts terminal effects while preserving production notification and delivery cleanup. */
    protected function onClose(): void
    {
        ++$this->closes;
        parent::onClose();
    }

    /** @return string Local fixture peer address without a provider */
    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /**
     * @param array<string, string> $headers Actual parsed headers
     * @param string $acceptKey Independently recorded minted input, before connection announcement
     * @param array<string, string> $cookies Actual parsed cookies
     * @param ?string $clientIp Actual address
     * @param RequestQueryParams $queryParams Actual query
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
        $this->handshakeKey = $acceptKey;
    }
}

/** Records every connection delta/snapshot invocation with no filtering or expected-output projection. */
final class ConnectionNativeMesh implements ClientMesh
{
    /** @var list<PeerConnectionsDeltaDTO|PeerConnectionsSnapshotDTO> Every typed connection output, in order */
    public array $frames = [];
    public ?Closure $afterDelta = null;

    /**
     * @param string $nodeId Unused remote node
     * @param string $acceptKey Unused browser
     * @param SignalDTO $signal Unused addressed signal
     * @return bool This fixture does not send remote client signals
     */
    public function sendSignalToClientNode(string $nodeId, string $acceptKey, SignalDTO $signal): bool
    {
        return true;
    }

    /** @param SignalDTO $signal Unused fanout */
    public function broadcastClientFanout(SignalDTO $signal): void
    {
    }

    /** @param list<string> $acceptKeys Unused sibling drops */
    public function broadcastConnectionDrop(array $acceptKeys): void
    {
    }

    /** @param SignalDTO $signal Unused page access verdict */
    public function broadcastPageAccessReassess(SignalDTO $signal): void
    {
    }

    /** @param list<string> $acceptKeys Unused group departures */
    public function broadcastGroupLeaveAll(array $acceptKeys): void
    {
    }

    /**
     * @param list<string> $opened Actual batched opens
     * @param list<string> $closed Actual batched closes
     */
    public function broadcastConnectionsDelta(array $opened, array $closed): void
    {
        $this->frames[] = new PeerConnectionsDeltaDTO('local', $opened, $closed);
        $after = $this->afterDelta;
        $this->afterDelta = null;
        $after?->__invoke();
    }

    /**
     * @param string $nodeId Explicit handover addressee
     * @param list<string> $acceptKeys Whole announced set
     */
    public function sendConnectionsSnapshotToNode(string $nodeId, array $acceptKeys): void
    {
        $this->frames[] = new PeerConnectionsSnapshotDTO('local', $acceptKeys);
    }
}

final class ConnectionNativeWorkers extends WorkerServer
{
    public bool $ready = false;

    /** No process is launched; the test controls only the worker drain verdict. */
    public function __construct()
    {
    }

    /** A real master shutdown may prepare this controlled worker boundary. */
    public function prepareShutdown(): void
    {
    }

    /** @return bool Whether all simulated worker connections have left */
    public function isReadyToShutdown(): bool
    {
        return $this->ready;
    }

    /** No listener is opened. */
    protected function onStart(): void
    {
    }
}
