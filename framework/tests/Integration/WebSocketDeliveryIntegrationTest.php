<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Daemon\SignalDispatcher;
use Hilos\Tests\Fixtures\SignalDispatcherFixture;
use Hilos\Tests\Unit\PerInstanceFallbackAgent;
use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\AgentSignalHolds;
use Hilos\Core\Daemon\PageHandoffIdentity;
use Hilos\Core\Daemon\PageSubscriptionContext;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\PendingPageDelivery;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRenderedSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Tests\Unit\Cluster\Placement\SnapshotTestLeadership;
use Hilos\Tests\Unit\PerInstanceIdentityTestAgent;
use Hilos\Tests\Unit\PerInstanceIdentityTestWorker;
use Hilos\Tests\Unit\PerInstancePublicIdentityPage;
use Hilos\Tests\Unit\PerInstanceRosterIdentityBrowser;
use Hilos\Tests\Unit\RosterConnection;
use Hilos\Tests\Unit\RosterRtContext;
use Hilos\Tests\Unit\SessionViewConnection;
use Hilos\Tests\Unit\SessionViewConnections;
use Hilos\Tests\Unit\SessionViewStates;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use Closure;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use Throwable;

require_once dirname(__DIR__) . '/Unit/Cluster/Placement/ClusterPlacementSnapshotTest.php';
require_once dirname(__DIR__) . '/Unit/DaemonManagerPerInstanceSubscriptionTest.php';
require_once dirname(__DIR__) . '/Unit/HilosConnectionsViewTest.php';

/**
 * One-process transport integration: real queue, socket bytes, final page handler and typed IPC.
 * No database or rollback scope is used. The split before completion is shorter than a stand
 * tick, so this test controls that boundary; real process/cluster coverage runs on the stand.
 */
final class WebSocketDeliveryIntegrationTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?TableContext $previousTables;
    private DeliveryFlowMaster $master;
    private DeliveryFlowServer $server;
    private DeliveryFlowWorkerServer $worker;
    private DeliveryFlowFactory $factory;
    private DeliveryFlowBrowser $browser;
    private PageSignalRouter $pages;
    /** @var list<array{DeliveryFlowClient, Socket}> Accepted socket pairs */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTables = Hilos::$table;
        Hilos::$env = new EnvAccessor();
        $this->master = new DeliveryFlowMaster();
        $this->server = new DeliveryFlowServer();
        $this->worker = new DeliveryFlowWorkerServer($this->master->getAgentManagerDaemon());
        $this->master->registerServer($this->server);
        $this->master->registerServer($this->worker);
        $this->browser = new DeliveryFlowBrowser();
        Hilos::$browser = $this->browser;
        $this->factory = new DeliveryFlowFactory(new DeliveryFlowAgent());
        $this->pages = new PageSignalRouter($this->factory, new ActionRouteConfig());
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as [$client, $peer]) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($client->acceptKey));
            $client->close();
            socket_close($peer);
        }
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$browser = $this->previousBrowser;
        Hilos::$table = $this->previousTables;
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
    }

    /** @return iterable<string, array{bool}> Worker output locally and after a peer hop */
    public static function paths(): iterable
    {
        yield 'worker' => [false];
        yield 'worker and peer' => [true];
    }

    #[DataProvider('paths')]
    public function testInitialPacketWaitsAcrossReadsAndTheWholeWindowForCompletion(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        self::assertNotSame('forged', $request->data->deliveryId);
        self::assertTrue($client->isDeliveryPending('delivery', $request->data->deliveryId));
        $this->handle($request);
        $signals = $this->takeSignals();
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response', 'hilos_delivery_complete'],
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        foreach (array_slice($signals, 0, -1) as $signal) {
            $this->deliver($signal, $client, $peer);
            $client->write();
            self::assertSame([], $this->read($socket));
        }
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket), 'Neither an IPC read boundary nor the window ends the hold.');
        $this->deliver($signals[4], $client, $peer);
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames, 'One physical WebSocket message carries the whole initial answer.');
        self::assertSame('signal_batch', $frames[0]['type']);
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response'],
            array_column($frames[0]['data']['frames'], 'type'));
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertSame([], $this->pending());
    }

    /** @return iterable<string, array{bool, bool, bool}> Real typed transport and identity parking paths */
    public static function restoredWindowPaths(): iterable
    {
        yield 'worker' => [false, false, false];
        yield 'worker and peer' => [true, false, false];
        yield 'master identity parking' => [true, true, false];
        yield 'page router identity parking' => [true, false, true];
    }

    /**
     * @param bool $peer Whether request and response cross peer serialization
     * @param bool $masterParking Whether identity resolution parks the request at the master
     * @param bool $pageParking Whether identity resolution parks it in the page router
     */
    #[DataProvider('restoredWindowPaths')]
    public function testRestoredWindowCommitsFacetsWithOnePhysicalAnswer(
        bool $peer,
        bool $masterParking,
        bool $pageParking,
    ): void {
        [$client, $socket] = $this->connect();
        $browser = new DeliveryRestoredWindowBrowser();
        Hilos::$browser = $browser;
        $table = new DeliveryRestoredWindowTable($client->acceptKey);
        Hilos::$table = new DeliveryRestoredWindowTables($table);
        Hilos::$table->configure();
        $descriptor = [
            'limit' => 1,
            'pageIndex' => 1,
            'filter' => ['search' => 'match', 'class' => 'agent'],
            'sort' => [['field' => 'id', 'direction' => 'desc']],
            'facets' => ['class' => ['daemon', 'agent', 'worker']],
            'rendered' => ['id', 'class', 'label'],
        ];
        $browser->identity = $masterParking ? ConnectionIdentity::pending() : ConnectionIdentity::resolved(null);
        $client->acceptJson(json_encode([
            'type' => 'page_subscribe', 'page' => 'identity', 'acceptKey' => 'forged', 'deliveryId' => 'forged',
            'tableWindows' => ['hilosLogKeys' => $descriptor],
        ], JSON_THROW_ON_ERROR));
        $this->master->drain();
        if ($masterParking) {
            self::assertSame([], $this->worker->requests);
            self::assertNull($table->firstQuery);
            $client->now = 10_000;
            $client->write();
            self::assertSame([], $this->read($socket));
            $browser->identity = ConnectionIdentity::resolved(null);
            new ReflectionMethod(SignalDispatcher::class, 'queueIdentityDiscovery')
                ->invoke(SignalDispatcherFixture::owner($this->master), $client->acceptKey);
            $this->master->drain();
        }
        self::assertCount(1, $this->worker->requests);
        $request = array_pop($this->worker->requests)->signal;
        if ($peer) {
            $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $request)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            $request = $forwarded->signal;
        }
        self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $request->data);
        self::assertSame($client->acceptKey, $request->data->acceptKey);
        self::assertSame($client->acceptKey . ':1', $request->data->deliveryId);
        self::assertSame(['hilosLogKeys' => $descriptor], array_map(
            static fn(TableWindowDescriptorDTO $window): array => $window->toArray(), $request->data->tableWindows));
        $browser->identity = $pageParking ? ConnectionIdentity::pending() : ConnectionIdentity::resolved(null);
        $this->handle($request);
        if ($pageParking) {
            self::assertSame([], $this->takeSignals());
            self::assertNull($table->firstQuery);
            $client->now = 10_000;
            $client->write();
            self::assertSame([], $this->read($socket));
            $browser->identity = ConnectionIdentity::resolved(null);
            $this->pages->releasePendingFrames();
        }
        self::assertSame([], $table->facetsAtFirstQuery);
        self::assertSame($descriptor['facets'], Hilos::$sr->getTableFacets($client->acceptKey, 'hilosLogKeys'));
        self::assertNotNull($table->firstQuery);
        self::assertSame('match', $table->firstQuery->search);
        self::assertSame($descriptor['filter'], $table->firstQuery->filter);
        self::assertSame($descriptor['sort'], $table->firstQuery->sort?->toArray());
        self::assertSame(1, $table->firstQuery->limit);
        self::assertSame(1, $table->firstQuery->pageIndex);
        $signals = $this->takeSignals();
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response', 'hilos_delivery_complete'],
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        foreach (array_slice($signals, 0, -1) as $signal) {
            $this->deliver($signal, $client, $peer);
            $client->write();
            self::assertSame([], $this->read($socket));
        }
        $client->now = 20_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        self::assertTrue($client->isDeliveryPending('identity', $request->data->deliveryId));
        $this->deliver($signals[4], $client, $peer);
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame('signal_batch', $frames[0]['type']);
        $inner = $frames[0]['data']['frames'];
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response'], array_column($inner, 'type'));
        $window = $inner[1]['data']['payload']['windows']['hilosLogKeys'];
        self::assertSame(1, $window['limit']);
        self::assertSame($descriptor['sort'], $window['sort']);
        self::assertSame(['b'], array_column($window['rows'], 'rowKey'));
        self::assertSame(2, $window['totalCount']);
        self::assertSame('hilosLogKeys', $inner[2]['data']['tableKey']);
        self::assertSame([
            'class' => ['any' => ['count' => 4, 'exact' => true], 'options' => [
                'daemon' => ['count' => 1, 'exact' => true],
                'agent' => ['count' => 2, 'exact' => true],
                'worker' => ['count' => 1, 'exact' => true],
            ]],
        ], $inner[2]['data']['facets']);
        self::assertFalse($client->isDeliveryPending('identity', $request->data->deliveryId));
        self::assertSame([], $this->pending());
    }

    /** @return iterable<string, array{Throwable, int}> Actual page refusals */
    public static function refusals(): iterable
    {
        yield '401' => [new PageUnauthorizedException(), 401];
        yield '403' => [new PageForbiddenException(), 403];
        yield '404' => [new PageResourceNotFoundException(), 404];
        yield '500' => [new PageInternalErrorException(), 500];
    }

    #[DataProvider('refusals')]
    public function testRefusalAlsoWaitsForItsInternalBoundary(Throwable $failure, int $status): void
    {
        [$client, $socket] = $this->connect();
        $this->factory->page()->failure = $failure;
        $request = $this->subscribe($client);
        $this->handle($request);
        $signals = $this->takeSignals();
        self::assertCount(2, $signals);
        $this->deliver($signals[0], $client);
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->deliver($signals[1], $client);
        $client->write();
        self::assertSame([['type' => 'subscription_page_error', 'data' => $signals[0]->data->data->toArray()]], $this->read($socket));
        self::assertSame($status, $signals[0]->data->data->httpCode);
        self::assertSame([], $this->pending());
    }

    public function testAcceptedRequestsToastsSessionsAndRepliesCannotBreakTheHold(): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        $client->acceptJson('{"type":"page_update_subscription","page":"delivery"}');
        $update = Hilos::$sr->getNextQueuedSignal();
        foreach (['toast', 'hilos_session_state', 'action_success', 'table_window'] as $type) {
            $client->sendFrame(json_encode(['type' => $type, 'data' => [], 'requestId' => 'r'], JSON_THROW_ON_ERROR));
        }
        $client->now = 10_000;
        $client->completeDelivery('delivery', $update->data->deliveryId);
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->handle($request);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame(['toast', 'hilos_session_state', 'action_success', 'table_window',
            'own_state', 'page_response', 'table_facet_counts', 'after_response'], array_column($frames[0]['data']['frames'], 'type'));
    }

    public function testOldOrWrongPageCompletionAndOldUnsubscribeCannotReleaseTheNewAttempt(): void
    {
        [$client, $socket] = $this->connect();
        $old = $this->subscribe($client);
        $client->sendFrame('{"type":"own_state","data":{"old":true}}');
        $new = $this->subscribe($client, 'other');
        $client->acceptJson('{"type":"page_unsubscribe","page":"delivery"}');
        Hilos::$sr->getNextQueuedSignal();
        $client->completeDelivery('delivery', $old->data->deliveryId);
        $client->completeDelivery('delivery', $new->data->deliveryId);
        $client->sendFrame('{"type":"own_state","data":{"new":true}}');
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->deliver(new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData('other', $new->data->deliveryId), targetAcceptKey: $client->acceptKey)), $client);
        $client->write();
        self::assertSame([['type' => 'own_state', 'data' => ['new' => true]]], $this->read($socket));
        self::assertSame([], $this->pending());
    }

    public function testDelayedUpdateDoesNotHoldAndUsesTheCurrentMirrorForReplyGrace(): void
    {
        [$client, $socket] = $this->connect();
        $mirror = new DeliverySettingsMirror();
        $client->setDeliverySettingsMirror($mirror);
        $client->acceptJson('{"type":"page_update_subscription","page":"delivery"}');
        $update = Hilos::$sr->getNextQueuedSignal();
        $client->sendFrame('{"type":"toast","data":1}');
        $client->write();
        self::assertSame([['type' => 'toast', 'data' => 1]], $this->read($socket));
        $client->now = 5000;
        $mirror->onDeliverySettings(new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor(), 200);
        $client->completeDelivery('delivery', $update->data->deliveryId);
        $client->sendFrame('{"type":"toast","data":2}');
        $client->write();
        self::assertCount(1, $this->read($socket));
        $client->now = 5199;
        $client->sendFrame('{"type":"toast","data":3}');
        $client->write();
        self::assertCount(1, $this->read($socket));
        $client->now = 5200;
        $client->completeDelivery('delivery', $update->data->deliveryId);
        $client->sendFrame('{"type":"toast","data":4}');
        $client->write();
        self::assertSame([], $this->read($socket));
        $client->now = 5399;
        $client->write();
        self::assertSame([['type' => 'toast', 'data' => 4]], $this->read($socket));
    }

    #[DataProvider('paths')]
    public function testUpdateHandlerOutputAndCompletionCrossTheActualTypedTransport(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        $this->handle($this->subscribe($client));
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client, $peer);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        $client->now = 5000;
        $client->acceptJson('{"type":"page_update_subscription","page":"delivery"}');
        $this->master->drain();
        $request = array_pop($this->worker->requests)->signal;
        self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $request->data);
        $this->handle($request);
        $signals = $this->takeSignals();
        self::assertCount(5, $signals);
        foreach (array_slice($signals, 0, -1) as $signal) {
            $this->deliver($signal, $client, $peer);
        }
        $client->write();
        self::assertCount(1, $this->read($socket), 'An update tracks completion without holding application delivery.');
        self::assertTrue($client->isDeliveryPending('delivery', $request->data->deliveryId));
        $client->now = 10_000;
        $this->deliver($signals[4], $client, $peer);
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertSame([], $this->pending());
        $client->write();
        self::assertSame([], $this->read($socket));
        $client->sendFrame('{"type":"toast","data":1}');
        $client->write();
        self::assertCount(1, $this->read($socket));
    }

    public function testMasterIdentityParkingHasNoFictitiousOwnerAndKeepsTheBrowserHold(): void
    {
        [$client, $socket] = $this->connect();
        $this->browser->identity = ConnectionIdentity::pending();
        $client->acceptJson('{"type":"page_subscribe","page":"identity"}');
        $this->master->drain();
        self::assertSame([], $this->worker->requests);
        self::assertSame([], $this->pending());
        self::assertCount(1, new ReflectionProperty(SignalDispatcher::class, 'parkedSignals')
            ->getValue(SignalDispatcherFixture::owner($this->master)));
        $client->sendFrame('{"type":"toast","data":1}');
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->browser->identity = ConnectionIdentity::resolved(null);
        new ReflectionMethod(SignalDispatcher::class, 'queueIdentityDiscovery')
            ->invoke(SignalDispatcherFixture::owner($this->master), $client->acceptKey);
        $this->master->drain();
        self::assertCount(1, $this->worker->requests);
        $request = array_pop($this->worker->requests)->signal;
        self::assertTrue($client->isDeliveryPending('identity', $request->data->deliveryId));
        self::assertCount(1, $this->pending()[$client->acceptKey]);
        $this->handle($request);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame(['toast', 'own_state', 'page_response', 'table_facet_counts', 'after_response'],
            array_column($frames[0]['data']['frames'], 'type'));
        self::assertSame([], $this->pending());
    }

    public function testIdentityParkingSurvivesTheWindowUntilTheActualHandlerRuns(): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        $this->browser->identity = ConnectionIdentity::pending();
        $this->handle($request);
        self::assertSame([], $this->takeSignals());
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->pages->releasePendingFrames();
        self::assertSame([], $this->takeSignals());
        $this->browser->identity = ConnectionIdentity::resolved(null);
        $this->pages->releasePendingFrames();
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        self::assertSame([], $this->pending());
    }

    public function testSourceParkingInTheWorkerDoesNotCompleteTheSocketHoldBeforeResume(): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        $router = Hilos::$sr;
        $worker = new DeliveryFlowWorker($this->pages);
        Hilos::$sr = $router;
        SourceInterestRegistry::readsWhatIsDelivered();
        $consumer = SourceConsumer::page($client->acceptKey);
        SourceInterestRegistry::register(SourceChange::KIND_RT, 'deliverySource', $consumer);
        new ReflectionMethod(WorkerManager::class, 'waitForSources')->invoke($worker, $consumer, [SourceChange::KIND_RT => ['deliverySource']]);
        $worker->handleDaemonMessage(WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO('owner', $request)->toJson()));
        $worker->pass();
        self::assertSame([], $this->takeSignals());
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        self::assertTrue($client->isDeliveryPending('delivery', $request->data->deliveryId));
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, 'deliverySource');
        $worker->pass();
        $signals = $this->takeSignals();
        self::assertSame(['rt_staleness', 'own_state', 'page_response', 'table_facet_counts', 'after_response', 'hilos_delivery_complete'],
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        foreach ($signals as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
    }

    /** @return iterable<string, array{string}> Refusals before a worker handles the request */
    public static function earlyRefusals(): iterable
    {
        foreach (['unserved', 'unreachable', 'start'] as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('earlyRefusals')]
    public function testMasterRefusalCompletesTheAcceptedBrowserToken(string $page): void
    {
        [$client, $socket] = $this->connect();
        $this->worker->refuse = $page === 'start';
        $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => $page], JSON_THROW_ON_ERROR));
        $this->master->drain();
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame('subscription_page_error', $frames[0]['type']);
        self::assertSame($page, $frames[0]['data']['page']);
        self::assertSame($page === 'unserved' ? 404 : 503, $frames[0]['data']['httpCode']);
        self::assertSame([], $this->pending());
        $client->sendFrame('{"type":"toast","data":1}');
        $client->write();
        self::assertCount(1, $this->read($socket), 'The refusal completion opens response grace.');
    }

    /** @return iterable<string, array{string}> Real master hooks while an attempt is pending */
    public static function losses(): iterable
    {
        foreach (['worker', 'stop', 'link', 'node left'] as $event) {
            yield $event => [$event];
        }
    }

    #[DataProvider('losses')]
    public function testLossCancelsOnlyItsPendingSocketAndDoesNotRepeat(string $event): void
    {
        [$client, $socket] = $this->connect();
        [$other, $otherSocket] = $this->connect();
        $request = $this->subscribe($client);
        $completed = $this->subscribe($other);
        $this->handle($completed);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $other);
        }
        $other->write();
        self::assertCount(1, $this->read($otherSocket));
        if (in_array($event, ['link', 'node left'], true)) {
            $this->master->route($request, new RemoteAgentDestination('remote', 'owner'), $this->worker);
        }
        self::assertCount(1, $this->pending()[$client->acceptKey]);
        $client->sendFrame('{"type":"own_state","data":{"incomplete":true}}');
        $fire = function () use ($event): void {
            match ($event) {
                'worker' => $this->master->reportAgentsLostWithWorker(3, false, ['owner']),
                'stop' => $this->master->onAgentStopped('owner'),
                'link' => $this->master->noteNodeUnreachable('remote', microtime(true)),
                'node left' => $this->master->onNodeLeft($this->remoteNode()),
            };
        };
        $fire();
        $fire();
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        $this->master->drain();
        self::assertSame([], $this->pending());
        $client->now = $other->now = 10_000;
        $client->write();
        $other->write();
        self::assertSame([['type' => 'page_resubscribe', 'data' => ['page' => 'delivery', 'params' => []]]], $this->read($socket));
        self::assertSame([], $this->read($otherSocket));
        $client->completeDelivery('delivery', $request->data->deliveryId);
        $client->write();
        self::assertSame([], $this->read($socket));
    }

    #[DataProvider('paths')]
    public function testParkedStartFailureIsOneTerminalRefusalFollowedByItsCompletion(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        $client->acceptJson('{"type":"page_subscribe","page":"delivery","params":{"id":1}}');
        $request = Hilos::$sr->getNextQueuedSignal();
        $socketRouter = Hilos::$sr;
        $serving = $this->master;
        $worker = $this->worker;
        if ($peer) {
            Hilos::$sr->queueSignal($request->signalSource, $request->signalType, $request->signalName, $request->data);
            $this->master->drain();
            $serving = new DeliveryFlowMaster();
            $worker = new DeliveryFlowWorkerServer();
            $serving->registerServer($worker);
        }
        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($serving);
        $agents->started = false;
        $worker->awaiting = true;
        if ($peer) {
            $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $request)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            $serving->deliverSignalToAgent('owner', null, $forwarded->signal);
        } else {
            Hilos::$sr->queueSignal($request->signalSource, $request->signalType, $request->signalName, $request->data);
            $serving->drain();
        }
        self::assertFalse($agents->isAgentStarted('owner'));
        self::assertSame([], $worker->requests);
        $parked = $this->heldAgentFrames($serving);
        self::assertCount(1, $parked);
        self::assertSame($request->data->deliveryId, $parked[0]->signal->data->deliveryId);
        self::assertCount(1, new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($serving)));
        $starts = $worker->startRequests;
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
        $report = WorkerDTO::factoryWorkerDTO(new WorkerAgentStartFailedDTO('owner', 'owner', null, 'onStart refused')->toJson());
        self::assertInstanceOf(WorkerAgentStartFailedDTO::class, $report);
        $agents->handleAgentStartFailed($report);
        $signals = $this->takeSignals();
        self::assertSame(['subscription_page_error', 'hilos_delivery_complete'],
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        self::assertSame('agent_unavailable', $signals[0]->data->data->errorCode);
        self::assertSame(503, $signals[0]->data->data->httpCode);
        self::assertSame('delivery', $signals[1]->data->data->page);
        self::assertSame($request->data->deliveryId, $signals[1]->data->data->deliveryId);
        if ($peer) {
            $signals = $this->forwardFromServing($serving, $signals, $client->acceptKey);
            self::assertSame(['subscription_page_error', 'hilos_delivery_complete'],
                array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
            self::assertSame([],
                new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')->getValue(SignalDispatcherFixture::owner($serving)));
        }
        Hilos::$sr = $socketRouter;
        $this->deliver($signals[0], $client, $peer);
        $client->write();
        self::assertSame([], $this->read($socket), 'The refusal still waits for its service boundary.');
        $this->deliver($signals[1], $client, $peer);
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame('subscription_page_error', $frames[0]['type']);
        self::assertSame(503, $frames[0]['data']['httpCode']);
        self::assertSame('agent_unavailable', $frames[0]['data']['errorCode']);
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertSame([], $this->pending());
        self::assertSame([], $this->heldAgentFrames($serving));
        self::assertSame([], $this->heldAgentFrames($serving, released: true));
        self::assertSame([], new ReflectionProperty(SignalDispatcher::class, 'parkedSignals')->getValue(SignalDispatcherFixture::owner($serving)));
        $this->deliver($signals[1], $client, $peer);
        $serving->onAgentStartFailed('owner', 'late duplicate');
        $serving->drain();
        $this->master->drain();
        self::assertSame([], $worker->requests, 'The refusal must not cause another start or dispatch.');
        self::assertSame($starts, $worker->startRequests);
        $client->write();
        self::assertSame([], $this->read($socket));
    }

    public function testParkedUpdateFailureCompletesOnlyItsTokenWithoutASubscribeError(): void
    {
        [$client, $socket] = $this->connect();
        [$other, $otherSocket] = $this->connect();
        $initial = $this->subscribe($client);
        $this->handle($initial);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        $held = $this->subscribe($other);
        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->master);
        $agents->started = false;
        $this->worker->awaiting = true;
        $client->acceptJson('{"type":"page_update_subscription","page":"delivery"}');
        $update = Hilos::$sr->getNextQueuedSignal();
        Hilos::$sr->queueSignal($update->signalSource, $update->signalType, $update->signalName, $update->data);
        $this->master->drain();
        self::assertCount(1, $this->heldAgentFrames());
        $this->master->onAgentStartFailed('owner', 'update owner refused');
        $signals = $this->takeSignals();
        self::assertSame(['hilos_delivery_complete'],
            array_map(static fn (SignalDTO $signal): string => $signal->signalName->getName(), $signals));
        self::assertSame($update->data->deliveryId, $signals[0]->data->data->deliveryId);
        $client->acceptJson('{"type":"page_subscribe","page":"delivery","params":{"id":2}}');
        $new = $client->pendingPageSubscribe();
        self::assertNotNull($new);
        $this->deliver($signals[0], $client);
        self::assertFalse($client->isDeliveryPending('delivery', $update->data->deliveryId));
        self::assertTrue($client->isDeliveryPending('delivery', $new->deliveryId));
        self::assertTrue($other->isDeliveryPending('delivery', $held->data->deliveryId));
        $client->write();
        $other->now = 10_000;
        $other->write();
        self::assertSame([], $this->read($socket));
        self::assertSame([], $this->read($otherSocket));
    }

    #[DataProvider('paths')]
    public function testStaleRecoveryPreservesTheNewAttemptAndItsWholePhysicalAnswer(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        [$other, $otherSocket] = $this->connect();
        $old = $this->subscribe($client, params: ['id' => 1]);
        $new = $this->subscribe($client, params: ['id' => 2]);
        $unrelated = $this->subscribe($other);
        $this->handle($new);
        $signals = $this->takeSignals();
        foreach (array_slice($signals, 0, -1) as $signal) {
            $this->deliver($signal, $client, $peer);
        }
        $ledger = $this->pending();
        $control = $this->recovery($client, ['id' => 1]);
        if ($peer) {
            $socketRouter = Hilos::$sr;
            $serving = new DeliveryFlowMaster();
            $serving->registerServer(new DeliveryFlowWorkerServer());
            foreach ([$old, $new] as $request) {
                $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $request)->toJson());
                self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
                $serving->deliverSignalToAgent('owner', null, $forwarded->signal);
            }
            $servingLedger = new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries');
            $before = $servingLedger->getValue(SignalDispatcherFixture::owner($serving));
            Hilos::$sr->queueSignal($control->signalSource, $control->signalType, $control->signalName, $control->data);
            $serving->drain();
            self::assertSame($before, $servingLedger->getValue(SignalDispatcherFixture::owner($serving)));
            Hilos::$sr = $socketRouter;
            $this->deliver($control, $client, peer: true);
        } else {
            Hilos::$sr->queueSignal($control->signalSource, $control->signalType, $control->signalName, $control->data);
            $this->master->drain();
        }
        self::assertSame($ledger, $this->pending());
        self::assertTrue($client->isDeliveryPending('delivery', $new->data->deliveryId));
        self::assertTrue($other->isDeliveryPending('delivery', $unrelated->data->deliveryId));
        $client->now = 10_000;
        new ReflectionMethod(WebSocketClient::class, 'drainDelivery')->invoke($client, true);
        $client->write();
        $other->write();
        self::assertSame([], $this->read($socket));
        self::assertSame([], $this->read($otherSocket));
        $this->deliver(new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData('delivery', $old->data->deliveryId), targetAcceptKey: $client->acceptKey)),
            $client, $peer);
        self::assertSame($ledger, $this->pending());
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->deliver($signals[count($signals) - 1], $client, $peer);
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame('signal_batch', $frames[0]['type']);
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response'], array_column($frames[0]['data']['frames'], 'type'));
        self::assertArrayNotHasKey($client->acceptKey, $this->pending());
        self::assertArrayHasKey($other->acceptKey, $this->pending());
    }

    public function testQueuedOldRecoveryCannotCancelANewParsedSubscribeBeforeDispatch(): void
    {
        [$client, $socket] = $this->connect();
        $old = $this->subscribe($client, params: ['id' => 1]);
        $control = $this->recovery($client, ['id' => 1]);
        Hilos::$sr->queueSignal($control->signalSource, $control->signalType, $control->signalName, $control->data);
        $client->acceptJson('{"type":"page_subscribe","page":"delivery","params":{"id":2}}');
        $this->worker->requests = [];
        $this->master->drain();
        self::assertCount(1, $this->worker->requests);
        $new = $this->worker->requests[0]->signal;
        self::assertNotSame($old->data->deliveryId, $new->data->deliveryId);
        self::assertSame(['id' => 2], $new->data->params);
        self::assertTrue($client->isDeliveryPending('delivery', $new->data->deliveryId));
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
    }

    /** @return iterable<string, array{bool}> The new request on either side of a start report */
    public static function startQueueStates(): iterable
    {
        yield 'parked' => [false];
        yield 'released' => [true];
    }

    #[DataProvider('startQueueStates')]
    public function testStalePeerRecoveryCannotRemoveTheNewStartQueueRequest(bool $released): void
    {
        [$client] = $this->connect();
        $this->subscribe($client, params: ['id' => 1]);
        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->master);
        $agents->started = false;
        $this->worker->awaiting = true;
        $client->acceptJson('{"type":"page_subscribe","page":"delivery","params":{"id":2}}');
        $this->master->drain();
        if ($released) {
            $this->master->onAgentStarted('owner');
        }
        $queue = $this->heldAgentFrames(released: $released);
        self::assertCount(1, $queue);
        $ledger = $this->pending();
        $this->deliver($this->recovery($client, ['id' => 1]), $client, peer: true);
        self::assertSame($queue, $this->heldAgentFrames(released: $released));
        self::assertSame($ledger, $this->pending());
        if (!$released) {
            $this->master->onAgentStarted('owner');
        }
        $agents->started = true;
        $this->worker->requests = [];
        $this->master->drain();
        self::assertCount(1, $this->worker->requests);
        self::assertSame($queue[0]->signal->data->deliveryId, $this->worker->requests[0]->signal->data->deliveryId);
    }

    #[DataProvider('paths')]
    public function testMatchingRecoveryDiscardsItsBufferBeforeControlAndAllowsTheSameSocketToSubscribe(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client, params: ['id' => 1, 'options' => ['a' => true, 'b' => [1, '2']]]);
        $client->sendFrame('{"type":"own_state","data":{"incomplete":true}}');
        $control = $this->recovery($client, ['options' => ['b' => [1, '2'], 'a' => true], 'id' => 1]);
        if ($peer) {
            $this->deliver($control, $client, peer: true);
        } else {
            Hilos::$sr->queueSignal($control->signalSource, $control->signalType, $control->signalName, $control->data);
            $this->master->drain();
        }
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertSame([], $this->pending());
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame('page_resubscribe', $frames[0]['type']);
        $new = $this->subscribe($client, params: $request->data->params);
        $this->handle($new);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client, $peer);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        self::assertSame([], $this->pending());
    }

    #[DataProvider('paths')]
    public function testRecoveryStillReachesCompletedAndSyntheticPagesWithoutAPendingToken(bool $peer): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client, params: ['id' => 1]);
        $this->handle($request);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client, $peer);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        self::assertSame([], $this->pending());
        $this->deliver($this->recovery($client, ['id' => 1]), $client, $peer);
        $client->write();
        self::assertSame([['type' => 'page_resubscribe', 'data' => ['page' => 'delivery', 'params' => ['id' => 1]]]], $this->read($socket));
        [$synthetic, $syntheticSocket] = $this->connect();
        $this->deliver($this->recovery($synthetic, []), $synthetic, $peer);
        $synthetic->write();
        self::assertSame([['type' => 'page_resubscribe', 'data' => ['page' => 'delivery', 'params' => []]]], $this->read($syntheticSocket));
    }

    /** @param array<string, mixed> $params Recovery identity */
    private function recovery(DeliveryFlowClient $client, array $params): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::PAGE_RESUBSCRIBE),
            new WebSocketSignalData(new PageResubscribeSignalData('delivery', $params), targetAcceptKey: $client->acceptKey));
    }

    /** @return iterable<string, array{bool}> Successful handoff with or without subsequent link loss */
    public static function relayLinkOutcomes(): iterable
    {
        yield 'exact attempt settlement' => [false];
        yield 'outbound link lost after completion' => [true];
    }

    /** @param bool $loseLink Whether to report the loss of only the relay-to-serving link */
    #[DataProvider('relayLinkOutcomes')]
    public function testRecoveryRelaySettlesItsOwnSubscriptionWhenItsPeerQueueAcceptsIt(bool $loseLink): void
    {
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $request = $this->subscribe($client, params: ['id' => 23]);
        $deliveryId = $request->data->deliveryId;
        $socketRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        $this->master->route($request, new RemoteAgentDestination('relay', 'owner'), $this->worker);
        $relay = new DeliveryFlowMaster();
        $relayRouter = Hilos::$sr;
        $relay->registerServer(new DeliveryFlowWorkerServer());
        [$peer, $link, $reader] = $this->connectPeer('relay', 'remote');
        try {
            $relay->registerServer($peer);
            $placement = $this->beginRelayRecovery();
            $firstHop = PeerDTO::fromWire(new PeerSignalDTO('socket', 'relay', 'owner', null, $request)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $firstHop);
            Hilos::$cluster->registerAgentSignalSink($relay);
            $peer->onSignalReceived($link, $firstHop);
            $relay->drain();
            $relayOwner = SignalDispatcherFixture::owner($relay);
            $relayIndex = $relayOwner->pendingPageIndex();
            self::assertNull($relayIndex->get($acceptKey, $deliveryId)->nodeId);
            self::assertCount(1, $this->heldAgentFrames($relay));
            $other = new PendingPageDelivery($acceptKey, 'delivery', 'another-attempt', ['id' => 24], 'owner', 'socket');
            $relayOwner->recordPendingPageDelivery($other);

            $order = new PlacementId('socket', 1, 2);
            $records = [new PlacementRecord('owner', null, 'remote', PlacementState::Started, placementId: $order, workerStarted: true)];
            $addresses = ['remote' => [new PeerPlacedAgentEntry('owner', null)]];
            $placement->onPlacementView('socket', new PeerPlacementViewDTO('socket', $addresses, new PlacementSnapshot('socket', 1, 2, $records)));
            $relay->drain();
            [$forwarded] = $this->takePeerFrames($link, $reader, 1);
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            self::assertTrue($forwarded->placementRecoveryRelay);
            self::assertSame('remote', $forwarded->targetNodeId);
            self::assertSame($acceptKey, $forwarded->signal->data->acceptKey);
            self::assertSame($deliveryId, $forwarded->signal->data->deliveryId);
            self::assertSame($other, $relayIndex->get($acceptKey, $other->deliveryId));
            if (!$loseLink) {
                self::assertNull($relayIndex->get($acceptKey, $deliveryId));
            }
            self::assertSame('relay', $this->pending()[$acceptKey][$deliveryId]->nodeId);
            self::assertTrue($client->isDeliveryPending('delivery', $deliveryId));
            $placement->onPlacementView('socket', new PeerPlacementViewDTO('socket', $addresses, new PlacementSnapshot('socket', 1, 3, $records)));
            $relay->drain();
            self::assertFalse($link->hasPendingWrite());

            Hilos::$cluster = new ClusterContext();
            $serving = new DeliveryFlowMaster();
            $servingWorker = new DeliveryFlowWorkerServer();
            $serving->registerServer($servingWorker);
            $serving->deliverSignalToAgent($forwarded->agentType, $forwarded->agentIndex, $forwarded->signal,
                placementRecoveryRelay: $forwarded->placementRecoveryRelay);
            self::assertCount(1, $servingWorker->requests);
            $servingIndex = SignalDispatcherFixture::owner($serving)->pendingPageIndex();
            self::assertNotNull($servingIndex->get($acceptKey, $deliveryId));
            $this->handle($servingWorker->requests[0]->signal);
            $signals = $this->forwardFromServing($serving, $this->takeSignals(), $acceptKey);
            $completion = $signals[array_key_last($signals)];
            self::assertSame(SignalConstants::DELIVERY_COMPLETE, $completion->signalName->getName());
            self::assertSame($deliveryId, $completion->data->data->deliveryId);
            self::assertNull($servingIndex->get($acceptKey, $deliveryId));
            Hilos::$sr = $socketRouter;
            foreach ($signals as $signal) {
                $this->deliver($signal, $client, peer: true);
            }
            $client->write();
            $frames = $this->read($socket);
            self::assertCount(1, $frames);
            self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response'],
                array_column($frames[0]['data']['frames'], 'type'));
            self::assertFalse($client->isDeliveryPending('delivery', $deliveryId));
            self::assertSame([], $this->pending());

            if ($loseLink) {
                Hilos::$sr = $relayRouter;
                $link->close();
                $relay->noteNodeUnreachable('remote', microtime(true));
                self::assertSame([], $this->takeSignals(), 'The completed relay must not ask the origin to subscribe again');
                self::assertSame($other, $relayIndex->get($acceptKey, $other->deliveryId));
            }
        } finally {
            $link->close();
            socket_close($reader);
            Hilos::$cluster = $previousCluster;
            Hilos::$sr = $socketRouter;
        }
    }

    public function testRefreezeCompletesTheOlderRemoteUpdateBeforeYoungerCompletionAndHostLoss(): void
    {
        [$client, $socket] = $this->connect();
        $initial = $this->subscribe($client, params: ['id' => 23]);
        $this->handle($initial);
        foreach ($this->takeSignals() as $signal) {
            $this->deliver($signal, $client);
        }
        $client->write();
        self::assertCount(1, $this->read($socket));
        $updates = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $client->acceptJson('{"type":"page_update_subscription","page":"delivery","params":{"id":23}}');
            $update = Hilos::$sr->getNextQueuedSignal();
            self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $update?->data);
            $this->master->route($update, new RemoteAgentDestination('relay', 'owner'), $this->worker);
            $updates[] = $update;
        }
        [$old, $young] = $updates;
        $socketRouter = Hilos::$sr;
        $socketCluster = Hilos::$cluster;
        $relay = new DeliveryFlowMaster();
        $relay->registerServer(new DeliveryFlowWorkerServer());
        [$peer, $link, $reader] = $this->connectPeer('relay', 'socket');
        try {
            $relay->registerServer($peer);
            $this->beginRelayRecovery();
            $locations = new ClusterClientLocation('relay');
            $locations->applySnapshot('socket', [$client->acceptKey]);
            Hilos::$cluster->registerClientLocation($locations);
            $owner = SignalDispatcherFixture::owner($relay);
            $owner->recordPendingPageDelivery(new PendingPageDelivery(
                $client->acceptKey, 'delivery', $old->data->deliveryId, $old->data->params, 'owner', null,
            ));
            $index = $owner->pendingPageIndex();
            $relay->deliverSignalToAgent('owner', null, $young);
            self::assertCount(1, $this->heldAgentFrames($relay));
            self::assertNotNull($index->get($client->acceptKey, $young->data->deliveryId));

            $relay->onAgentStopped('owner');
            $relay->drain();
            self::assertTrue($link->hasPendingWrite(), 'The socket master is owed the older update completion');
            [$completion] = $this->takePeerFrames($link, $reader, 1);
            self::assertInstanceOf(PeerClientSignalDTO::class, $completion);
            self::assertSame(SignalConstants::DELIVERY_COMPLETE, $completion->signal->signalName->getName());
            self::assertSame($old->data->deliveryId, $completion->signal->data->data->deliveryId);
            self::assertNull($index->get($client->acceptKey, $old->data->deliveryId));
            self::assertNotNull($index->get($client->acceptKey, $young->data->deliveryId));

            Hilos::$sr = $socketRouter;
            Hilos::$cluster = $socketCluster;
            $this->deliver($completion->signal, $client, peer: true);
            self::assertFalse($client->isDeliveryPending('delivery', $old->data->deliveryId));
            self::assertTrue($client->isDeliveryPending('delivery', $young->data->deliveryId));

            $this->handle($young);
            foreach ($this->takeSignals() as $signal) {
                $this->deliver($signal, $client, peer: true);
            }
            self::assertFalse($client->isDeliveryPending('delivery', $young->data->deliveryId));
            $client->write();
            $this->read($socket);
            $this->master->noteNodeUnreachable('relay', microtime(true));
            $this->master->drain();
            $client->write();
            self::assertSame([], $this->read($socket));
            self::assertSame([], $this->pending());
        } finally {
            $link->close();
            socket_close($reader);
            Hilos::$sr = $socketRouter;
            Hilos::$cluster = $socketCluster;
        }
    }

    /** @return iterable<string, array{bool, ?bool}> Original operation and newer update's state on arrival */
    public static function terminalRecoveryArrivals(): iterable
    {
        yield 'only subscribe' => [false, null];
        yield 'only update' => [true, null];
        yield 'newer update after incomplete subscribe' => [false, false];
        yield 'newer update pending' => [true, false];
        yield 'newer update completed' => [true, true];
    }

    #[DataProvider('terminalRecoveryArrivals')]
    public function testTerminalPeerRecoverySettlesOnlyItsOriginalDelivery(bool $update, ?bool $newerCompleted): void
    {
        [$client, $socket] = $this->connect();
        $params = ['id' => 23];
        $old = $this->subscribe($client, params: $params);
        if ($update) {
            $this->handle($old);
            foreach ($this->takeSignals() as $signal) {
                $this->deliver($signal, $client);
            }
            $client->write();
            self::assertCount(1, $this->read($socket));
            $old = $this->acceptUpdate($client, $params);
        } else {
            $client->sendFrame('{"type":"own_state","data":{"incomplete":true}}');
        }
        $acceptKey = $client->acceptKey;
        $socketRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        $this->master->route($old, new RemoteAgentDestination('remote', 'owner'), $this->worker);
        $serving = new DeliveryFlowMaster();
        $serving->registerServer(new DeliveryFlowWorkerServer());
        [$sendingPeer, $sendingLink, $reader] = $this->connectPeer('remote', 'socket');
        [$receivingPeer, $receivingLink, $incoming] = $this->connectPeer('socket', 'remote');
        try {
            $serving->registerServer($sendingPeer);
            Hilos::$cluster = new ClusterContext();
            $locations = new ClusterClientLocation('remote');
            $locations->applySnapshot('socket', [$acceptKey]);
            Hilos::$cluster->registerClientLocation($locations);
            $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $old)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            $serving->deliverSignalToAgent('owner', null, $forwarded->signal);
            $serving->onAgentStopped('owner');
            $serving->drain();
            self::assertNull(SignalDispatcherFixture::owner($serving)->pendingPageIndex()->get($acceptKey, $old->data->deliveryId));
            [$control] = $this->takePeerFrames($sendingLink, $reader, 1);
            self::assertInstanceOf(PeerClientSignalDTO::class, $control);
            self::assertSame(SignalConstants::PAGE_RESUBSCRIBE, $control->signal->signalName->getName());
            self::assertSame($old->data->deliveryId, $control->pageRecoveryDeliveryId);

            Hilos::$sr = $socketRouter;
            Hilos::$cluster = new ClusterContext();
            Hilos::$cluster->registerClientSignalSink($this->master);
            $new = null;
            if ($newerCompleted !== null) {
                $new = $this->acceptUpdate($client, $params);
                $this->master->route($new, new RemoteAgentDestination('remote', 'owner'), $this->worker);
                self::assertNotSame($old->data->deliveryId, $new->data->deliveryId);
                if ($newerCompleted) {
                    $this->assertUpdateAnswer($client, $socket, $new);
                }
            }
            self::assertTrue($client->isDeliveryPending('delivery', $old->data->deliveryId));
            $receivingPeer->onClientSignalReceived($receivingLink, $control);
            $this->master->drain();
            self::assertFalse($client->isDeliveryPending('delivery', $old->data->deliveryId));
            self::assertArrayNotHasKey($old->data->deliveryId, $this->pending()[$acceptKey] ?? []);
            $client->now += 5000;
            $client->write();
            self::assertSame($new === null ? [['type' => 'page_resubscribe', 'data' => ['page' => 'delivery', 'params' => $params]]] : [],
                $this->read($socket));

            if ($newerCompleted === false) {
                self::assertTrue($client->isDeliveryPending('delivery', $new->data->deliveryId));
                self::assertArrayHasKey($new->data->deliveryId, $this->pending()[$acceptKey]);
                $this->assertUpdateAnswer($client, $socket, $new);
            }
            $receivingPeer->onClientSignalReceived($receivingLink, $control);
            $this->master->drain();
            $client->now += 5000;
            $client->write();
            self::assertSame([], $this->read($socket));
            self::assertSame([], $this->pending());
        } finally {
            $sendingLink->close();
            $receivingLink->close();
            socket_close($reader);
            socket_close($incoming);
            Hilos::$sr = $socketRouter;
            Hilos::$cluster = $previousCluster;
        }
    }

    public function testAnOrdinaryFrameRechecksItsAddressAtThePeerSendBoundary(): void
    {
        $previousCluster = Hilos::$cluster;
        $previousApp = Hilos::appClass();
        $app = new ReflectionProperty(Hilos::class, 'appClass');
        $app->setValue(null, DeliveryFlowPlacementHilos::class);
        Hilos::$cluster = new ClusterContext();
        $leadership = $this->createStub(Leadership::class);
        $leadership->method('leaderId')->willReturn('leader');
        Hilos::$cluster->registerLeadership($leadership);
        $mesh = $this->createStub(PlacementMesh::class);
        $ask = null;
        $mesh->method('sendToNode')->willReturnCallback(static function (string $node, PeerDTO $frame) use (&$ask): bool {
            if ($frame instanceof PeerPlacementRequestDTO) {
                $ask = $frame;
            }
            return true;
        });
        $placement = new ClusterPlacement('socket', $mesh, $this->createStub(PlacementExecutor::class));
        Hilos::$cluster->registerWorkerPlacement($placement);
        [$peer, $link, $reader] = $this->connectPeer('socket', 'old');
        try {
            $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('authority', 7));
            $placement->requirePlacement('owner', null);
            self::assertInstanceOf(PeerPlacementRequestDTO::class, $ask);
            $placement->onPlacementVerdict('leader', PeerPlacementVerdictDTO::placed('owner', null, 'old')->forRequest($ask));
            $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName('physical-address'), new SignalData());
            $destination = Hilos::$sr->placeAgentDestination(new AgentDestination('owner'));
            self::assertInstanceOf(RemoteAgentDestination::class, $destination);
            $delivery = SignalDispatcherFixture::owner($this->master)->agentDestinationDelivery(
                SignalDispatcherFixture::direct($signal), $this->worker, $peer, $destination, $signal,
            );
            $delivery->rewind();
            $delivery->next();
            $delivery->next();
            $delivery->next();
            self::assertTrue($delivery->valid());
            $address = new PlacementRecord('owner', null, 'socket', PlacementState::Started,
                placementId: new PlacementId('leader', 7, 1), workerStarted: true);
            $placement->onPlacementView('leader', new PeerPlacementViewDTO(
                'leader', ['socket' => [new PeerPlacedAgentEntry('owner', null)]], new PlacementSnapshot('leader', 7, 1, [$address]),
            ));
            while ($delivery->valid()) {
                $delivery->next();
            }
            self::assertFalse($link->hasPendingWrite());
            self::assertCount(1, $this->worker->requests);
            self::assertEquals($signal, $this->worker->requests[0]->signal);
        } finally {
            $link->close();
            socket_close($reader);
            $app->setValue(null, $previousApp);
            Hilos::$cluster = $previousCluster;
        }
    }

    /** @return iterable<string, array{bool}> Which existing terminal door answers the held handoff */
    public static function handoffRefusals(): iterable
    {
        yield 'start refused' => [false];
        yield 'placement refused' => [true];
    }

    #[DataProvider('handoffRefusals')]
    public function testTerminalHandoffRefusalRecoversWhenItsAgentReportsAStart(bool $notPlaced): void
    {
        $previousRt = Hilos::$rt;
        try {
            [$client, $socket] = $this->connect();
            $page = PerInstancePublicIdentityPage::PAGE;
            $type = PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE;
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = $this->handoffRuntime($client->acceptKey);
            $this->subscribe($client, $page);
            $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->master);
            $agents->started = false;
            $this->worker->awaiting = true;
            $this->applyHandoffIdentity($this->master, $client->acceptKey);
            $this->master->drain();
            $pending = array_values($this->pending()[$client->acceptKey]);
            self::assertCount(1, $pending);
            self::assertTrue($pending[0]->handoff);
            self::assertSame($type . ':7', $pending[0]->agentId);
            $client->write();
            self::assertSame([], $this->read($socket));

            if ($notPlaced) {
                $this->master->onAgentNotPlaced($type, '7', 'placement refused');
            } else {
                $this->master->onAgentStartFailed($pending[0]->agentId, 'start refused');
            }
            $signals = $this->takeSignals();
            self::assertCount(1, $signals);
            $this->deliver($signals[0], $client, peer: true);
            $client->write();
            $error = $this->read($socket);
            self::assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($error, 'type'));
            self::assertSame($page, $error[0]['data']['page']);

            $this->master->onAgentStopped($pending[0]->agentId);
            $this->master->drain();
            $client->write();
            self::assertSame([], $this->read($socket), 'A stop does not make the address available.');
            self::assertTrue($client->isDeliveryPending($page, $pending[0]->deliveryId));
            self::assertTrue($client->isLatestPageDelivery($pending[0]->deliveryId));
            self::assertTrue(SignalDispatcherFixture::owner($this->master)->pendingPageIndex()
                ->get($client->acceptKey, $pending[0]->deliveryId)?->awaitsRecovery);
            $this->worker->awaiting = false;
            $agents->started = true;
            $this->master->onAgentStarted($pending[0]->agentId);
            $this->master->drain();
            self::assertFalse($client->isDeliveryPending($page, $pending[0]->deliveryId));
            $client->now += 5000;
            $client->write();
            self::assertSame([['type' => SignalConstants::PAGE_RESUBSCRIBE, 'data' => ['page' => $page, 'params' => []]]],
                $this->read($socket));
            $new = $this->subscribe($client, $page);
            $this->deliver($signals[0], $client, peer: true);
            $client->write();
            self::assertSame([], $this->read($socket));
            self::assertTrue($client->isDeliveryPending($page, $new->data->deliveryId));
        } finally {
            Hilos::$rt = $previousRt;
        }
    }

    /** @return iterable<string, array{bool}> Whether a replacement arrives before the accepted control drains */
    public static function terminalRecoveryLifetimes(): iterable
    {
        yield 'current recovery' => [false];
        yield 'replaced by the same page and params' => [true];
    }

    #[DataProvider('terminalRecoveryLifetimes')]
    public function testTerminalRefusalRetriesWhenStartedWasAlreadyDelivered(bool $replaceBeforeDrain): void
    {
        $previousRt = Hilos::$rt;
        try {
            [$client, $socket] = $this->connect();
            $page = PerInstancePublicIdentityPage::PAGE;
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = $this->handoffRuntime($client->acceptKey);
            $this->subscribe($client, $page);
            $this->applyHandoffIdentity($this->master, $client->acceptKey);
            $this->master->drain();
            $handoff = array_pop($this->worker->requests)->signal;
            $pending = $this->pending()[$client->acceptKey][$handoff->data->deliveryId];

            // The socket learns that it can already re-subscribe while the terminal answer
            // produced on another host is still in flight.
            $this->master->onAgentStarted($pending->agentId);
            $this->master->drain();
            self::assertTrue($client->isDeliveryPending($page, $pending->deliveryId));
            self::assertFalse($pending->awaitsRecovery);

            $refusal = $this->addressedPageReply($handoff->data, true, 'unavailable');
            $refusal = new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                $refusal->signalType,
                $refusal->signalName,
                $refusal->data,
                [...$refusal->meta, ...PageHandoffIdentity::fromMeta($handoff->meta)->toMeta(),
                    PageHandoffIdentity::META_TERMINAL_REFUSAL => $pending->deliveryId],
            );
            $this->deliver($refusal, $client, peer: true);
            $this->master->drain();
            $this->deliver($refusal, $client, peer: true);
            $this->master->drain();
            self::assertFalse($client->isDeliveryPending($page, $pending->deliveryId));
            if ($replaceBeforeDrain) {
                $fresh = $this->subscribe($client, $page);
                $this->deliver($this->addressedPageReply($fresh->data, false, 'fresh'), $client);
                $client->completeDelivery($page, $fresh->data->deliveryId);
            }
            $client->now += 5000;
            $client->write();
            $frames = $this->read($socket);
            if ($replaceBeforeDrain) {
                self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'type'));
                self::assertSame('fresh', $frames[0]['data']['payload']['data']['marker']);
            } else {
                self::assertSame([['type' => SignalConstants::PAGE_RESUBSCRIBE, 'data' => ['page' => $page, 'params' => []]]], $frames);
            }
        } finally {
            Hilos::$rt = $previousRt;
        }
    }

    /** @return iterable<string, array{bool}> Replacement site in the real whole answer */
    public static function wholeReplacementSites(): iterable
    {
        yield 'success invalidated in before-response' => [false];
        yield 'projection throws after replacement' => [true];
    }

    #[DataProvider('wholeReplacementSites')]
    public function testRealWholeResendKeepsResourceAndDebtOnItsOriginalRecord(bool $throws): void
    {
        $masterRouter = Hilos::$sr;
        $worker = new DeliveryFlowWorker($this->pages, new class extends SignalRouter {
            public function pageAgentIndexRoute(string $page): ?PageAgentIndexRoute
            {
                return $page === DeliveryFlowPage::PAGE
                    ? new PageAgentIndexRoute(PageAgentIndexSource::SESSION_USER_OR_GUEST, null, DeliveryFlowAgent::AGENT_TYPE)
                    : parent::pageAgentIndexRoute($page);
            }
        });
        $router = Hilos::$sr;
        $page = DeliveryFlowPage::PAGE;
        $target = 'target';
        $neighbor = 'neighbor';
        $params = ['key' => 'same'];
        $resource = PageResource::db('fixture', 'old');
        $newResource = PageResource::db('fixture', 'new');
        $fixturePage = $this->factory->page();
        $fixturePage->resource = $resource;
        $fixturePage->beforeResponse = static function (): void {};
        $this->browser->sendWholeOnFact = true;
        $this->browser->bindPageResender($worker);
        try {
            foreach ([$target, $neighbor] as $key) {
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page, $params, deliveryId: $key . ':1'));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
            }
            $fixturePage->projection = static function (string $acceptKey) use ($target): void {
                if ($acceptKey === $target) {
                    throw new PageInternalErrorException('first projection failed');
                }
            };
            $this->flushWholeFanOut();
            $this->assertWholeFanOutFrames([$target => SignalConstants::SUBSCRIPTION_PAGE_ERROR, $neighbor => SignalTypeConstants::PAGE_RESPONSE]);
            self::assertTrue($router->hasPageDeliveryFailure($target));
            self::assertSame($resource, $router->pageResource($neighbor));

            if ($throws) {
                $router->clearPageDeliveryFailure($target);
            }
            $replace = static function (string $acceptKey) use ($router, $target, $page, $params, $newResource, $throws): void {
                if ($acceptKey !== $target) {
                    return;
                }
                self::assertSame(DeliveryFlowAgent::AGENT_TYPE, ExecutionContext::currentAgentId());
                $router->unsubscribeFromPage($page, new WebSocketPageUnsubscribeSignalDTO($acceptKey));
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params, deliveryId: 'target:2'));
                $router->bindPageAgent($acceptKey, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                $router->bindPageResource($acceptKey, $newResource);
                if ($throws) {
                    throw new PageInternalErrorException('late projection failed');
                }
                $router->markPageDeliveryFailure($acceptKey);
            };
            $fixturePage->projection = $throws ? $replace : null;
            $fixturePage->beforeResponse = $throws ? static function (): void {} : $replace;

            $this->flushWholeFanOut();
            $this->assertWholeFanOutFrames([$neighbor => SignalTypeConstants::PAGE_RESPONSE]);
            self::assertSame('target:2', $router->pageSubscription($target)?->deliveryId);
            self::assertSame($newResource, $router->pageResource($target));
            self::assertSame(!$throws, $router->hasPageDeliveryFailure($target));

            $router->markPageDeliveryFailure($target);
            $fixturePage->beforeResponse = static function (): void {};
            $fixturePage->projection = null;
            $fixturePage->resource = $newResource;
            $this->browser->recordDatabaseBack();
            $this->browser->flushToSignalRouter();
            $this->assertWholeFanOutFrames([$target => SignalTypeConstants::PAGE_RESPONSE], 'target:2');
            self::assertFalse($router->hasPageDeliveryFailure($target));
            self::assertSame($newResource, $router->pageResource($target));
        } finally {
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return iterable<string, array{string}> Project callback during whole assembly */
    public static function wholeReplacementCallbacks(): iterable
    {
        yield 'first query' => ['query'];
        yield 'second table' => ['second table'];
        yield 'progress of the only table' => ['progress'];
    }

    #[DataProvider('wholeReplacementCallbacks')]
    public function testRealWholeResendCommitsAReusedWindowOnlyForItsOriginalSubscription(string $callback): void
    {
        $masterRouter = Hilos::$sr;
        $worker = new DeliveryFlowWorker($this->pages);
        $router = Hilos::$sr;
        $page = DeliveryFlowPage::PAGE;
        $params = ['key' => 'same'];
        $tableKey = 'hilosLogKeys';
        $secondKey = 'secondWindow';
        $second = $callback === 'second table' ? new DeliveryRestoredWindowTable('target') : null;
        $browser = new DeliveryRestoredWindowBrowser($second !== null);
        Hilos::$browser = $browser;
        $this->factory->page()->beforeResponse = static function (): void {};
        $table = new DeliveryRestoredWindowTable('target');
        Hilos::$table = new DeliveryRestoredWindowTables($table, $second);
        Hilos::$table->configure();
        try {
            foreach (['target', 'neighbor'] as $key) {
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page, $params));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                foreach ($second === null ? [$tableKey] : [$tableKey, $secondKey] as $windowKey) {
                    $router->setTableViewport($key, new TableViewportSubscription(
                        $windowKey, sort: TableSortOrderDTO::fromWire([['field' => 'id', 'direction' => 'asc']]), limit: 2,
                    ));
                }
                self::assertSame(PageResendOutcome::Answered, $worker->resendPage($page, $key, $params));
            }
            $this->takeSignals();
            $target = $router->getTableViewport('target', $tableKey);
            $neighbor = $router->getTableViewport('neighbor', $tableKey);
            $first = $target->firstAnchor();
            $last = $target->lastAnchor();
            $anchors = $target->rowAnchors();
            self::assertNotNull($first);
            self::assertNotNull($last);
            self::assertSame(['a', 'b'], $target->rowIds());
            self::assertSame(5, $target->totalCount());

            $originalSecond = $router->getTableViewport('target', $secondKey);
            $router->oweTableWindow('target', $tableKey);
            if ($second !== null) {
                $router->oweTableWindow('target', $secondKey);
            }
            $replace = static function () use ($router, $page, $params): void {
                self::assertSame(DeliveryFlowAgent::AGENT_TYPE, ExecutionContext::currentAgentId());
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('target', $page, $params));
                $router->bindPageAgent('target', $page, DeliveryFlowAgent::AGENT_TYPE, null);
            };
            $staleRows = [['id' => 'c', 'class' => 'worker', 'label' => 'stale whole']];
            $table->duringQuery = static function () use ($callback, $replace, $staleRows): array {
                if ($callback === 'query') {
                    $replace();
                }
                return $staleRows;
            };
            if ($second !== null) {
                $second->duringQuery = static function () use ($replace, $staleRows): array {
                    $replace();
                    return $staleRows;
                };
            } elseif ($callback === 'progress') {
                $table->duringProgress = static function () use ($replace): array {
                    $replace();
                    return [];
                };
            }
            $original = $router->pageSubscription('target');
            self::assertNotSame(PageResendOutcome::Answered, $worker->resendPage($page, 'target', $params));
            self::assertNotSame($original, $router->pageSubscription('target'));
            self::assertSame($target, $router->getTableViewport('target', $tableKey));
            self::assertSame(['a', 'b'], $target->rowIds());
            self::assertSame(5, $target->totalCount());
            self::assertSame($first, $target->firstAnchor());
            self::assertSame($last, $target->lastAnchor());
            self::assertSame($anchors, $target->rowAnchors());
            self::assertTrue($router->isTableWindowOwed('target', $tableKey));
            if ($originalSecond !== null) {
                self::assertSame($originalSecond, $router->getTableViewport('target', $secondKey));
                self::assertSame(['a', 'b'], $originalSecond->rowIds());
                self::assertTrue($router->isTableWindowOwed('target', $secondKey));
            }
            self::assertSame([], $this->takeSignals());
            $router->clearTableWindowDebt('target', $tableKey);
            self::assertSame($neighbor, $router->getTableViewport('neighbor', $tableKey));
            self::assertSame(['a', 'b'], $neighbor->rowIds());

            $updated = new TableRowMutationDTO(
                TableMutationType::Update, 'a', $table->makeRow(['id' => 'a', 'class' => 'daemon', 'label' => 'live delta']),
            );
            foreach (['target', 'neighbor'] as $key) {
                $browser->publishTableMutations($page, $key, $tableKey, static fn (): array => [$updated]);
            }
            $browser->flushToSignalRouter();
            $deltas = [];
            foreach ($this->takeSignals() as $signal) {
                if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                    $deltas[] = $signal->data->targetAcceptKey;
                }
            }
            self::assertSame(['target', 'neighbor'], $deltas);

            $router->oweTableWindow('target', $tableKey);
            $table->duringQuery = null;
            $table->duringProgress = static fn (): array => [new TableProgressDTO(TableProgressScope::Table, 'running', null, 1, 3)];
            if ($second !== null) {
                $second->duringQuery = null;
            }
            self::assertSame(PageResendOutcome::Answered, $worker->resendPage($page, 'target', $params));
            self::assertSame(['a', 'b'], $target->rowIds());
            self::assertSame(5, $target->totalCount());
            self::assertSame($first->toArray(), $target->firstAnchor()?->toArray());
            self::assertSame($last->toArray(), $target->lastAnchor()?->toArray());
            self::assertFalse($router->isTableWindowOwed('target', $tableKey));
            self::assertFalse($router->isTableWindowOwed('target', $secondKey));
            $answers = array_values(array_filter($this->takeSignals(),
                static fn (SignalDTO $signal): bool => $signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
            self::assertCount(1, $answers);
            self::assertSame('running', $answers[0]->data->data->payload->windows[$tableKey]['progress'][0]['progressKey']);
        } finally {
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return iterable<string, array{string, bool}> Change after the real worker answer, or within its guard */
    public static function ordinaryFanOutContinuations(): iterable
    {
        yield 'whole and same agent bind' => ['bind', true];
        yield 'raw delete refusal and same agent bind' => ['raw', true];
        yield 'owed delete refusal and same agent bind' => ['owed refusal', true];
        yield 'new subscribe with equal params' => ['subscribe', false];
        yield 'merge with equal params' => ['merge', false];
        yield 'new serving address' => ['owner', false];
        yield 'round trip to the same serving address' => ['owner back', false];
        yield 'new session of the same user' => ['session', false];
        yield 'new opt-in record holding the same token and proof' => ['opt-in', false];
        yield 'different router' => ['router', false];
        yield 'same agent bind inside guard' => ['guard', false];
        yield 'same agent bind after a companion but inside assembly' => ['assembly', false];
    }

    #[DataProvider('ordinaryFanOutContinuations')]
    public function testWorkerDrainKeepsAcceptedOutcomeOnOrdinaryRegistration(string $change, bool $delivered): void
    {
        $masterRouter = Hilos::$sr;
        $previousRt = Hilos::$rt;
        $router = new class($change === 'opt-in') extends SignalRouter {
            public int $answers = 0;

            /** @param bool $optIn Whether the fixture page routes to its session owner */
            public function __construct(private readonly bool $optIn)
            {
                parent::__construct();
            }

            /**
             * @param string $page Requested page
             * @return ?PageAgentIndexRoute Session-owner route for the opted-in fixture
             */
            public function pageAgentIndexRoute(string $page): ?PageAgentIndexRoute
            {
                return $this->optIn && $page === DeliveryFlowPage::PAGE
                    ? new PageAgentIndexRoute(PageAgentIndexSource::SESSION_USER_OR_GUEST, null, DeliveryFlowAgent::AGENT_TYPE)
                    : parent::pageAgentIndexRoute($page);
            }

            /**
             * @param WebSocketPageSubscribeSignalDTO|PageSubscriptionContext $authority Original answer authority
             * @param Closure $answer Dispatch to the serving page
             * @param ?PageHandoffIdentity $identity Certified identity when routed by owner
             * @return mixed Outcome from that dispatch
             */
            public function withPageAnswerAuthority(
                WebSocketPageSubscribeSignalDTO|PageSubscriptionContext $authority,
                Closure $answer,
                ?PageHandoffIdentity $identity = null,
            ): mixed {
                ++$this->answers;

                return parent::withPageAnswerAuthority(authority: $authority, answer: $answer, identity: $identity);
            }
        };
        $worker = new DeliveryFlowWorker($this->pages, $router);
        [$daemon, $reader] = $this->attachWorkerIpc($worker);
        $page = DeliveryFlowPage::PAGE;
        $key = 'target';
        $params = ['key' => 'same'];
        $raw = $change === 'raw';
        $refusal = $raw || $change === 'owed refusal';

        try {
            if ($change === 'session' || $change === 'opt-in') {
                Hilos::$rt = $this->sessionHandoffRuntime($key, 101);
            }
            $subscribe = static function (SignalRouter $router) use ($key, $page, $params, $change): void {
                $deliveryId = $change === 'opt-in' ? $key . ':1' : null;
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page, $params, deliveryId: $deliveryId));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                if ($deliveryId !== null) {
                    $router->pageSubscription($key)->admittedIdentity = PageHandoffIdentity::current($key);
                }
            };
            $subscribe($router);
            if ($change === 'opt-in') {
                self::assertSame($key . ':1', $router->pageSubscription($key)?->deliveryId);
                self::assertSame(101, $router->pageSubscription($key)?->admittedIdentity?->sessionId);
            }
            if (!$raw) {
                $router->markPageDeliveryFailure($key);
            }
            $resource = PageResource::db('fixture', 'observed');
            if ($refusal) {
                $router->bindPageResource($key, $resource);
            } elseif ($change === 'bind') {
                $this->factory->page()->resource = $resource;
            }
            if ($change === 'owed refusal') {
                $this->browser->duringAccess = static function (): void { throw new PageResourceNotFoundException(); };
            } elseif ($change === 'guard' || $change === 'assembly') {
                $bind = static function () use ($router, $key, $page): void {
                    $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                };
                if ($change === 'guard') {
                    $this->browser->duringAccess = $bind;
                } else {
                    $this->factory->page()->projection = $bind;
                }
            }

            $resender = $this->createMock(PageResender::class);
            if ($raw) {
                $resender->expects(self::once())->method('refuseDeletedResource')->willReturnCallback(
                    static function (string $page, string $key) use ($worker, $router): PageResendOutcome {
                        $outcome = $worker->refuseDeletedResource($page, $key);
                        self::assertSame(PageResendOutcome::Refused, $outcome);
                        $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);

                        return $outcome;
                    },
                );
            } else {
                $resender->expects(self::once())->method('resendPage')->willReturnCallback(
                    function (string $page, string $key, array $params, bool $reportRefusal) use (
                        $worker, $router, $change, $refusal, $subscribe,
                    ): PageResendOutcome {
                        $outcome = $worker->resendPage($page, $key, $params, $reportRefusal);
                        if ($change === 'guard' || $change === 'assembly') {
                            self::assertSame(PageResendOutcome::Unserved, $outcome);

                            return $outcome;
                        }
                        self::assertSame($refusal ? PageResendOutcome::Refused : PageResendOutcome::Answered, $outcome);
                        match ($change) {
                            'subscribe' => $subscribe($router),
                            'merge' => $router->updatePageSubscription($page, new WebSocketPageUpdateSubscriptionSignalDTO($key, $page, $params)),
                            'owner', 'owner back' => $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, 'new-owner'),
                            'router' => $subscribe(Hilos::$sr = new SignalRouter()),
                            default => null,
                        };
                        if ($change === 'session') {
                            Hilos::$rt = $this->sessionHandoffRuntime($key, 102);
                        }
                        if (in_array($change, ['bind', 'owed refusal', 'owner back', 'session', 'opt-in'], true)) {
                            $proof = $router->pageSubscription($key)->admittedIdentity;
                            $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                            $router->pageSubscription($key)->admittedIdentity = $proof;
                        }
                        if (!$refusal && $change !== 'bind') {
                            Hilos::$sr->markPageDeliveryFailure($key);
                        }

                        return $outcome;
                    },
                );
            }
            $this->browser->bindPageResender($resender);
            if ($refusal) {
                $this->browser->record(SourceChange::dbDeleted('fixture', 'observed'));
            } else {
                $this->browser->publishPageData($page, $key, static fn () => new PagePayload(data: ['portion' => 'first']), []);
                if ($change === 'bind' || $change === 'guard') {
                    $this->browser->publishPageData($page, $key, static fn () => new PagePayload(data: ['portion' => 'second']), []);
                }
            }
            self::assertSame([], $this->browser->flushToSignalRouter());
            if ($refusal) {
                self::assertNull($router->pageResource($key));
            } elseif ($change === 'bind') {
                self::assertSame($resource, $router->pageResource($key));
            }
            self::assertSame(1, $router->answers, 'The real worker dispatch uses the compatible router override.');
            $replies = [];
            $names = [];
            foreach ($this->drainWorkerSignals($worker, $daemon, $reader) as $signal) {
                if (!$signal->data instanceof WebSocketSignalData || $signal->data->targetAcceptKey !== $key) {
                    continue;
                }
                $reply = $signal->data->data;
                $names[] = $signal->signalName->getName();
                if ($reply instanceof PageResponseSignalData) {
                    self::assertSame([], $reply->payload->data);
                    $replies[] = $signal->signalName->getName();
                } elseif ($reply instanceof PageSubscriptionErrorSignalData) {
                    self::assertSame(HttpConstants::HTTP_NOT_FOUND, $reply->httpCode);
                    $replies[] = $signal->signalName->getName();
                }
            }
            self::assertSame($delivered ? [$refusal ? SignalConstants::SUBSCRIPTION_PAGE_ERROR : SignalTypeConstants::PAGE_RESPONSE] : [], $replies);
            if ($change === 'bind') {
                self::assertSame(['own_state', SignalTypeConstants::PAGE_RESPONSE, SignalTypeConstants::TABLE_FACET_COUNTS, 'after_response'],
                    $names);
            } elseif (!$delivered) {
                self::assertSame([], $names);
            }
            self::assertSame($refusal || !$delivered, Hilos::$sr->hasPageDeliveryFailure($key));
        } finally {
            $daemon->close();
            socket_close($reader);
            Hilos::$rt = $previousRt;
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return iterable<string, array{bool, bool}> Held window lifetime and kind of accepted table work */
    public static function renderedWindowContinuations(): iterable
    {
        yield 'delta and recount on the held window' => [false, false];
        yield 'delta and recount for a replaced window' => [true, false];
        yield 'catch-up on the held window' => [false, true];
        yield 'catch-up for a replaced window' => [true, true];
    }

    #[DataProvider('renderedWindowContinuations')]
    public function testWorkerDrainDeliversAcceptedTableWorkAcrossTableRendered(bool $replace, bool $owed): void
    {
        $masterRouter = Hilos::$sr;
        $worker = new DeliveryFlowWorker($this->pages);
        $router = Hilos::$sr;
        [$daemon, $reader] = $this->attachWorkerIpc($worker);
        $page = DeliveryFlowPage::PAGE;
        $tableKey = 'hilosLogKeys';
        $browser = new DeliveryRestoredWindowBrowser();
        Hilos::$browser = $browser;
        $table = new DeliveryRestoredWindowTable('target');
        Hilos::$table = new DeliveryRestoredWindowTables($table);
        Hilos::$table->configure();
        $field = 'label';
        $makeWindow = static fn (array $rendered = []) => new TableViewportSubscription($tableKey, rendered: $rendered);

        try {
            foreach (['target', 'neighbor'] as $key) {
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
                $router->setTableViewport($key, $makeWindow());
                self::assertSame(PageResendOutcome::Answered, $worker->resendPage($page, $key, []));
                $router->setTableFacets($key, $tableKey, ['class' => ['daemon', 'agent']]);
                if ($owed) {
                    $router->oweTableWindow($key, $tableKey);
                }
            }
            $this->drainWorkerSignals($worker, $daemon, $reader);
            $original = $router->getTableViewport('target', $tableKey);
            $row = ['id' => 'a', 'class' => 'daemon', 'label' => 'changed'];
            $changed = static fn () => [$row, ['id' => 'b', 'class' => 'agent', 'label' => 'match']];
            $table->duringQuery = $changed;
            $publish = static function (array $row) use ($browser, $page, $tableKey, $table): void {
                $mutation = new TableRowMutationDTO(TableMutationType::Update, 'a', $table->makeRow($row));
                foreach (['target', 'neighbor'] as $key) {
                    $browser->publishTableMutations($page, $key, $tableKey, static fn (): array => [$mutation]);
                }
            };
            $publish($row);
            $this->pages->dispatchTableRendered(new WebSocketTableRenderedSignalDTO('target', $page, $tableKey, [$field]),
                SignalSource::WEBSOCKET, $page);
            $window = $router->getTableViewport('target', $tableKey);
            self::assertNotSame($original, $window);
            self::assertSame([$field], $window->rendered);
            if ($replace) {
                $window = $makeWindow([$field]);
                $router->setTableViewport('target', $window);
            }

            self::assertSame([], $browser->flushToSignalRouter());
            $signals = $this->drainWorkerSignals($worker, $daemon, $reader);
            $targets = $replace ? ['neighbor'] : ['target', 'neighbor'];
            $rowsSignal = $owed ? SignalTypeConstants::TABLE_WINDOW : SignalTypeConstants::TABLE_VIEWPORT_DELTA;
            self::assertSame($targets, $this->workerSignalTargets($signals, $rowsSignal));
            self::assertSame($targets, $this->workerSignalTargets($signals, SignalTypeConstants::TABLE_FACET_COUNTS));
            self::assertSame($owed ? [] : $targets, $this->workerSignalTargets($signals, SignalTypeConstants::TABLE_VIEWPORT_COUNT));
            self::assertSame($replace ? 0 : 2, $window->totalCount());
            self::assertSame(5, $original->totalCount());
            self::assertSame($owed && $replace, $router->isTableWindowOwed('target', $tableKey));
            foreach ($signals as $signal) {
                if ($signal->data->data instanceof TableFacetCountsSignalData) {
                    self::assertSame(2, $signal->data->data->facets->filters['class'][TableConstants::FACET_KEY_ANY]->count);
                }
            }

            if ($replace) {
                // Serve the new window with its own snapshot before sending it a later change.
                self::assertSame(PageResendOutcome::Answered, $worker->resendPage($page, 'target', []));
                $this->drainWorkerSignals($worker, $daemon, $reader);
            }
            $publish($row);
            self::assertSame([], $browser->flushToSignalRouter());
            self::assertSame([], $this->workerSignalTargets(
                $this->drainWorkerSignals($worker, $daemon, $reader), SignalTypeConstants::TABLE_VIEWPORT_DELTA,
            ));
            $row['label'] = 'fresh';
            $table->duringQuery = static fn () => [$row, $changed()[1]];
            $publish($row);
            self::assertSame([], $browser->flushToSignalRouter());
            self::assertSame(['target', 'neighbor'], $this->workerSignalTargets(
                $this->drainWorkerSignals($worker, $daemon, $reader), SignalTypeConstants::TABLE_VIEWPORT_DELTA,
            ));
        } finally {
            $daemon->close();
            socket_close($reader);
            Hilos::$sr = $masterRouter;
        }
    }

    public function testWorkerDrainDropsAnEarlierFrameAfterTheRealGuardReplacesItsAddress(): void
    {
        $masterRouter = Hilos::$sr;
        $worker = new DeliveryFlowWorker($this->pages);
        $router = Hilos::$sr;
        $page = DeliveryFlowPage::PAGE;
        $this->browser->sendWholeOnFact = true;
        $this->browser->bindPageResender($worker);
        [$daemon, $reader] = $this->attachWorkerIpc($worker);

        try {
            foreach (['target', 'neighbor'] as $key) {
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
            }
            foreach ([['neighbor', 'first'], ['target', 'old'], ['neighbor', 'last']] as [$key, $portion]) {
                $this->browser->publishPageData($page, $key, static fn () => new PagePayload(data: ['portion' => $portion]), []);
            }
            $this->browser->duringAccess = static function (string $key) use ($router, $page): void {
                self::assertSame('target', $key);
                $router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($key, $page));
                $router->bindPageAgent($key, $page, DeliveryFlowAgent::AGENT_TYPE, null);
            };

            $this->flushWholeFanOut();
            self::assertSame([['neighbor', 'first'], ['neighbor', 'last'], ['neighbor', null]],
                $this->drainWorkerPageReplies($worker, $daemon, $reader));

            $this->flushWholeFanOut();
            self::assertSame([['target', null], ['neighbor', null]], $this->drainWorkerPageReplies($worker, $daemon, $reader));
        } finally {
            $daemon->close();
            socket_close($reader);
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return list<array{string, ?string}> Page replies in their actual worker IPC order */
    private function drainWorkerPageReplies(DeliveryFlowWorker $worker, WorkerDaemonClient $daemon, Socket $reader): array
    {
        $replies = [];
        foreach ($this->drainWorkerSignals($worker, $daemon, $reader) as $signal) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof PageResponseSignalData) {
                $replies[] = [$signal->data->targetAcceptKey, $signal->data->data->payload->data['portion'] ?? null];
            }
        }

        return $replies;
    }

    /** @return array{WorkerDaemonClient, Socket} Worker IPC writer and its peer */
    private function attachWorkerIpc(DeliveryFlowWorker $worker): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        self::assertTrue(socket_set_nonblock($pair[0]));
        self::assertTrue(socket_set_nonblock($pair[1]));
        $daemon = new WorkerDaemonClient();
        new ReflectionProperty(AbstractSocket::class, 'socket')->setValue($daemon, $pair[0]);
        new ReflectionProperty(WorkerDaemonClient::class, 'state')->setValue($daemon, DaemonConnectionState::CONNECTED);
        new ReflectionProperty(WorkerManager::class, 'daemonClient')->setValue($worker, $daemon);

        return [$daemon, $pair[1]];
    }

    /** @return list<SignalDTO> Addressed browser frames read back from the worker's actual IPC */
    private function drainWorkerSignals(DeliveryFlowWorker $worker, WorkerDaemonClient $daemon, Socket $reader): array
    {
        new ReflectionMethod(WorkerManager::class, 'dispatchQueuedSignalsToDaemon')->invoke($worker);
        $daemon->write();
        self::assertFalse($daemon->hasPendingWrite());
        $bytes = trim($this->readBytes($reader));
        if ($bytes === '') {
            return [];
        }
        $signals = [];
        foreach (explode("\n", $bytes) as $line) {
            $frame = WorkerDTO::factoryWorkerDTO($line);
            if ($frame instanceof WorkerAgentMessageDTO && $frame->signal->data instanceof WebSocketSignalData) {
                $signals[] = $frame->signal;
            }
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Addressed frames in IPC order
     * @return list<string> Connections that received the named frame in that order
     */
    private function workerSignalTargets(array $signals, string $name): array
    {
        $targets = [];
        foreach ($signals as $signal) {
            if ($signal->signalName->getName() === $name) {
                $targets[] = $signal->data->targetAcceptKey;
            }
        }

        return $targets;
    }

    private function flushWholeFanOut(): void
    {
        $this->browser->record(SourceChange::dbUpdated('fixture', 'observed', ['name' => 'updated']));
        $this->browser->flushToSignalRouter();
    }

    /** @param array<string, string> $expected Addressed whole delivery outcomes */
    private function assertWholeFanOutFrames(array $expected, string $targetId = 'target:1'): void
    {
        $actual = [];
        foreach ($this->takeSignals() as $signal) {
            $type = $signal->signalName->getName();
            if ($type === SignalTypeConstants::PAGE_RESPONSE || $type === SignalConstants::SUBSCRIPTION_PAGE_ERROR) {
                $key = $signal->data->targetAcceptKey;
                self::assertArrayNotHasKey($key, $actual);
                self::assertSame($key === 'target' ? $targetId : $key . ':1', $signal->meta[WebSocketPageSubscribeSignalDTO::DELIVERY_ID]);
                $actual[$key] = $type;
            }
        }
        self::assertSame($expected, $actual);
    }

    /** @return iterable<string, array{bool, bool}> Old answer shape and its transport boundary */
    public static function acceptedPageReplyLifetimes(): iterable
    {
        yield 'whole in active packing' => [false, true];
        yield 'refusal in active packing' => [true, true];
        yield 'whole behind cadence' => [false, false];
        yield 'refusal behind cadence' => [true, false];
    }

    #[DataProvider('acceptedPageReplyLifetimes')]
    public function testAcceptedPageReplyStillNeedsItsOriginalSubscriptionAtTheSocket(bool $refusal, bool $packing): void
    {
        [$client, $socket] = $this->connect();
        $initial = $this->subscribe($client, params: ['key' => 'same']);
        $this->deliver(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData('delivery', $initial->data->deliveryId), targetAcceptKey: $client->acceptKey),
        ), $client);
        $client->now++;
        $client->sendFrame('{"type":"first","data":{}}');
        $client->write();
        self::assertSame(['first'], array_column($this->read($socket), 'type'));
        $client->now += $packing ? 1000 : 999;

        $this->deliver($this->addressedPageReply($initial->data, $refusal, 'old'), $client);
        $queue = new ReflectionProperty(WebSocketClient::class, 'deliveryQueue')->getValue($client);
        self::assertTrue($queue->hasFrames(), 'The old reply is already accepted by the socket queue.');
        $neighbors = $packing ? 65 : 1;
        for ($index = 0; $index < $neighbors; $index++) {
            $client->sendFrame(json_encode(['type' => 'neighbor', 'data' => [$index]], JSON_THROW_ON_ERROR));
        }
        if ($packing) {
            $client->write();
            self::assertTrue($queue->hasPendingPacking(), 'The old reply has entered the open packed run.');
        } else {
            self::assertGreaterThan($client->now, $queue->nextWorkAt($client->now));
        }
        self::assertSame([], $this->read($socket));

        $client->acceptJson('{"type":"page_unsubscribe","page":"delivery"}');
        $this->master->drain();
        $replacement = $this->subscribe($client, params: ['key' => 'same']);
        self::assertNotSame($initial->data->deliveryId, $replacement->data->deliveryId);
        $this->deliver($this->addressedPageReply($replacement->data, false, 'fresh'), $client);
        $client->completeDelivery('delivery', $replacement->data->deliveryId);
        for ($pass = 0; $pass < 4; $pass++) {
            $client->write();
        }
        $logical = [];
        foreach ($this->read($socket) as $frame) {
            array_push($logical, ...($frame['type'] === SignalTypeConstants::SIGNAL_BATCH ? $frame['data']['frames'] : [$frame]));
        }
        self::assertSame([...array_fill(0, $neighbors, 'neighbor'), SignalTypeConstants::PAGE_RESPONSE], array_column($logical, 'type'));
        self::assertSame('fresh', $logical[$neighbors]['data']['payload']['data']['marker']);
    }

    /** @return iterable<string, array{bool, bool, bool}> Response kind, partial predecessor, and partially written answer */
    public static function packedPageReplyLifetimes(): iterable
    {
        yield 'whole with WRITE held' => [false, false, false];
        yield 'refusal behind a partial predecessor' => [true, true, false];
        yield 'a started page frame must finish' => [false, false, true];
    }

    #[DataProvider('packedPageReplyLifetimes')]
    public function testPackedPageReplyRechecksBeforeTransportWrite(bool $refusal, bool $partial, bool $started): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client, params: ['key' => 'same']);
        $client->completeDelivery('delivery', $request->data->deliveryId);
        $bytes = '';
        $largeText = str_repeat('x', 128 * 1024);
        $clientSocket = $client->getSocket();
        if ($partial || $started) {
            self::assertTrue(socket_set_option($clientSocket, SOL_SOCKET, SO_SNDBUF, 4096));
        }
        if ($partial) {
            $client->sendFrame(json_encode([
                'type' => SignalTypeConstants::SIGNAL_BATCH,
                'data' => ['frames' => [['type' => 'predecessor', 'data' => $largeText]]],
            ], JSON_THROW_ON_ERROR));
        }

        $client->sendFrame('{"type":"before","data":1}');
        $this->deliver($this->addressedPageReply($request->data, $refusal, $started ? $largeText : 'stale'), $client);
        $client->sendFrame('{"type":"after","data":2}');
        (new ReflectionMethod(WebSocketClient::class, 'drainDelivery'))->invoke($client);
        $queue = (new ReflectionProperty(WebSocketClient::class, 'deliveryQueue'))->getValue($client);
        self::assertFalse($queue->hasFrames(), 'The old page frame and its neighbors left the delivery queue.');
        if ($partial || $started) {
            $client->write();
            $bytes = $this->readBytes($socket);
            self::assertGreaterThan(0, strlen($bytes));
            self::assertLessThan(strlen($largeText), strlen($bytes));
        } else {
            self::assertSame([], $this->read($socket));
        }

        $fresh = $this->subscribe($client, params: ['key' => 'same']);
        $this->deliver($this->addressedPageReply($fresh->data, false, 'fresh'), $client);
        $client->completeDelivery('delivery', $fresh->data->deliveryId);
        $client->now += 5000;
        for ($pass = 0; $client->hasPendingWrite() && $pass < 64; $pass++) {
            $client->write();
            $bytes .= $this->readBytes($socket);
        }

        $logical = [];
        foreach ($this->decodeFrames($bytes) as $frame) {
            array_push($logical, ...($frame['type'] === SignalTypeConstants::SIGNAL_BATCH ? $frame['data']['frames'] : [$frame]));
        }
        self::assertSame([
            ...($partial ? ['predecessor'] : []),
            'before',
            ...($started ? [SignalTypeConstants::PAGE_RESPONSE] : []),
            'after',
            SignalTypeConstants::PAGE_RESPONSE,
        ], array_column($logical, 'type'));
        self::assertSame('fresh', $logical[array_key_last($logical)]['data']['payload']['data']['marker']);
        if ($started) {
            self::assertSame($largeText, $logical[1]['data']['payload']['data']['marker']);
        }
    }

    /** @return iterable<string, array{bool, bool}> Response kind and whether expiry reaches the socket after RT apply */
    public static function packedIdentityResponses(): iterable
    {
        yield 'whole' => [false, false];
        yield 'packed terminal refusal' => [true, false];
        yield 'late terminal report' => [true, true];
    }

    #[DataProvider('packedIdentityResponses')]
    public function testPackedPageBytesRequireTheCurrentGuestSessionBeforeReassess(bool $refusal, bool $late): void
    {
        $previousRt = Hilos::$rt;
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $masterRouter = Hilos::$sr;
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = $this->sessionHandoffRuntime($acceptKey, 101);
            $request = $this->subscribe($client, $page);
            $client->sendFrame('{"type":"before","data":1}');
            if ($refusal) {
                $guest = new DeliveryFlowGuestAgent();
                $worker = new PerInstanceIdentityTestWorker(3, $guest);
                Hilos::$sr = $masterRouter;
                $worker->deliver(new DaemonAgentMessageDTO($guest->getId(), $request));
                self::assertSame(PageHandoffIdentity::PROBE, $worker->router->getNextQueuedSignal()?->meta[PageHandoffIdentity::META_PROOF]);
                $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
                $report = $worker->router->getNextQueuedSignal();
                self::assertInstanceOf(SignalDTO::class, $report);
                if (!$late) {
                    $this->queueServingWorkerReply($this->master, $report);
                }
            } else {
                $this->deliver($this->addressedPageReply($request->data, false, 'old'), $client);
                $client->completeDelivery($page, $request->data->deliveryId);
            }
            $client->sendFrame('{"type":"after","data":2}');
            (new ReflectionMethod(WebSocketClient::class, 'drainDelivery'))->invoke($client);
            self::assertTrue($client->hasPendingWrite());
            self::assertSame([], $this->read($socket));

            $this->applyHandoffFact($this->master, SignalTypeConstants::RT_SYNC_UPDATED, new RtSyncUpdatedSignalData(
                DeliveryFlowSessionRuntime::connections, $acceptKey, [StateHilosSessionConnection::sessionId => 102],
            ));
            self::assertSame(102, PageHandoffIdentity::current($acceptKey)?->sessionId);
            self::assertTrue($client->isCurrentPageSubscription($page, $request->data->deliveryId));
            if ($late) {
                $reply = new ReflectionMethod(DaemonManager::class, 'replyToPageHandoffProbe')
                    ->invoke($this->master, SignalDispatcherFixture::direct($report), $acceptKey, $report);
                foreach ($reply as $_) {
                }
                self::assertFalse($client->isDeliveryPending($page, $request->data->deliveryId));
                self::assertTrue($client->isCurrentPageSubscription($page, $request->data->deliveryId));
            }
            $client->write();
            [$neighbors] = $this->read($socket);
            self::assertSame(['before', 'after'], array_column($neighbors['data']['frames'], 'type'));

            $this->master->drain();
            $fresh = array_pop($this->worker->requests)?->signal;
            self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $fresh?->data);
            self::assertNotSame($request->data->deliveryId, $fresh->data->deliveryId);
            $this->deliver($this->addressedPageReply($fresh->data, false, 'fresh'), $client);
            $client->completeDelivery($page, $fresh->data->deliveryId);
            $client->now += 5_000;
            $client->write();
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->read($socket), 'type'));
        } finally {
            Hilos::$rt = $previousRt;
        }
    }

    /** @return iterable<string, array{bool}> Whether the retained native prefix includes the scoped frame */
    public static function tlsPagePrefixLifetimes(): iterable
    {
        yield 'TLS has started the scoped frame' => [false];
        yield 'TLS has only started the predecessor' => [true];
    }

    #[DataProvider('tlsPagePrefixLifetimes')]
    public function testNativeTlsRetainsItsStartedPrefixAndRechecksTheUnstartedFrame(bool $predecessor): void
    {
        $fixture = new MutualTlsFixture();
        $authority = $fixture->issueAuthority('Delivery test CA');
        $exchange = new TlsTestExchange(
            $fixture, $fixture->issueNode('gateway', $authority), $fixture->issueNode('browser', $authority), null,
            $fixture->trustFile($authority),
        );
        try {
            $transport = $exchange->transports['accepting'];
            $client = new DeliveryFlowClient($exchange->sockets['accepting'], $transport);
            $this->server->attach($client);
            $exchange->bindClient('accepting', $client);
            $exchange->handshake();

            $request = $this->subscribe($client, params: ['key' => 'same']);
            $client->completeDelivery('delivery', $request->data->deliveryId);
            $large = str_repeat('x', 128 * 1024);
            if ($predecessor) {
                $client->sendFrame(json_encode([
                    'type' => SignalTypeConstants::SIGNAL_BATCH,
                    'data' => ['frames' => [['type' => 'predecessor', 'data' => $large]]],
                ], JSON_THROW_ON_ERROR));
            }
            $this->deliver($this->addressedPageReply($request->data, false, $predecessor ? 'stale' : $large), $client);
            $client->write();
            self::assertGreaterThan(0, $transport->pendingWriteBytes());
            self::assertSame('', $exchange->received['dialing']);

            $fresh = $this->subscribe($client, params: ['key' => 'same']);
            $this->deliver($this->addressedPageReply($fresh->data, false, 'fresh'), $client);
            $client->completeDelivery('delivery', $fresh->data->deliveryId);
            $client->now += 5000;
            $exchange->runUntil(static fn (): bool => $exchange->failures !== []
                || (!$client->hasPendingWrite() && str_contains($exchange->received['dialing'], '"fresh"')));
            self::assertSame([], $exchange->failures);
            $frames = $this->decodeFrames($exchange->received['dialing']);
            self::assertSame([
                $predecessor ? SignalTypeConstants::SIGNAL_BATCH : SignalTypeConstants::PAGE_RESPONSE,
                SignalTypeConstants::PAGE_RESPONSE,
            ], array_column($frames, 'type'));
            self::assertSame($large, $predecessor
                ? $frames[0]['data']['frames'][0]['data'] : $frames[0]['data']['payload']['data']['marker']);
            self::assertSame('fresh', $frames[1]['data']['payload']['data']['marker']);
        } finally {
            $exchange->close();
            $fixture->close();
        }
    }

    private function addressedPageReply(WebSocketPageSubscribeSignalDTO $request, bool $refusal, string $marker): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName($refusal ? SignalConstants::SUBSCRIPTION_PAGE_ERROR : SignalTypeConstants::PAGE_RESPONSE),
            new WebSocketSignalData(
                $refusal ? PageSubscriptionErrorSignalData::forStatus($request->page, 500, $marker)
                    : new PageResponseSignalData($request->page, new PagePayload(data: ['marker' => $marker])),
                targetAcceptKey: $request->acceptKey,
            ),
            [WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $request->deliveryId, WebSocketPageSubscribeSignalDTO::PAGE => $request->page],
        );
    }

    /** @return iterable<string, array{bool}> Whether the serving worker still holds an ordinary page */
    public static function firstTrackableSubscriptions(): iterable
    {
        yield 'cold entry or reconnect' => [false];
        yield 'navigation from an ordinary page on the same worker' => [true];
    }

    #[DataProvider('firstTrackableSubscriptions')]
    public function testInitialSubscribeWaitsForTheMatchingGuestSessionOnAReadyWorker(bool $fromOrdinaryPage): void
    {
        $previousRt = Hilos::$rt;
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $masterRouter = Hilos::$sr;
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            $masterRt = $this->sessionHandoffRuntime($acceptKey, 102);
            $workerRt = $this->sessionHandoffRuntime($acceptKey, 101);
            Hilos::$rt = $masterRt;
            $guest = new DeliveryFlowGuestAgent();
            $worker = new PerInstanceIdentityTestWorker(3, $guest);
            Hilos::$sr = $masterRouter;
            if ($fromOrdinaryPage) {
                $ordinary = new DeliveryFlowAgent();
                $worker->addFixtureAgent($ordinary);
                $worker->pageRouterFor = fn (AgentInterface $agent): ?PageSignalRouter => $agent === $ordinary ? $this->pages : null;
                $oldRequest = $this->subscribe($client, DeliveryFlowPage::PAGE);
                $worker->deliver(new DaemonAgentMessageDTO($ordinary->getId(), $oldRequest));
                while (($signal = $worker->router->getNextQueuedSignal()) !== null) {
                    $this->queueServingWorkerReply($this->master, $signal);
                }
                $client->write();
                self::assertNotEmpty($this->read($socket));
                $masterRouter->bindPageAgent($acceptKey, DeliveryFlowPage::PAGE, $ordinary->getType(), $ordinary->getIndex());
                $agents = (new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon'))->getValue($this->master);
                $agents->stopped[$ordinary->getId()] = true;
                $this->worker->awaiting = true;
            }
            $oldMirror = $worker->router->pageSubscription($acceptKey);
            $request = $this->subscribe($client, $page);
            if ($fromOrdinaryPage) {
                self::assertSame(DeliveryFlowPage::PAGE, $oldMirror?->page);
                self::assertCount(1, $this->heldAgentFrames());
            }
            self::assertSame(SignalTypeConstants::PAGE_SUBSCRIBE, $request->signalType->getType());
            self::assertSame(102, PageHandoffIdentity::fromMeta($request->meta)?->sessionId);

            Hilos::$rt = $workerRt;
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections);
            self::assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections));
            $worker->deliver(new DaemonAgentMessageDTO($guest->getId(), $request));
            self::assertSame([], $guest->subscribedSessions);
            self::assertSame($oldMirror, $worker->router->pageSubscription($acceptKey));
            self::assertNull($worker->router->getNextQueuedSignal());

            $worker->applyConnectionUpdate(new RtSyncUpdatedSignalData(
                DeliveryFlowSessionRuntime::connections, $acceptKey, [StateHilosSessionConnection::sessionId => 102],
            ));
            $probe = $worker->router->getNextQueuedSignal();
            self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
            Hilos::$rt = $masterRt;
            $this->queueServingWorkerReply($this->master, $probe);
            $proof = array_pop($this->worker->requests);
            self::assertSame(PageHandoffIdentity::GRANTED, $proof?->signal->meta[PageHandoffIdentity::META_PROOF]);
            if ($fromOrdinaryPage) {
                unset($agents->stopped[$ordinary->getId()]);
                $this->worker->awaiting = false;
                $this->master->onAgentStarted($ordinary->getId());
                $this->master->drain();
                $release = array_pop($this->worker->requests);
                self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $release?->signal->signalType->getType());
                self::assertSame(DeliveryFlowPage::PAGE, $release->signal->signalName->getName());
                self::assertSame([], $release->signal->meta);

                Hilos::$rt = $workerRt;
                $worker->deliver($release);
                self::assertSame(1, $ordinary->pageReleases);
                self::assertNull($worker->router->pageSubscription($acceptKey));
                $worker->deliver($release);
                self::assertSame(1, $ordinary->pageReleases);
                self::assertSame([DeliveryFlowSessionRuntime::connections], SourceInterestRegistry::collectionsOfConsumer(
                    SourceConsumer::pageHandoff($request->data->deliveryId), SourceChange::KIND_RT,
                ));
            }
            Hilos::$rt = $workerRt;
            $worker->deliver($proof);
            $worker->deliver($proof);
            self::assertSame([102], $guest->subscribedSessions);
            self::assertSame(SignalTypeConstants::RT_STALENESS, $worker->router->getNextQueuedSignal()?->signalName->getName());
            $answer = $worker->router->getNextQueuedSignal();
            $complete = $worker->router->getNextQueuedSignal();
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer?->signalName->getName());
            self::assertSame(SignalConstants::DELIVERY_COMPLETE, $complete?->signalName->getName());
            self::assertNull($worker->router->getNextQueuedSignal());

            Hilos::$rt = $masterRt;
            $this->queueServingWorkerReply($this->master, $answer);
            $this->queueServingWorkerReply($this->master, $complete);
            $client->write();
            $frames = $this->read($socket);
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'type'));
            self::assertSame(102, $frames[0]['data']['payload']['data'][StateHilosSessionConnection::sessionId]);
        } finally {
            Hilos::$rt = $previousRt;
            Hilos::$sr = $masterRouter;
        }
    }

    private function sessionHandoffRuntime(string $acceptKey, ?int $sessionId): DeliveryFlowSessionRuntime
    {
        $runtime = new DeliveryFlowSessionRuntime();
        $runtime->configure();
        if ($sessionId !== null) {
            $runtime->connectionsSource()->add(SessionViewConnection::create($acceptKey, null, sessionId: $sessionId));
        }

        return $runtime;
    }

    /** @return iterable<string, array{string}> Ways a worker loses a pending handoff */
    public static function handoffWaitEndings(): iterable
    {
        yield 'late without a row' => ['timeout'];
        yield 'challenge with no reply' => ['challenge'];
        yield 'stream deletes the connection' => ['deleted'];
        yield 'exact leave' => ['leave'];
        yield 'connection closed' => ['close'];
    }

    #[DataProvider('handoffWaitEndings')]
    public function testHandoffWaitOrChallengeReleasesItsOwnReader(string $ending): void
    {
        $previousRt = Hilos::$rt;
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $masterRouter = Hilos::$sr;
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            $masterRt = $this->sessionHandoffRuntime($acceptKey, 102);
            Hilos::$rt = $masterRt;
            $guest = new DeliveryFlowGuestAgent();
            $worker = new PerInstanceIdentityTestWorker(3, $guest);
            Hilos::$sr = $masterRouter;
            $request = $this->subscribe($client, $page);
            $consumer = SourceConsumer::pageHandoff($request->data->deliveryId);
            $workerRt = $this->sessionHandoffRuntime($acceptKey, match ($ending) {
                'challenge' => 102,
                'timeout' => null,
                default => 101,
            });
            Hilos::$rt = $workerRt;
            $worker->deliver(new DaemonAgentMessageDTO($guest->getId(), $request));
            self::assertSame([DeliveryFlowSessionRuntime::connections],
                SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
            $expires = in_array($ending, ['timeout', 'challenge', 'deleted'], true);
            $grant = null;
            if ($ending === 'challenge') {
                $probe = $worker->router->getNextQueuedSignal();
                self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
                Hilos::$rt = $masterRt;
                $this->queueServingWorkerReply($this->master, $probe);
                $grant = array_pop($this->worker->requests);
                self::assertSame(PageHandoffIdentity::GRANTED, $grant->signal->meta[PageHandoffIdentity::META_PROOF]);
                Hilos::$rt = $workerRt;
            }
            if ($expires) {
                $client->sendFrame('{"type":"neighbor","data":1}');
                $client->write();
                self::assertSame([], $this->read($socket));
                self::assertTrue($client->isDeliveryPending($page, $request->data->deliveryId));
                self::assertArrayHasKey($request->data->deliveryId, $this->pending()[$acceptKey]);
            }
            match ($ending) {
                'deleted' => $worker->applyConnectionSync(new RtSyncDeletedSignalData(DeliveryFlowSessionRuntime::connections, $acceptKey)),
                'leave' => $worker->deliver(new DaemonAgentMessageDTO($guest->getId(), new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                    new SignalName($page),
                    new WebSocketPageUnsubscribeSignalDTO($acceptKey),
                    [WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $request->data->deliveryId],
                ))),
                'close' => $worker->deliver(new DaemonAgentMessageDTO($guest->getId(), new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
                    new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
                    new WebSocketCloseSignalDTO($acceptKey),
                ))),
                default => $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS),
            };
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
            self::assertNull($worker->router->pageSubscription($acceptKey));
            if ($expires) {
                $terminal = $worker->router->getNextQueuedSignal();
                if ($terminal !== null) {
                    Hilos::$rt = $masterRt;
                    $this->queueServingWorkerReply($this->master, $terminal);
                    $this->queueServingWorkerReply($this->master, $terminal);
                }
                self::assertFalse($client->isDeliveryPending($page, $request->data->deliveryId));
                self::assertArrayNotHasKey($request->data->deliveryId, $this->pending()[$acceptKey] ?? []);
                $this->master->onAgentStarted($guest->getId());
                $this->master->drain();
                self::assertSame([], $this->worker->requests);
                $client->now += 5000;
                $client->write();
                [$frame] = $this->read($socket);
                self::assertSame(['neighbor', SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frame['data']['frames'], 'type'));

                Hilos::$rt = $workerRt;
                if ($grant !== null) {
                    $worker->deliver($grant);
                } else {
                    // The worker dropped the copy with its last reader. Let a later stream fact arrive.
                    Hilos::$rt = $this->sessionHandoffRuntime($acceptKey, 101);
                    $worker->applyConnectionUpdate(new RtSyncUpdatedSignalData(
                        DeliveryFlowSessionRuntime::connections, $acceptKey, [StateHilosSessionConnection::sessionId => 102],
                    ));
                }
                $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
            }
            self::assertNull($worker->router->getNextQueuedSignal());
            self::assertSame([], $guest->subscribedSessions);
            $client->write();
            self::assertSame([], $this->read($socket));
        } finally {
            Hilos::$rt = $previousRt;
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return iterable<string, array{string}> Terminal boundaries on the remote worker */
    public static function remoteHandoffEndings(): iterable
    {
        yield 'RT never catches up' => ['rt'];
        yield 'grant never reaches the worker' => ['grant lost'];
        yield 'grant crosses the deadline before the next sweep' => ['late grant'];
        yield 'agent hook throws before rendering' => ['hook'];
        yield 'probe at the socket deadline' => ['socket probe'];
        yield 'whole and completion at the socket deadline' => ['socket whole'];
        yield 'completion at the deadline with a whole already queued' => ['socket completion'];
        yield 'locally routed completion at the deadline' => ['socket routed completion'];
        yield 'completion just before the socket deadline' => ['before deadline'];
        yield 'socket expires before the serving ledger receives completion' => ['socket tick'];
        yield 'socket expires after the serving ledger receives completion' => ['socket tick after completion'];
        yield 'socket expires while serving master holds the request' => ['serving hold'];
        yield 'RT thaws with the same session' => ['thaw'];
        yield 'RT snapshot replaces the session' => ['snapshot'];
    }

    #[DataProvider('remoteHandoffEndings')]
    public function testRemoteHandoffHasOneTerminalOutcomeOnBothMasters(string $ending): void
    {
        $oldRt = Hilos::$rt;
        $oldCluster = Hilos::$cluster;
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $socketRouter = Hilos::$sr;
        [$outbound, $socketLink, $replies] = $this->connectPeer('socket', 'remote');
        [$inbound, $servingLink, $probes] = $this->connectPeer('remote', 'socket');
        $this->master->registerServer($outbound);
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            $initialSessionId = in_array($ending, ['thaw', 'snapshot'], true) ? 101 : 102;
            $socketRt = $this->sessionHandoffRuntime($acceptKey, $initialSessionId);
            $workerRt = $this->sessionHandoffRuntime($acceptKey, $ending === 'rt' ? null : $initialSessionId);
            Hilos::$rt = $socketRt;
            $request = $this->subscribe($client, $page);
            $guest = new DeliveryFlowGuestAgent();
            $this->master->route($request, new RemoteAgentDestination('remote', $guest->getType()), $this->worker);
            $token = $request->data->deliveryId;
            self::assertSame('remote', $this->pending()[$acceptKey][$token]->nodeId);
            $client->sendFrame('{"type":"neighbor","data":1}');
            $client->write();
            self::assertSame([], $this->read($socket));

            $serving = new DeliveryFlowMaster();
            $servingRouter = Hilos::$sr;
            $workerServer = new DeliveryFlowWorkerServer();
            $serving->registerServer($workerServer);
            $serving->registerServer($inbound);
            $worker = new PerInstanceIdentityTestWorker(3, $guest);
            Hilos::$sr = $servingRouter;
            Hilos::$rt = $workerRt;
            $servingCluster = new ClusterContext();
            Hilos::$cluster = $servingCluster;
            $socketCluster = new ClusterContext();
            $socketCluster->registerClientSignalSink($this->master);
            $placement = $this->createStub(WorkerPlacement::class);
            $placement->method('locate')->willReturn(AgentLocation::onNode('remote'));
            $socketCluster->registerWorkerPlacement($placement);
            $servingCluster->registerAgentSignalSink($serving);
            $locations = new ClusterClientLocation('remote');
            $locations->applySnapshot('socket', [$acceptKey]);
            $servingCluster->registerClientLocation($locations);
            $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', $guest->getType(), null, $request)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            if ($ending === 'serving hold') {
                $workerServer->awaiting = true;
                $serving->getAgentManagerDaemon()->started = false;
            }
            $inbound->onSignalReceived($servingLink, $forwarded);
            $serving->drain();
            self::assertArrayHasKey($token, $this->pending($serving)[$acceptKey]);
            $deadline = (int) (2 * AgentConstants::START_DEADLINE_SECONDS * 1000);
            if ($ending === 'serving hold') {
                self::assertCount(1, $this->heldAgentFrames($serving));
                self::assertSame([], $workerServer->requests);
                Hilos::$sr = $socketRouter;
                Hilos::$rt = $socketRt;
                Hilos::$cluster = $socketCluster;
                $client->now = $deadline;
                $client->onTick();
                $this->assertSocketHandoffExpiry($client, $socket, $page, $token);
                Hilos::$sr = $servingRouter;
                Hilos::$rt = $workerRt;
                Hilos::$cluster = $servingCluster;
                [$release] = $this->takePeerFrames($socketLink, $replies, 1);
                $inbound->onSignalReceived($servingLink, $release);
                $serving->drain();
                self::assertSame([], $this->pending($serving));
                self::assertSame([], $this->heldAgentFrames($serving));
                $serving->getAgentManagerDaemon()->started = true;
                $workerServer->awaiting = false;
                $serving->onAgentStarted($guest->getId());
                $serving->drain();
                foreach ($workerServer->requests as $message) {
                    $worker->deliver($message);
                }
                self::assertSame([], $guest->subscribedSessions);
                self::assertNull($worker->router->pageSubscription($acceptKey));
                return;
            }
            self::assertCount(1, $workerServer->requests);

            $neighbor = SourceConsumer::page('neighbor-reader');
            SourceInterestRegistry::register(SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections, $neighbor);
            $worker->deliver(array_pop($workerServer->requests));
            if ($ending === 'rt') {
                self::assertNull($worker->router->getNextQueuedSignal());
            } else {
                $probe = $worker->router->getNextQueuedSignal();
                self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
                $this->queueServingWorkerReply($serving, $probe);
                [$query] = $this->takePeerFrames($servingLink, $probes, 1);
                self::assertInstanceOf(PeerClientSignalDTO::class, $query);

                Hilos::$rt = $socketRt;
                Hilos::$sr = $socketRouter;
                Hilos::$cluster = $socketCluster;
                if ($ending === 'socket probe') {
                    $client->now = $deadline;
                }
                $outbound->onClientSignalReceived($socketLink, $query);
                $this->master->drain();
                if ($ending === 'socket probe') {
                    $this->assertSocketHandoffExpiry($client, $socket, $page, $token);
                    [$release, $denial] = $this->takePeerFrames($socketLink, $replies, 2);
                    self::assertInstanceOf(PeerSignalDTO::class, $release);
                    self::assertInstanceOf(PeerSignalDTO::class, $denial);
                    self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $release->signal->signalType->getType());
                    self::assertSame(PageHandoffIdentity::REFUSED, $denial->signal->meta[PageHandoffIdentity::META_PROOF]);
                    Hilos::$sr = $servingRouter;
                    Hilos::$rt = $workerRt;
                    Hilos::$cluster = $servingCluster;
                    $inbound->onSignalReceived($servingLink, $release);
                    $inbound->onSignalReceived($servingLink, $denial);
                    $serving->drain();
                    self::assertSame([], $this->pending($serving));
                    foreach ($workerServer->requests as $message) {
                        $worker->deliver($message);
                    }
                    self::assertSame([], $guest->subscribedSessions);
                    self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(
                        SourceConsumer::pageHandoff($token), SourceChange::KIND_RT,
                    ));
                    return;
                }
                [$grant] = $this->takePeerFrames($socketLink, $replies, 1);
                self::assertInstanceOf(PeerSignalDTO::class, $grant);
                self::assertSame(PageHandoffIdentity::GRANTED, $grant->signal->meta[PageHandoffIdentity::META_PROOF]);
                Hilos::$sr = $servingRouter;
                Hilos::$rt = $workerRt;
                Hilos::$cluster = $servingCluster;
                if ($ending !== 'grant lost') {
                    $inbound->onSignalReceived($servingLink, $grant);
                    $serving->drain();
                    self::assertCount(1, $workerServer->requests);
                    if ($ending === 'late grant') {
                        $since = new ReflectionProperty(WorkerManager::class, 'pageHandoffSince');
                        $since->setValue($worker, [$acceptKey => microtime(true) - AgentConstants::START_DEADLINE_SECONDS]);
                        $worker->deliver(array_pop($workerServer->requests));
                    } elseif ($ending !== 'hook') {
                        $worker->deliver(array_pop($workerServer->requests));
                    } else {
                        $guest->onSubscribe = static fn () => throw new RuntimeException('fixture subscribe hook failed');
                        try {
                            $worker->deliver(array_pop($workerServer->requests));
                            self::fail('The project hook should fail before the page renderer.');
                        } catch (RuntimeException $failure) {
                            self::assertSame('fixture subscribe hook failed', $failure->getMessage());
                        }
                    }
                }
            }
            $socketExpiry = in_array($ending, ['socket tick', 'socket tick after completion'], true);
            $deadlineCompletion = in_array($ending, ['socket completion', 'socket routed completion'], true);
            if ($socketExpiry || $deadlineCompletion || in_array($ending, ['socket whole', 'before deadline', 'thaw', 'snapshot'], true)) {
                self::assertSame($token, $worker->router->pageSubscription($acceptKey)?->deliveryId);
                self::assertSame([$initialSessionId], $guest->subscribedSessions);
                self::assertNull($worker->idleDeadline($guest->getId()));
                self::assertSame(SignalTypeConstants::RT_STALENESS, $worker->router->getNextQueuedSignal()?->signalName->getName());
                $answer = $worker->router->getNextQueuedSignal();
                self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer?->signalName->getName());
                $this->queueServingWorkerReply($serving, $answer);
                $complete = $worker->router->getNextQueuedSignal();
                self::assertSame(SignalConstants::DELIVERY_COMPLETE, $complete?->signalName->getName());
                if ($ending !== 'socket tick') {
                    $this->queueServingWorkerReply($serving, $complete);
                }

                Hilos::$sr = $socketRouter;
                Hilos::$rt = $socketRt;
                Hilos::$cluster = $socketCluster;
                if ($socketExpiry) {
                    $client->now = $deadline;
                    $client->onTick();
                    $this->assertSocketHandoffExpiry($client, $socket, $page, $token);
                    Hilos::$sr = $servingRouter;
                    Hilos::$rt = $workerRt;
                    Hilos::$cluster = $servingCluster;
                    [$release] = $this->takePeerFrames($socketLink, $replies, 1);
                    self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $release->signal->signalType->getType());
                    $inbound->onSignalReceived($servingLink, $release);
                    $serving->drain();
                    self::assertSame([], $this->pending($serving));
                    $worker->deliver(array_pop($workerServer->requests));
                    self::assertNull($worker->router->pageSubscription($acceptKey));
                    self::assertNotNull($worker->idleDeadline($guest->getId()));
                    self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($acceptKey), SourceChange::KIND_RT));
                    self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::pageHandoff($token), SourceChange::KIND_RT));
                    self::assertSame([DeliveryFlowSessionRuntime::connections],
                        SourceInterestRegistry::collectionsOfConsumer($neighbor, SourceChange::KIND_RT));
                    Hilos::$sr = $worker->router;
                    Hilos::$browser->record(SourceChange::dbUpdated('fixture', 'observed', ['name' => 'changed']));
                    Hilos::$browser->flushToSignalRouter();
                    self::assertSame(PageResendOutcome::Unserved, $worker->resendPage($page, $acceptKey, []));
                    Hilos::$sr = $servingRouter;
                    if ($ending === 'socket tick') {
                        $this->queueServingWorkerReply($serving, $complete);
                    }
                    Hilos::$sr = $socketRouter;
                    Hilos::$rt = $socketRt;
                    Hilos::$cluster = $socketCluster;
                } elseif ($deadlineCompletion || $ending === 'socket whole' || $ending === 'before deadline') {
                    $client->now = $ending === 'socket whole' ? $deadline : $deadline - 1;
                }
                [$whole, $finished] = $this->takePeerFrames($servingLink, $probes, 2);
                $outbound->onClientSignalReceived($socketLink, $whole);
                if ($deadlineCompletion) {
                    self::assertTrue($client->isDeliveryPending($page, $token));
                    $client->write();
                    self::assertSame([], $this->read($socket));
                    $client->now = $deadline;
                }
                if ($ending === 'socket routed completion') {
                    $this->queueServingWorkerReply($this->master, $complete);
                }
                $outbound->onClientSignalReceived($socketLink, $finished);
                $outbound->onClientSignalReceived($socketLink, $finished);
                $this->master->drain();
                self::assertSame([], $this->pending());
                self::assertFalse($client->isDeliveryPending($page, $token));
                if ($ending === 'socket whole' || $deadlineCompletion) {
                    $this->assertSocketHandoffExpiry($client, $socket, $page, $token);
                } elseif ($socketExpiry) {
                    $client->write();
                    self::assertSame([], $this->read($socket));
                } elseif ($ending === 'before deadline') {
                    $client->now = $deadline;
                    $client->onTick();
                    $client->write();
                    $frames = $this->read($socket);
                    self::assertCount(1, $frames);
                    self::assertSame(['neighbor', SignalTypeConstants::PAGE_RESPONSE], array_column($frames[0]['data']['frames'], 'type'));
                } else {
                    $sessionId = $ending === 'snapshot' ? 102 : $initialSessionId;
                    [$release, $reassess] = $this->recoverRemoteHandoff(
                        $client, $socket, $socketLink, $replies, $request->data, $ending === 'snapshot',
                    );
                    Hilos::$sr = $servingRouter;
                    Hilos::$rt = $workerRt;
                    Hilos::$cluster = $servingCluster;
                    $inbound->onSignalReceived($servingLink, $release);
                    $inbound->onSignalReceived($servingLink, $reassess);
                    $serving->drain();
                    $worker->applyConnectionUpdate(new RtSyncUpdatedSignalData(
                        DeliveryFlowSessionRuntime::connections, $acceptKey, [StateHilosSessionConnection::sessionId => $sessionId],
                    ));
                    foreach ($workerServer->requests as $message) {
                        $worker->deliver($message);
                    }
                    $workerServer->requests = [];
                    $this->queueServingWorkerReply($serving, $this->nextWorkerHandoffFrame($worker, SignalTypeConstants::PAGE_ACCESS_REASSESS));
                    [$freshProbe] = $this->takePeerFrames($servingLink, $probes, 1);

                    Hilos::$sr = $socketRouter;
                    Hilos::$rt = $socketRt;
                    Hilos::$cluster = $socketCluster;
                    $outbound->onClientSignalReceived($socketLink, $freshProbe);
                    $this->master->drain();
                    [$freshGrant] = $this->takePeerFrames($socketLink, $replies, 1);

                    Hilos::$sr = $servingRouter;
                    Hilos::$rt = $workerRt;
                    Hilos::$cluster = $servingCluster;
                    $inbound->onSignalReceived($servingLink, $freshGrant);
                    $serving->drain();
                    $worker->deliver(array_pop($workerServer->requests));
                    $newMirror = $worker->router->pageSubscription($acceptKey);
                    $inbound->onSignalReceived($servingLink, $release);
                    $serving->drain();
                    $worker->deliver(array_pop($workerServer->requests));
                    self::assertSame($newMirror, $worker->router->pageSubscription($acceptKey));
                    self::assertSame([$initialSessionId, $sessionId], $guest->subscribedSessions);
                    $this->queueServingWorkerReply($serving, $this->nextWorkerHandoffFrame($worker, SignalTypeConstants::PAGE_RESPONSE));
                    $this->queueServingWorkerReply($serving, $this->nextWorkerHandoffFrame($worker, SignalConstants::DELIVERY_COMPLETE));
                    self::assertSame([], $this->pending($serving));
                    [$freshWhole, $freshComplete] = $this->takePeerFrames($servingLink, $probes, 2);

                    Hilos::$sr = $socketRouter;
                    Hilos::$rt = $socketRt;
                    Hilos::$cluster = $socketCluster;
                    $outbound->onClientSignalReceived($socketLink, $freshWhole);
                    $outbound->onClientSignalReceived($socketLink, $freshComplete);
                    $outbound->onClientSignalReceived($socketLink, $whole);
                    $this->master->drain();
                    $client->write();
                    $frames = $this->read($socket);
                    self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'type'));
                    self::assertSame($sessionId, $frames[0]['data']['payload']['data'][StateHilosSessionConnection::sessionId]);
                    self::assertSame([], $this->pending());
                }
                return;
            }

            if ($ending === 'rt' || $ending === 'grant lost') {
                $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
            }
            self::assertNull($worker->router->pageSubscription($acceptKey));
            self::assertSame([], $guest->subscribedSessions);
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::pageHandoff($token), SourceChange::KIND_RT));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($acceptKey), SourceChange::KIND_RT));
            self::assertSame([DeliveryFlowSessionRuntime::connections],
                SourceInterestRegistry::collectionsOfConsumer($neighbor, SourceChange::KIND_RT));
            do {
                $terminal = $worker->router->getNextQueuedSignal();
            } while ($terminal?->signalName->getName() === SignalTypeConstants::RT_STALENESS);
            self::assertSame($token, $terminal?->meta[PageHandoffIdentity::META_TERMINAL_REFUSAL]);
            $this->queueServingWorkerReply($serving, $terminal);
            [$expired] = $this->takePeerFrames($servingLink, $probes, 1);
            self::assertInstanceOf(PeerClientSignalDTO::class, $expired);
            self::assertSame([], $this->pending($serving));
            $serving->onAgentStopped($guest->getId());
            $serving->drain();
            $servingLink->write();
            self::assertSame('', $this->readBytes($probes));

            Hilos::$sr = $socketRouter;
            Hilos::$rt = $socketRt;
            Hilos::$cluster = new ClusterContext();
            Hilos::$cluster->registerClientSignalSink($this->master);
            $outbound->onClientSignalReceived($socketLink, $expired);
            $outbound->onClientSignalReceived($socketLink, $expired);
            $this->master->drain();
            self::assertFalse($client->isDeliveryPending($page, $token));
            self::assertSame([], $this->pending());
            $client->write();
            [$frame] = $this->read($socket);
            self::assertSame(['neighbor', SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frame['data']['frames'], 'type'));
            self::assertSame(503, $frame['data']['frames'][1]['data']['httpCode']);
            self::assertSame('agent_unavailable', $frame['data']['frames'][1]['data']['errorCode']);
        } finally {
            $servingLink->close();
            $socketLink->close();
            socket_close($probes);
            socket_close($replies);
            Hilos::$sr = $socketRouter;
            Hilos::$rt = $oldRt;
            Hilos::$cluster = $oldCluster;
        }
    }

    private function assertSocketHandoffExpiry(DeliveryFlowClient $client, Socket $socket, string $page, string $token): void
    {
        self::assertFalse($client->isDeliveryPending($page, $token));
        self::assertSame([], $this->pending());
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame(['neighbor', SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frames[0]['data']['frames'], 'type'));
        self::assertSame(503, $frames[0]['data']['frames'][1]['data']['httpCode']);
        self::assertSame('agent_unavailable', $frames[0]['data']['frames'][1]['data']['errorCode']);
        $client->onTick();
        $client->write();
        self::assertSame([], $this->read($socket));
    }

    private function nextWorkerHandoffFrame(PerInstanceIdentityTestWorker $worker, string $type): SignalDTO
    {
        do {
            $frame = $worker->router->getNextQueuedSignal();
        } while ($frame?->signalName->getName() === SignalTypeConstants::RT_STALENESS);
        self::assertSame($type, $frame?->signalName->getName());
        return $frame;
    }

    /** @return array{PeerSignalDTO, PeerSignalDTO} Old exact release and new handoff to the serving node */
    private function recoverRemoteHandoff(
        DeliveryFlowClient $client,
        Socket $socket,
        PeerLink $link,
        Socket $peer,
        WebSocketPageSubscribeSignalDTO $request,
        bool $snapshot,
    ): array {
        [$ordinary, $ordinarySocket] = $this->connect();
        $other = $this->subscribe($ordinary);
        $otherSubscription = Hilos::$sr->pageSubscription($other->data->acceptKey);
        $this->deliver($this->addressedPageReply($other->data, false, 'ordinary'), $ordinary);
        $this->deliver(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData($other->data->page, $other->data->deliveryId),
                targetAcceptKey: $other->data->acceptKey),
        ), $ordinary);
        $source = 'rt-source';
        $collection = DeliveryFlowSessionRuntime::connections;
        $key = $request->acceptKey;
        $this->master->getAgentManagerDaemon()->rtReplicaOriginMap()->note($source, $collection, [$key]);
        $this->master->noteNodeUnreachable($source, microtime(true));
        self::assertNull(PageHandoffIdentity::current($key));
        $client->write();
        self::assertSame([['type' => 'neighbor', 'data' => 1]], $this->read($socket));

        if ($snapshot) {
            $this->publishConnectionSnapshot($source, $key, 102);
        } else {
            new ReflectionMethod(DaemonManager::class, 'clearStaleness')->invoke($this->master, $collection, [$key]);
        }
        self::assertSame($snapshot ? 102 : 101, PageHandoffIdentity::current($key)?->sessionId);
        $this->master->drain();
        self::assertSame($otherSubscription, Hilos::$sr->pageSubscription($other->data->acceptKey));
        $ordinary->write();
        $ordinaryFrames = $this->read($ordinarySocket);
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($ordinaryFrames, 'type'));
        self::assertSame('ordinary', $ordinaryFrames[0]['data']['payload']['data']['marker']);
        [$release, $reassess] = $this->takePeerFrames($link, $peer, 2);
        self::assertInstanceOf(PeerSignalDTO::class, $release);
        self::assertInstanceOf(PeerSignalDTO::class, $reassess);
        self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $release->signal->signalType->getType());
        self::assertSame(SignalTypeConstants::PAGE_ACCESS_REASSESS, $reassess->signal->signalType->getType());
        self::assertNotSame($request->deliveryId, $reassess->signal->data->deliveryId);
        $this->deliver($this->addressedPageReply($request, false, 'obsolete'), $client, peer: true);
        $client->write();
        self::assertSame([], $this->read($socket));

        return [$release, $reassess];
    }

    private function publishConnectionSnapshot(string $source, string $key, int $sessionId): void
    {
        $runtime = Hilos::$rt;
        $parts = [];
        $sender = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $part) use (&$parts): void { $parts[] = $part; },
            static function (): void {},
        );
        try {
            Hilos::$rt = $this->sessionHandoffRuntime($key, $sessionId);
            $sender->sendSnapshot(DeliveryFlowSessionRuntime::connections, RtSnapshotScope::rows([$key]), $source, partialOwner: true);
        } finally {
            Hilos::$rt = $runtime;
        }
        $published = false;
        $receiver = new RtSyncStreamChannel(
            $sender->receive(...),
            function (RtSyncStreamSnapshot $snapshot, Closure $settled) use ($source, &$published): Generator {
                return $this->master->stageRemoteRtSnapshot(
                    $source,
                    $snapshot,
                    static function (bool $accepted) use ($settled, &$published): void {
                        $published = $accepted;
                        $settled($accepted);
                    },
                    static function (): void {},
                    static fn (): bool => false,
                );
            },
            receipt: fn () => $this->master->captureRtSyncReceipt($source),
        );
        foreach ($parts as $part) {
            $receiver->receive($part);
        }
        self::assertTrue($published);
    }

    /** @return iterable<string, array{bool, bool}> Successive changes and the predecessor's packing boundary */
    public static function handoffNeighborOrders(): iterable
    {
        yield 'one reassess' => [false, false];
        yield 'two reassessments with an unpacked predecessor' => [true, false];
        yield 'two reassessments with a packed predecessor' => [true, true];
    }

    #[DataProvider('handoffNeighborOrders')]
    public function testRetargetedHandoffPreservesItsIndependentFrames(bool $retarget, bool $packed): void
    {
        $oldRt = Hilos::$rt;
        $oldRouter = Hilos::$sr;
        [$client, $socket] = $this->connect();
        $key = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $guest = new DeliveryFlowGuestAgent();
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            $socketRt = $this->sessionHandoffRuntime($key, 101);
            Hilos::$rt = $socketRt;
            $initial = $this->subscribe($client, $page);
            $worker = new PerInstanceIdentityTestWorker(3, $guest);
            $workerRt = $this->sessionHandoffRuntime($key, 101);
            Hilos::$sr = $oldRouter;
            $client->sendFrame('{"type":"session_control","data":101}');
            $this->deliver($this->addressedPageReply($initial->data, false, 'obsolete'), $client);
            $client->completeDelivery($page, $initial->data->deliveryId);
            if ($packed) {
                new ReflectionMethod(WebSocketClient::class, 'drainDelivery')->invoke($client);
                self::assertTrue($client->hasPendingWrite());
            }

            $this->applyHandoffFact($this->master, SignalTypeConstants::RT_SYNC_UPDATED, new RtSyncUpdatedSignalData(
                DeliveryFlowSessionRuntime::connections, $key, [StateHilosSessionConnection::sessionId => 102],
            ));
            $this->master->drain();
            $request = array_pop($this->worker->requests);
            $middle = $request->signal->data;
            self::assertNotSame($initial->data->deliveryId, $middle->deliveryId);
            $this->worker->requests = [];
            Hilos::$rt = $workerRt;
            $worker->deliver($request);
            $interest = SourceConsumer::pageHandoff($middle->deliveryId);
            self::assertSame([DeliveryFlowSessionRuntime::connections],
                SourceInterestRegistry::collectionsOfConsumer($interest, SourceChange::KIND_RT));
            Hilos::$rt = $socketRt;
            $client->sendFrame('{"type":"session_notice","data":102}');
            $client->sendFrame('{"type":"action_reply","data":"between"}');

            if ($retarget) {
                $this->applyHandoffFact($this->master, SignalTypeConstants::RT_SYNC_UPDATED, new RtSyncUpdatedSignalData(
                    DeliveryFlowSessionRuntime::connections, $key, [StateHilosSessionConnection::sessionId => 103],
                ));
                $this->master->drain();
                $request = array_pop($this->worker->requests);
                self::assertNotSame($middle->deliveryId, $request->signal->data->deliveryId);
                self::assertFalse($client->isDeliveryPending($page, $middle->deliveryId));
                Hilos::$rt = $workerRt;
                foreach ($this->worker->requests as $release) {
                    $worker->deliver($release);
                }
                self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($interest, SourceChange::KIND_RT));
                Hilos::$rt = $socketRt;
                $this->deliver($this->addressedPageReply($middle, false, 'obsolete middle'), $client);
            }

            $client->sendFrame('{"type":"action_reply_final","data":"after"}');
            $sessionId = $retarget ? 103 : 102;
            Hilos::$rt = $workerRt;
            $worker->applyConnectionUpdate(new RtSyncUpdatedSignalData(
                DeliveryFlowSessionRuntime::connections, $key, [StateHilosSessionConnection::sessionId => $sessionId],
            ));
            if ($retarget) {
                $worker->deliver($request);
            }
            Hilos::$rt = $socketRt;
            $this->queueServingWorkerReply($this->master, $this->nextWorkerHandoffFrame($worker, SignalTypeConstants::PAGE_ACCESS_REASSESS));
            $grant = array_pop($this->worker->requests);
            Hilos::$rt = $workerRt;
            $worker->deliver($grant);
            self::assertSame([$sessionId], $guest->subscribedSessions);
            Hilos::$rt = $socketRt;
            $this->flushLocalHandoffWorker($worker);
            $client->write();
            $client->write();
            $frames = [];
            foreach ($this->read($socket) as $packet) {
                array_push($frames, ...($packet['type'] === SignalTypeConstants::SIGNAL_BATCH ? $packet['data']['frames'] : [$packet]));
            }
            self::assertSame(['session_control', 'session_notice', 'action_reply', 'action_reply_final', SignalTypeConstants::PAGE_RESPONSE],
                array_column($frames, 'type'));
            self::assertSame([101, 102, 'between', 'after'], array_column(array_slice($frames, 0, 4), 'data'));
            self::assertSame($sessionId, $frames[4]['data']['payload']['data'][StateHilosSessionConnection::sessionId]);
        } finally {
            Hilos::$rt = $oldRt;
            Hilos::$sr = $oldRouter;
        }
    }

    public function testGrantCannotRunOnAReplacementAgentOnTheSameWorker(): void
    {
        $previousRt = Hilos::$rt;
        $masterRouter = Hilos::$sr;
        [$client, $socket] = $this->connect();
        $page = PerInstancePublicIdentityPage::PAGE;
        $acceptKey = $client->acceptKey;
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = $this->sessionHandoffRuntime($acceptKey, 102);
            $old = new DeliveryFlowGuestAgent();
            $replacement = new DeliveryFlowGuestAgent();
            $worker = new PerInstanceIdentityTestWorker(3, $old);
            $worker->startAgent = static fn (): AgentInterface => $replacement;
            SourceInterestRegistry::register(
                SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections, SourceConsumer::page('other-reader'),
            );
            Hilos::$sr = $masterRouter;
            $request = $this->subscribe($client, $page);
            $worker->deliver(new DaemonAgentMessageDTO($old->getId(), $request));
            $this->queueServingWorkerReply($this->master, $worker->router->getNextQueuedSignal());
            $grant = array_pop($this->worker->requests);
            self::assertSame(PageHandoffIdentity::GRANTED, $grant->signal->meta[PageHandoffIdentity::META_PROOF]);

            $worker->deliver(new AgentStopDTO($old->getId()));
            $worker->deliver(new AgentStartDTO($old->getId(), [$acceptKey]));
            $worker->deliver($grant);
            self::assertSame([], $replacement->subscribedSessions);
            self::assertNull($worker->router->pageSubscription($acceptKey));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::pageHandoff($request->data->deliveryId), SourceChange::KIND_RT,
            ));
            self::assertNull($worker->router->getNextQueuedSignal());

            $fresh = $this->subscribe($client, $page);
            $this->worker->requests[] = new DaemonAgentMessageDTO($replacement->getId(), $fresh);
            $this->admitLocalHandoffWorker($worker);
            self::assertSame([102], $replacement->subscribedSessions);
            self::assertSame($fresh->data->deliveryId, $worker->router->pageSubscription($acceptKey)?->deliveryId);
            $client->write();
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->read($socket), 'type'));
        } finally {
            Hilos::$rt = $previousRt;
            Hilos::$sr = $masterRouter;
        }
    }

    /** @return iterable<string, array{string, bool}> Restart outcome and whether the first KEEP meets an already stale copy */
    public static function policyRestartOutcomes(): iterable
    {
        yield 'same link confirms the start' => ['started', false];
        yield 'identity changes before the worker starts' => ['changed', false];
        yield 'fresh stop gap owes the new price' => ['gap-started', false];
        yield 'fresh stop gap ends on identity change' => ['gap-changed', false];
        yield 'fresh stop gap ends on placement revocation' => ['gap-revoked', false];
        yield 'fresh stop gap ends when the placement starts on another worker' => ['gap-reseated', false];
        yield 'fresh stop gap ends on failed start' => ['gap-failed', false];
        yield 'fresh stop gap debt stays with the replaced delivery' => ['gap-superseded', false];
        yield 'accepted patch after RT confirmation changes the params of the owed whole' => ['stale-merged', true];
        yield 'empty patch after RT confirmation preserves the owed whole' => ['stale-merged-empty', true];
        yield 'a merged recovery and its buffered product fact share one whole' => ['stale-merged-with-fact', false];
        yield 'leave after RT confirmation drops the queued recovery' => ['stale-left-after-confirmation', true];

        foreach ([
            'stale' => 'the snapshot confirms the same identity',
            'stale-owner' => 'the snapshot confirms another owner',
            'stale-session' => 'the snapshot confirms another session of the same owner',
            'stale-deleted' => 'the snapshot confirms the connection was deleted',
            'stale-pending' => 'a pending session needs a later RT update',
            'stale-released' => 'exact release ends the mirror before recovery',
            'stale-closed' => 'socket close ends the mirror before recovery',
            'stale-superseded' => 'late release and snapshot leave the newer mirror intact',
            'left-before-start' => 'a socket leaving before start falls out of the live roster',
            'failed' => 'worker factory refuses the start',
            'unavailable' => 'old worker can no longer take the agent',
            'revoked' => 'placement is revoked while stopping',
            'released' => 'exact release reaches the original IPC during the wait',
        ] as $outcome => $description) {
            yield 'stale before KEEP, ' . $description => [$outcome, true];
            yield 'stale after KEEP, ' . $description => [$outcome, false];
        }
    }

    /**
     * @param string $outcome How the same-link POLICY restart or the original subscription ends
     * @param bool $staleBeforeKeep Whether the worker's first KEEP arrives after the RT apply refusal
     */
    #[DataProvider('policyRestartOutcomes')]
    public function testCompletedPageReceivesProductChangesAfterPolicyRestartOnTheSameLink(string $outcome, bool $staleBeforeKeep): void
    {
        $priceInStopGap = str_starts_with($outcome, 'gap-');
        if ($priceInStopGap) {
            $outcome = substr($outcome, strlen('gap-'));
        }
        $hasNeighborProductReader = $priceInStopGap && $outcome === 'reseated';
        $merge = str_starts_with($outcome, 'stale-merged');
        $leave = $outcome === 'stale-left-after-confirmation';
        $superseded = in_array($outcome, ['superseded', 'stale-superseded'], true);
        $previousRt = Hilos::$rt;
        $previousDb = Hilos::$db;
        $previousCluster = Hilos::$cluster;
        $masterRouter = Hilos::$sr;
        $app = new ReflectionProperty(Hilos::class, 'appClass');
        $previousApp = Hilos::appClass();
        $app->setValue(null, $priceInStopGap ? DeliveryFlowProductHilos::class : DeliveryFlowRelayHilos::class);
        Hilos::$cluster = null;
        $interests = new ReflectionProperty(SourceInterestRegistry::class, 'byConsumer');
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $previousInterests = $interests->getValue();
        $previousStates = $states->getValue();
        $interests->setValue(null, []);
        $states->setValue(null, []);
        [$client, $socket] = $this->connect();
        $acceptKey = $client->acceptKey;
        $page = PerInstancePublicIdentityPage::PAGE;
        $old = new PerInstanceIdentityTestAgent('7');
        $replacement = new PerInstanceIdentityTestAgent('7');
        $price = 1200;
        $worker = new PerInstanceIdentityTestWorker(2, $old);
        $worker->startAgent = static fn (): AgentInterface => $replacement;
        $stopped = false;
        $old->onStopping = static function () use (&$stopped): void {
            $stopped = true;
        };

        Hilos::$sr = $masterRouter;
        $masterRt = $this->restartHandoffRuntime($acceptKey);
        Hilos::$rt = $masterRt;
        Hilos::$browser = new class extends BrowserContext {
            protected function observesOwnPageChange(string $page, string $acceptKey, SourceChange $change): bool
            {
                return $page === PerInstancePublicIdentityPage::PAGE && $change->sourceKey === 'products';
            }

            protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
            {
                $connection = Hilos::$rt->connectionsRegistry()[$acceptKey] ?? null;

                return $connection === null ? ConnectionIdentity::pending() : ConnectionIdentity::resolved($connection->userId);
            }
        };
        Hilos::$browser->bindPageResender($worker);
        $worker->pageRouterFor = static function (AgentInterface $agent) use (&$price): PageSignalRouter {
            return new PageSignalRouter(new class ($agent, static function () use (&$price): int {
                return $price;
            }) extends AbstractPageFactory {
                public function __construct(AbstractAgent $agent, private readonly Closure $price)
                {
                    parent::__construct($agent);
                }

                public function hasPage(string $pageName): bool
                {
                    return $pageName === PerInstancePublicIdentityPage::PAGE;
                }

                protected function createPage(string $pageName): AbstractPage
                {
                    return new class ($this->agent, $this->price) extends AbstractPage {
                        public const string PAGE = PerInstancePublicIdentityPage::PAGE;

                        public function __construct(AbstractAgent $agent, private readonly Closure $price)
                        {
                            parent::__construct($agent);
                        }

                        protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
                        {
                            return new PagePayload(data: ['price' => ($this->price)(), 'params' => $params->toArray()]);
                        }
                    };
                }
            }, new ActionRouteConfig());
        };

        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->master);
        $server = new DeliveryFlowWorkerServer($agents);
        $server->setLiveConnectionRoster($this->master);
        [$link, $reader] = $this->connectSharedWorker($server, $agents, $old);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($link, true);
        $daemon = new WorkerDaemonClient();
        new ReflectionProperty(AbstractSocket::class, 'socket')->setValue($daemon, $reader);
        new ReflectionProperty(WorkerDaemonClient::class, 'state')->setValue($daemon, DaemonConnectionState::CONNECTED);
        $otherLink = null;
        $otherDaemon = null;
        try {
            $request = $this->subscribe($client, $page, ['sort' => 'initial']);
            $this->worker->requests[] = new DaemonAgentMessageDTO($old->getId(), $request);
            $this->admitLocalHandoffWorker($worker);
            $client->write();
            $initial = $this->read($socket);
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($initial, 'type'));
            self::assertSame(1200, $initial[0]['data']['payload']['data']['price']);
            self::assertSame([], $this->pending());
            self::assertNull($worker->idleDeadline($old->getId()));
            $retained = $worker->router->pageSubscription($acceptKey);
            self::assertNotNull($retained);
            self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 101), $retained->admittedIdentity);

            $agents->restartDaemon = new class extends AbstractAgentDaemon {
                public const string AGENT_TYPE = PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE;
                protected ?string $agentIndex = '7';

                public function requiresMonopolisticProcess(): bool
                {
                    return false;
                }
            };
            $agents->usesStartReports = true;
            $agents->handleAgentStarted(new WorkerAgentStartedDTO($old->getId(), $old->getType(), $old->getIndex()));
            new ReflectionProperty(WorkerManager::class, 'daemonClient')->setValue($worker, $daemon);
            $workerRt = null;
            $gapRt = null;
            $otherReader = SourceConsumer::agent(PerInstanceFallbackAgent::AGENT_TYPE);
            $productReader = SourceConsumer::feature('restart-neighbor-products');
            $oldReader = SourceConsumer::agent($old->getId());
            if ($priceInStopGap) {
                Hilos::$db = $this->createStub(HilosDbContext::class);
                $gapRt = $this->restartHandoffRuntime($acceptKey);
                SourceInterestRegistry::register(SourceChange::KIND_DB, 'products', $oldReader);
                if ($hasNeighborProductReader) {
                    SourceInterestRegistry::register(SourceChange::KIND_DB, 'products', $productReader);
                }
                // Releasing this second source forces a new reader report even if products stay.
                SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::users, $oldReader);
                new ReflectionMethod(WorkerManager::class, 'notifySourceInterest')->invoke($worker);
                $daemon->write();
                $link->read();
                $this->deliverRestartRtFrames($worker, $gapRt, $link, $daemon, ['products', HilosDbContext::users]);
                $daemon->write();
                $link->read();
            }
            if ($staleBeforeKeep || str_starts_with($outcome, 'stale')) {
                SourceInterestRegistry::register(
                    SourceChange::KIND_RT, RosterRtContext::connections, $otherReader,
                );
                $workerRt = $this->restartHandoffRuntime($acceptKey);
                new ReflectionMethod(WorkerManager::class, 'notifySourceInterest')->invoke($worker);
                $daemon->write();
                $link->read();
                $this->deliverRestartRtFrames($worker, $workerRt, $link, $daemon);
                $daemon->write();
                $link->read();
            }
            $staleSince = null;
            $snapshotAnnouncement = null;
            $productFactBeforeKeep = $staleBeforeKeep && ($outcome === 'stale' || $merge || $leave);
            if ($staleBeforeKeep) {
                // The master can certify this socket while its next collection snapshot awaits confirmation.
                // Gate the cut with the real confirmation registry so the IPC can deliver KEEP and start first.
                $snapshotAnnouncement = $agents->rtCopyConfirmation()->announce(
                    [RosterRtContext::connections], [RosterRtContext::connections],
                );
                $staleSince = $this->refuseRestartRtUpdate($worker, $workerRt, $link, $daemon, $acceptKey);
                if ($productFactBeforeKeep) {
                    Hilos::$rt = $workerRt;
                    RtStaleness::mark(RosterRtContext::connections, [$acceptKey], $staleSince);
                    self::assertFalse($worker->router->isPageAnswerCurrent($acceptKey));
                    $price = 1500;
                    $this->flushRestartProductFact($worker, $price);
                    self::assertNull($worker->router->getNextQueuedSignal());
                    Hilos::$rt = $masterRt;
                    RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
                    $client->write();
                    self::assertSame([], $this->read($socket));
                }
            }

            $server->restartAgentHere($old->getType(), $old->getIndex(), true);
            self::assertTrue($server->isAgentAwaitingStopReport($old->getId()));
            $link->write();
            $daemon->read();
            $stop = $daemon->getNextMessage();
            self::assertInstanceOf(AgentStopDTO::class, $stop);
            self::assertSame(AgentStopDTO::KEEP_RESTART_MIRRORS, $stop->pageMirrors);
            self::assertNull($daemon->getNextMessage());
            if ($staleBeforeKeep) {
                Hilos::$rt = $workerRt;
                RtStaleness::mark(RosterRtContext::connections, [$acceptKey], $staleSince);
                self::assertNull(PageHandoffIdentity::current($acceptKey));
            } elseif ($gapRt !== null) {
                Hilos::$rt = $gapRt;
                self::assertEquals($retained->admittedIdentity, PageHandoffIdentity::current($acceptKey));
            }
            $worker->deliver($stop);
            Hilos::$rt = $masterRt;
            RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
            self::assertTrue($stopped);
            self::assertSame($retained, $worker->router->pageSubscription($acceptKey));
            self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer(
                SourceConsumer::page($acceptKey), SourceChange::KIND_RT,
            ));
            if ($gapRt !== null) {
                self::assertTrue($link->awaitsStopReport($old->getId()));
                // Read the stop report and its preceding interest updates before routing the price.
                $daemon->write();
                $link->read();
                self::assertFalse($link->awaitsStopReport($old->getId()));
                $readerMap = $agents->workerReaderMap();
                $holder = AgentManagerDaemon::workerHolderId($link->getWorkerIndex());
                self::assertTrue($readerMap->holds($holder, SourceChange::KIND_DB, 'products'));
                self::assertFalse($readerMap->holds($holder, SourceChange::KIND_DB, HilosDbContext::users));
                self::assertSame(
                    [SourceConsumer::page($acceptKey), ...($hasNeighborProductReader ? [$productReader] : [])],
                    SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, 'products'),
                );
                $servers = new ReflectionProperty(DaemonManager::class, 'servers');
                $servers->setValue($this->master, [$this->server, $server]);
                try {
                    $agents->handleWorkerDbSyncUpdated(new WorkerDbSyncUpdatedMessageDTO(
                        new DbSyncUpdatedSignalData('products', '1', ['price' => 1500]),
                    ));
                    $this->master->drain();
                } finally {
                    $servers->setValue($this->master, [$this->server, $this->worker]);
                }
                $link->write();
                $daemon->read();
                $fact = $daemon->getNextMessage();
                self::assertInstanceOf(WorkerDbSyncUpdatedMessageDTO::class, $fact);
                self::assertSame(['price' => 1500], $fact->signalData->row);
                self::assertNull($daemon->getNextMessage());
                Hilos::$rt = $gapRt;
                self::assertFalse($worker->router->isPageAnswerCurrent($acceptKey));
                $product = $this->createStub(Object_::class);
                $product->method('applyDbSyncEntityUpdate')->willReturnCallback(static function (array $row) use (&$price): void {
                    $price = $row['price'];
                });
                $products = $this->createStub(Objects::class);
                $products->method('cachesRows')->willReturn(true);
                $products->method('offsetExists')->willReturn(true);
                $products->method('offsetGet')->willReturn($product);
                Hilos::$db->method('mountedObjectCollection')->willReturnMap([['products', $products]]);
                $worker->deliver($fact);
                self::assertSame(1500, $price);
                $this->flushRestartBrowser($worker);
                self::assertTrue($worker->router->hasPageDeliveryFailure($acceptKey));
                self::assertNull($worker->router->getNextQueuedSignal());
                self::assertFalse($agents->isAgentStarted($old->getId()));
                self::assertEquals($retained->admittedIdentity, PageHandoffIdentity::current($acceptKey));
                Hilos::$rt = $masterRt;
                $client->write();
                self::assertSame([], $this->read($socket));
            }
            if ($outcome === 'revoked') {
                $server->revokePlacement($old->getType(), $old->getIndex());
            } elseif ($outcome === 'released') {
                $servers = new ReflectionProperty(DaemonManager::class, 'servers');
                $servers->setValue($this->master, [$this->server, $server]);
                $startsBeforeRelease = $server->startRequests;
                try {
                    $this->master->deliverSignalToAgent($old->getType(), $old->getIndex(), new SignalDTO(
                        new SignalSource(SignalSource::WEBSOCKET),
                        new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                        new SignalName($page),
                        new WebSocketPageUnsubscribeSignalDTO($acceptKey),
                        [WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $request->data->deliveryId],
                    ));
                    $this->master->drain();
                } finally {
                    $servers->setValue($this->master, [$this->server, $this->worker]);
                }
                $link->write();
                $daemon->read();
                $release = $daemon->getNextMessage();
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $release);
                self::assertNull($daemon->getNextMessage());
                $worker->deliver($release);
                self::assertNull($worker->router->pageSubscription($acceptKey));
                self::assertTrue($server->isAgentAwaitingStopReport($old->getId()));
                self::assertSame($startsBeforeRelease, $server->startRequests);
                self::assertSame([], $this->pending());
            }
            $daemon->write();
            $link->read();
            self::assertFalse($link->awaitsStopReport($old->getId()));
            if ($workerRt !== null && !$staleBeforeKeep) {
                $staleSince = $this->refuseRestartRtUpdate($worker, $workerRt, $link, $daemon, $acceptKey);
            }
            if ($outcome === 'reseated') {
                [$otherLink, $otherSocket] = $this->connectSharedWorker($server, $agents);
                new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($otherLink, 3);
                new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($otherLink, true);
                $otherDaemon = new WorkerDaemonClient();
                new ReflectionProperty(AbstractSocket::class, 'socket')->setValue($otherDaemon, $otherSocket);
                new ReflectionProperty(WorkerDaemonClient::class, 'state')->setValue($otherDaemon, DaemonConnectionState::CONNECTED);
            }
            if ($outcome === 'unavailable' || $outcome === 'reseated') {
                new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($link, false);
            } elseif ($outcome === 'left-before-start') {
                new ReflectionMethod(AbstractServer::class, 'dropClient')->invoke($this->server, $client);
            }
            new ReflectionMethod(WorkerServer::class, 'advanceAgentsAwaitingStopReport')->invoke($server);
            $link->write();
            $daemon->read();
            $start = $daemon->getNextMessage();
            self::assertNull($daemon->getNextMessage());
            if ($workerRt !== null) {
                Hilos::$rt = $workerRt;
                RtStaleness::mark(RosterRtContext::connections, [$acceptKey], $staleSince);
                self::assertNull(PageHandoffIdentity::current($acceptKey));
            } elseif ($gapRt !== null) {
                Hilos::$rt = $gapRt;
                self::assertEquals($retained->admittedIdentity, PageHandoffIdentity::current($acceptKey));
            }
            if (in_array($outcome, ['unavailable', 'revoked', 'reseated'], true)) {
                self::assertInstanceOf(AgentStopDTO::class, $start);
                self::assertSame(AgentStopDTO::DISCARD_RESTART_MIRRORS, $start->pageMirrors);
                $worker->deliver($start);
                $this->assertRestartMirrorReleased($worker, $acceptKey);
                self::assertFalse($server->isAgentAwaitingStopReport($old->getId()));
                if ($otherLink === null) {
                    self::assertFalse($agents->hasAgent($old->getId()));
                } else {
                    self::assertSame($otherLink, $agents->getAgent($old->getId())?->getWorkerClient());
                    $otherLink->write();
                    $otherDaemon->read();
                    $reseated = $otherDaemon->getNextMessage();
                    self::assertInstanceOf(AgentStartDTO::class, $reseated);
                    self::assertContains($acceptKey, $reseated->liveAcceptKeys);
                    self::assertNull($otherDaemon->getNextMessage());
                    self::assertSame(['products'], SourceInterestRegistry::collectionsOfConsumer($productReader, SourceChange::KIND_DB));
                    $this->flushRestartBrowser($worker);
                    self::assertNull($worker->router->getNextQueuedSignal());
                    $client->write();
                    self::assertSame([], $this->read($socket));
                }
                return;
            }
            self::assertInstanceOf(AgentStartDTO::class, $start);
            if ($outcome === 'left-before-start') {
                self::assertNotContains($acceptKey, $start->liveAcceptKeys);
            } else {
                self::assertContains($acceptKey, $start->liveAcceptKeys);
            }
            self::assertFalse($agents->isAgentStarted($old->getId()));
            if ($outcome === 'failed') {
                $worker->startAgent = null;
                try {
                    $worker->deliver($start);
                    self::fail('The worker fixture must refuse the start');
                } catch (LogicException $failure) {
                    self::assertSame('The fixture mounts its agents before dispatch.', $failure->getMessage());
                }
                Hilos::$rt = $masterRt;
                RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
                $daemon->write();
                $link->read();
                self::assertFalse($agents->hasAgent($old->getId()));
                $this->assertRestartMirrorReleased($worker, $acceptKey);
                return;
            }
            if ($outcome === 'changed') {
                Hilos::$rt->connectionsSource()->get($acceptKey)->applyDiff([StateHilosConnection::userId => 8]);
            }
            $release = new DaemonAgentMessageDTO($old->getId(), new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                new SignalName($page),
                new WebSocketPageUnsubscribeSignalDTO($acceptKey),
                [WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $request->data->deliveryId],
            ));
            $worker->deliver($start);
            if ($workerRt !== null && !in_array($outcome, ['released', 'left-before-start'], true)) {
                self::assertNotNull($retained);
                self::assertSame($retained, $worker->router->pageSubscription($acceptKey));
                self::assertTrue($retained->retainedForRestart);
                self::assertTrue($retained->awaitingIdentityConfirmation);
                self::assertNull($worker->idleDeadline($replacement->getId()));
                self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer(
                    SourceConsumer::page($acceptKey), SourceChange::KIND_RT,
                ));
                if (!$productFactBeforeKeep) {
                    $price = 1500;
                    $this->recordRestartProductFact($worker, $price);
                    if ($outcome !== 'stale-merged-with-fact') {
                        $this->flushRestartBrowser($worker);
                    }
                } else {
                    $this->flushRestartBrowser($worker);
                }
                self::assertNull($worker->router->getNextQueuedSignal());
                self::assertTrue($worker->router->hasPageDeliveryFailure($acceptKey));
                $client->write();
                self::assertSame([], $this->read($socket));

                if ($outcome === 'stale-released') {
                    $worker->deliver($release);
                } elseif ($outcome === 'stale-closed') {
                    $worker->deliver(new DaemonAgentMessageDTO($replacement->getId(), new SignalDTO(
                        new SignalSource(SignalSource::WEBSOCKET),
                        new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
                        new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
                        new WebSocketCloseSignalDTO($acceptKey),
                    )));
                }
            }
            // The fixture hosts two processes in one interpreter. Only the worker refused the RT operation.
            Hilos::$rt = $masterRt;
            RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
            if ($workerRt !== null) {
                $this->setRestartSnapshotIdentity($masterRt, $acceptKey, $outcome);
            }
            $daemon->write();
            $link->read();
            self::assertTrue($agents->isAgentStarted($replacement->getId()));
            self::assertSame($link, $agents->getAgent($replacement->getId())?->getWorkerClient());
            if (in_array($outcome, ['released', 'changed', 'left-before-start'], true)) {
                $this->assertRestartMirrorReleased($worker, $acceptKey);
                self::assertNotNull($worker->idleDeadline($replacement->getId()));
                return;
            }

            if ($workerRt !== null) {
                if ($snapshotAnnouncement !== null) {
                    $confirmation = $agents->rtCopyConfirmation();
                    self::assertSame([RosterRtContext::connections], $confirmation->addressed($snapshotAnnouncement, []));
                    $waiting = $confirmation->takeWaitingWorkers(RosterRtContext::connections);
                    self::assertArrayHasKey($link->getWorkerIndex(), $waiting);
                    $link->sendConfirmedRtSnapshot(RosterRtContext::connections, $waiting[$link->getWorkerIndex()]);
                }
                RtStaleness::mark(RosterRtContext::connections, [$acceptKey], $staleSince);
                $this->deliverRestartRtFrames($worker, $workerRt, $link, $daemon);
                self::assertNull(RtStaleness::staleSince(RosterRtContext::connections, $acceptKey));
                self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer(
                    $otherReader, SourceChange::KIND_RT,
                ));
                Hilos::$rt = $workerRt;
                if ($outcome === 'stale-pending') {
                    self::assertNull(PageHandoffIdentity::current($acceptKey));
                    self::assertSame($retained, $worker->router->pageSubscription($acceptKey));
                    self::assertTrue($retained->retainedForRestart);
                    self::assertTrue($retained->awaitingIdentityConfirmation);
                    $this->flushRestartBrowser($worker);
                    self::assertNull($worker->router->getNextQueuedSignal());
                    self::assertTrue($worker->router->hasPageDeliveryFailure($acceptKey));
                    Hilos::$rt = $masterRt;
                    $confirmed = new RtSyncUpdatedSignalData(
                        RosterRtContext::connections, $acceptKey, [StateHilosSessionConnection::sessionId => 101],
                    );
                    RtWorkDriver::runSynchronously(RtSyncApplicator::applyUpdatedInPortions($confirmed));
                    $daemon->write();
                    $link->read();
                    $link->rtSyncStream()->send($confirmed);
                    $this->deliverRestartRtFrames($worker, $workerRt, $link, $daemon);
                    Hilos::$rt = $workerRt;
                } elseif ($outcome !== 'stale' && !$superseded && !$merge && !$leave) {
                    $confirmed = PageHandoffIdentity::current($acceptKey);
                    match ($outcome) {
                        'stale-owner' => self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 8, 101), $confirmed),
                        'stale-session' => self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 102), $confirmed),
                        'stale-deleted' => self::assertNull($workerRt->connectionsSource()->get($acceptKey)),
                        default => self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 101), $confirmed),
                    };
                    $this->assertRestartMirrorReleased($worker, $acceptKey);
                    self::assertNotNull($worker->idleDeadline($replacement->getId()));
                    $this->flushRestartProductFact($worker, 1700);
                    self::assertNull($worker->router->getNextQueuedSignal());
                    return;
                }
                self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 101), PageHandoffIdentity::current($acceptKey));
                self::assertSame($retained, $worker->router->pageSubscription($acceptKey));
                self::assertFalse($retained->retainedForRestart);
                self::assertFalse($retained->awaitingIdentityConfirmation);
            }
            Hilos::$rt = $workerRt ?? $gapRt ?? $masterRt;
            $sort = 'initial';
            if ($merge || $leave) {
                Hilos::$rt = $masterRt;
                $patch = $outcome === 'stale-merged-empty' ? [] : ['sort' => 'updated'];
                $client->acceptJson(json_encode([
                    'type' => $merge ? SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION : SignalTypeConstants::PAGE_UNSUBSCRIBE,
                    'page' => $page,
                    'params' => $patch,
                ], JSON_THROW_ON_ERROR));
                $this->master->drain();
                $update = array_pop($this->worker->requests);
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $update);
                Hilos::$rt = $workerRt;
                $worker->deliver($update);
                if ($leave) {
                    $this->assertRestartMirrorReleased($worker, $acceptKey);
                    $this->flushRestartBrowser($worker);
                    self::assertNull($worker->router->getNextQueuedSignal());
                    $client->write();
                    self::assertSame([], $this->read($socket));
                    return;
                }
                self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $update->signal->data);
                $complete = $worker->router->getNextQueuedSignal();
                self::assertSame(SignalConstants::DELIVERY_COMPLETE, $complete?->signalName->getName());
                self::assertSame($update->signal->data->deliveryId, $complete->data->data->deliveryId);
                Hilos::$rt = $masterRt;
                $this->queueServingWorkerReply($this->master, $complete);
                Hilos::$rt = $workerRt;
                $merged = $worker->router->pageSubscription($acceptKey);
                $sort = $patch['sort'] ?? $sort;
                self::assertNotSame($retained, $merged);
                self::assertSame($retained->deliveryId, $merged?->deliveryId);
                self::assertSame(['sort' => $sort], $merged->params);
                self::assertTrue($worker->router->hasPageDeliveryFailure($acceptKey));
                self::assertNull($worker->router->getNextQueuedSignal());
            }
            if ($superseded) {
                Hilos::$rt = $masterRt;
                if ($workerRt !== null) {
                    $link->sendConfirmedRtSnapshot(RosterRtContext::connections, 1);
                }
                // Admit N+1 with the same address and params before the recovery flush of N.
                $fresh = $this->subscribe($client, $page, ['sort' => $sort]);
                $this->worker->requests[] = new DaemonAgentMessageDTO($replacement->getId(), $fresh);
                $this->admitLocalHandoffWorker($worker);
                $client->write();
                self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->read($socket), 'type'));
                $current = $worker->router->pageSubscription($acceptKey);
                self::assertSame($fresh->data->deliveryId, $current?->deliveryId);
                $worker->deliver($release);
                $daemon->write();
                $link->read();
                if ($workerRt !== null) {
                    $this->deliverRestartRtFrames($worker, $workerRt, $link, $daemon);
                }
                Hilos::$rt = $workerRt ?? $gapRt ?? $masterRt;
                self::assertSame($current, $worker->router->pageSubscription($acceptKey));
                self::assertFalse($worker->router->hasPageDeliveryFailure($acceptKey));
                self::assertSame($priceInStopGap ? ['products'] : [], SourceInterestRegistry::collectionsOfConsumer(
                    SourceConsumer::page($acceptKey), SourceChange::KIND_DB,
                ));
                self::assertNull($worker->idleDeadline($replacement->getId()));
                self::assertTrue($agents->isAgentStarted($replacement->getId()));
                self::assertSame([], $this->pending());
                // A separate debt on N+1 must not be tried by the queued recovery for N.
                $worker->router->markPageDeliveryFailure($acceptKey);
                $this->flushRestartBrowser($worker);
                self::assertNull($worker->router->getNextQueuedSignal());
                self::assertTrue($worker->router->hasPageDeliveryFailure($acceptKey));
                $worker->router->clearPageDeliveryFailure($acceptKey);
            }
            if (($workerRt === null && !$priceInStopGap) || $superseded) {
                $this->flushRestartBrowser($worker);
                self::assertNull($worker->router->getNextQueuedSignal());
                $price = 1700;
                $this->flushRestartProductFact($worker, $price);
            } else {
                $this->flushRestartBrowser($worker);
            }
            $answer = $worker->router->getNextQueuedSignal();
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer?->signalName->getName());
            self::assertSame(
                $worker->router->pageSubscription($acceptKey)?->deliveryId,
                $answer->meta[WebSocketPageSubscribeSignalDTO::DELIVERY_ID] ?? null,
            );
            self::assertNull($worker->router->getNextQueuedSignal());
            self::assertFalse($worker->router->hasPageDeliveryFailure($acceptKey));
            Hilos::$rt = $masterRt;
            Hilos::$sr = $masterRouter;
            if ($priceInStopGap) {
                $daemon->send(new WorkerAgentMessageDTO(null, $answer));
                $daemon->write();
                $link->read();
                $this->master->drain();
            } else {
                $this->queueServingWorkerReply($this->master, $answer);
            }
            $client->write();
            $frames = $this->read($socket);
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 'type'));
            self::assertSame($price, $frames[0]['data']['payload']['data']['price']);
            self::assertSame(['sort' => $sort], $frames[0]['data']['payload']['data']['params']);
            self::assertNull($worker->idleDeadline($replacement->getId()));
        } finally {
            RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
            $otherDaemon?->close();
            $otherLink?->close();
            $daemon->close();
            $link->close();
            Hilos::$db = $previousDb;
            Hilos::$rt = $previousRt;
            Hilos::$cluster = $previousCluster;
            Hilos::$sr = $masterRouter;
            $app->setValue(null, $previousApp);
            $interests->setValue(null, $previousInterests);
            $states->setValue(null, $previousStates);
            ExecutionContext::clear();
        }
    }

    /** @param string $acceptKey Socket represented on the master and independently on the worker */
    private function restartHandoffRuntime(string $acceptKey): DeliveryFlowSessionRuntime
    {
        $runtime = new DeliveryFlowSessionRuntime(RosterRtContext::connections);
        $runtime->configure();
        $runtime->connectionsSource()->add(SessionViewConnection::create($acceptKey, 7, sessionId: 101));

        return $runtime;
    }

    /**
     * @param DeliveryFlowSessionRuntime $masterRt Fresh master copy used to produce the snapshot
     * @param string $acceptKey Socket represented by the snapshot
     * @param string $outcome Fact the snapshot must confirm
     */
    private function setRestartSnapshotIdentity(DeliveryFlowSessionRuntime $masterRt, string $acceptKey, string $outcome): void
    {
        self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 101), PageHandoffIdentity::current($acceptKey));
        if ($outcome === 'stale-deleted') {
            $masterRt->connectionsSource()->remove($acceptKey);
            return;
        }

        $diff = match ($outcome) {
            'stale-session' => [StateHilosSessionConnection::sessionId => 102],
            'stale-owner' => [StateHilosConnection::userId => 8],
            'stale-pending' => [StateHilosSessionConnection::sessionId => null],
            default => [],
        };
        if ($diff !== []) {
            RtWorkDriver::runSynchronously(RtSyncApplicator::applyUpdatedInPortions(
                new RtSyncUpdatedSignalData(RosterRtContext::connections, $acceptKey, $diff),
            ));
        }
    }

    /**
     * @param PerInstanceIdentityTestWorker $worker Worker that reads the product source
     * @param int $price Product price announced through the DB sync handler
     */
    private function flushRestartProductFact(PerInstanceIdentityTestWorker $worker, int $price): void
    {
        $this->recordRestartProductFact($worker, $price);
        $this->flushRestartBrowser($worker);
    }

    /**
     * @param PerInstanceIdentityTestWorker $worker Worker that reads the product source
     * @param int $price Product price to buffer without flushing
     */
    private function recordRestartProductFact(PerInstanceIdentityTestWorker $worker, int $price): void
    {
        Hilos::$db = $this->createStub(HilosDbContext::class);
        $worker->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData('products', '1', ['price' => $price])));
    }

    /** @param PerInstanceIdentityTestWorker $worker Worker whose browser changes should flush */
    private function flushRestartBrowser(PerInstanceIdentityTestWorker $worker): void
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = $worker->router;
        try {
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }

    /**
     * @param PerInstanceIdentityTestWorker $worker Worker receiving the invalid update
     * @param RtContext $workerRt Replica on that worker
     * @param WorkerClient $link Master end of the original IPC
     * @param WorkerDaemonClient $daemon Worker end of the original IPC
     * @param string $acceptKey Connection whose replica becomes stale
     * @return float Freshness mark belonging to the worker's RT copy
     */
    private function refuseRestartRtUpdate(
        PerInstanceIdentityTestWorker $worker, RtContext $workerRt, WorkerClient $link, WorkerDaemonClient $daemon, string $acceptKey,
    ): float {
        $link->rtSyncStream()->send(new RtSyncUpdatedSignalData(RosterRtContext::connections, $acceptKey, [
            StateHilosConnection::userId => ['invalid'],
        ]));
        $this->deliverRestartRtFrames($worker, $workerRt, $link, $daemon);
        $staleSince = RtStaleness::staleSince(RosterRtContext::connections, $acceptKey);
        self::assertNotNull($staleSince);
        RtStaleness::clear(RosterRtContext::connections, [$acceptKey]);
        self::assertEquals(new PageHandoffIdentity(RosterRtContext::connections, 7, 101), PageHandoffIdentity::current($acceptKey));

        return $staleSince;
    }

    /**
     * @param PerInstanceIdentityTestWorker $worker Worker using a separate RT replica
     * @param RtContext $workerRt Worker replica into which the real stream applies its frames
     * @param WorkerClient $link Master end of the original IPC
     * @param WorkerDaemonClient $daemon Worker end of the original IPC
     * @param list<string> $dbInterests DB interest confirmations expected beside the stream frames
     */
    private function deliverRestartRtFrames(
        PerInstanceIdentityTestWorker $worker, RtContext $workerRt, WorkerClient $link, WorkerDaemonClient $daemon,
        array $dbInterests = [],
    ): void {
        while ($link->rtSyncStream()->pump(new RtWorkBudget())) {
        }
        $link->write();
        $daemon->read();
        $masterRt = Hilos::$rt;
        $masterRouter = Hilos::$sr;
        Hilos::$rt = $workerRt;
        Hilos::$sr = $worker->router;
        try {
            while (($frame = $daemon->getNextMessage()) !== null) {
                if ($frame instanceof WorkerDbInterestReadyMessageDTO) {
                    self::assertSame(array_shift($dbInterests), $frame->collectionKey);
                } else {
                    self::assertInstanceOf(WorkerRtSyncStreamDTO::class, $frame);
                }
                $worker->deliver($frame);
            }
            self::assertSame([], $dbInterests);
            $stream = new ReflectionMethod(WorkerManager::class, 'rtSyncStream')->invoke($worker);
            while ($stream->pump(new RtWorkBudget())) {
            }
        } finally {
            Hilos::$rt = $masterRt;
            Hilos::$sr = $masterRouter;
        }
    }

    /**
     * @param PerInstanceIdentityTestWorker $worker Original serving worker
     * @param string $acceptKey Socket whose retained mirror was discarded
     */
    private function assertRestartMirrorReleased(PerInstanceIdentityTestWorker $worker, string $acceptKey): void
    {
        self::assertNull($worker->router->pageSubscription($acceptKey));
        self::assertFalse($worker->router->hasPageDeliveryFailure($acceptKey));
        self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($acceptKey), SourceChange::KIND_RT));
        self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($acceptKey), SourceChange::KIND_DB));
    }

    /** @return iterable<string, array{bool}> Reader whose last DB interest fails in stop */
    public static function stoppedReaderFailures(): iterable
    {
        yield 'accepted mirror' => [false];
        yield 'parked handoff' => [true];
    }

    /** @param bool $waiting Whether the DB failure happens when forgetting a handoff wait */
    #[DataProvider('stoppedReaderFailures')]
    public function testPageReaderFailureLeavesStopHookWithItsTruthSources(bool $waiting): void
    {
        $previousRt = Hilos::$rt;
        $previousDb = Hilos::$db;
        $masterRouter = Hilos::$sr;
        [$client] = $this->connect();
        [$other] = $this->connect();
        $page = PerInstancePublicIdentityPage::PAGE;
        $agent = new PerInstanceIdentityTestAgent('7');
        $agentId = $agent->getId();
        $worker = new PerInstanceIdentityTestWorker(2, $agent);
        $released = [];
        $agent->onRelease = static function () use (&$released): void {
            $released[] = ExecutionContext::currentAcceptKey();
        };
        $failingSource = 'failing_page_read';
        $db = $this->createMock(HilosDbContext::class);
        $db->expects(self::once())->method('reHydrateCollection')->with($failingSource)
            ->willThrowException(new DatabaseException('The page source failed to reload'));
        $ownedSource = 'stop_hook_owned';
        TruthSourceRegistry::register($ownedSource, TruthSourceKeys::all(), $agentId);
        RtTruthSourceRegistry::register($ownedSource, TruthSourceKeys::all(), $agentId);
        $observed = [];
        $agent->onStopping = static function () use (&$observed, $worker, $agentId, $ownedSource): void {
            $observed[] = [ExecutionContext::currentAgentId(), $worker->idleDeadline($agentId)];
            TruthSourceRegistry::checkCanWrite($ownedSource, TruthSourceOperation::Update);
            $observed[] = RtTruthSourceRegistry::currentWriteGrant($ownedSource)->allows(TruthSourceOperation::Update);
        };

        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$sr = $masterRouter;
            $rt = $this->handoffRuntime($client->acceptKey);
            $rt->connectionsSource()->add(RosterConnection::create($other->acceptKey, 7));
            Hilos::$rt = $rt;
            $this->applyHandoffIdentity($this->master, $client->acceptKey);
            $request = $this->subscribe($client, $page);
            $this->worker->requests[] = new DaemonAgentMessageDTO($agentId, $request);
            if ($waiting) {
                $rt->connectionsSource()->get($client->acceptKey)->applyDiff([StateHilosConnection::userId => 8]);
                $worker->deliver(array_pop($this->worker->requests));
                self::assertNull($worker->router->pageSubscription($client->acceptKey));
                $consumer = SourceConsumer::pageHandoff($request->data->deliveryId);
            } else {
                $this->admitLocalHandoffWorker($worker);
                $consumer = SourceConsumer::page($client->acceptKey);
            }
            SourceInterestRegistry::register(SourceChange::KIND_DB, $failingSource, $consumer);
            $otherRequest = $this->subscribe($other, $page);
            $this->worker->requests[] = new DaemonAgentMessageDTO($agentId, $otherRequest);
            $this->admitLocalHandoffWorker($worker);
            Hilos::$db = $db;
            self::assertNull($worker->idleDeadline($agentId));

            $worker->deliver(new AgentStopDTO($agentId));
            self::assertSame($waiting ? [$other->acceptKey] : [$client->acceptKey, $other->acceptKey], $released);
            self::assertNull($worker->router->pageSubscription($client->acceptKey));
            self::assertNull($worker->router->pageSubscription($other->acceptKey));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));
            self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($other->acceptKey), SourceChange::KIND_RT));
            self::assertSame($agentId, $observed[0][0]);
            self::assertNotNull($observed[0][1]);
            self::assertTrue($observed[1]);
            self::assertFalse(TruthSourceRegistry::hasCreateSource($ownedSource));
            self::assertSame([], RtTruthSourceRegistry::collectionsOf($agentId));
        } finally {
            SourceInterestRegistry::releaseConsumer($consumer ?? SourceConsumer::page($client->acceptKey));
            TruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            Hilos::$db = $previousDb;
            Hilos::$rt = $previousRt;
            Hilos::$sr = $masterRouter;
            ExecutionContext::clear();
        }
    }

    /** @return iterable<string, array{?string, bool, ?string}> Browser and placement changes along the relay */
    public static function handoffProofLifetimes(): iterable
    {
        yield 'current' => [null, false, null];
        yield 'confirmed relay reaches the socket before the placement view' => [null, true, null];
        yield 'left the page' => ['leave', false, null];
        yield 'socket closed' => ['disconnect', false, null];
        yield 'same page and same person reopened' => ['reopen', false, null];
        yield 'old probe before and after explicit adoption' => [null, true, 'probe'];
        yield 'old relay expires before a new view' => [null, true, 'expiry'];
        yield 'grant outlives the serving node authority' => [null, false, 'grant'];
        yield 'relay lost before the first probe' => [null, true, 'before probe loss'];
        yield 'relay lost after its address is recorded' => [null, true, 'after probe loss'];
        yield 'socket expiry reaches the stopped historical holder' => [null, false, 'historical expiry'];
        yield 'reassess reaches the stopped historical holder' => [null, false, 'historical reassess'];
        yield 'reassess releases the completed subscription at its historical holder' => [null, false, 'historical completed'];
    }

    public function testLateOrdinaryReleasePreservesTheReplacementOnTheSameWorker(): void
    {
        $masterRouter = Hilos::$sr;
        $ordinary = new DeliveryFlowAgent();
        $other = new PerInstanceIdentityTestAgent('7');
        $worker = new PerInstanceIdentityTestWorker(3, $ordinary);
        $worker->addFixtureAgent($other);
        $acceptKey = 'shared-worker-connection';
        $consumer = SourceConsumer::page($acceptKey);
        try {
            $currentPage = 'replacement';
            $worker->router->subscribeToPage($currentPage, new WebSocketPageSubscribeSignalDTO($acceptKey, $currentPage));
            $worker->router->bindPageAgent($acceptKey, $currentPage, $other->getType(), $other->getIndex());
            $mirror = $worker->router->pageSubscription($acceptKey);
            SourceInterestRegistry::register(SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections, $consumer);
            $worker->deliver(new DaemonAgentMessageDTO($ordinary->getId(), new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                new SignalName(DeliveryFlowPage::PAGE),
                new WebSocketPageUnsubscribeSignalDTO($acceptKey),
            )));
            self::assertSame(0, $ordinary->pageReleases);
            self::assertSame($mirror, $worker->router->pageSubscription($acceptKey));
            self::assertSame([DeliveryFlowSessionRuntime::connections],
                SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
        } finally {
            SourceInterestRegistry::releaseConsumer($consumer);
            Hilos::$sr = $masterRouter;
        }
    }

    public function testLateReleaseOnSharedWorkerPreservesTheReopenedGuestMirror(): void
    {
        $previousRt = Hilos::$rt;
        $app = new ReflectionProperty(Hilos::class, 'appClass');
        $previousApp = Hilos::appClass();
        $app->setValue(null, DeliveryFlowHandoffHilos::class);
        [$client] = $this->connect();
        $page = PerInstancePublicIdentityPage::PAGE;
        $guest = PerInstanceFallbackAgent::AGENT_TYPE;
        $account = PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE . ':7';
        $acceptKey = $client->acceptKey;
        $consumer = SourceConsumer::page($acceptKey);
        $masterRouter = Hilos::$sr;
        try {
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = $this->handoffRuntime($acceptKey);
            $guestAgent = new DeliveryFlowGuestAgent();
            $worker = new PerInstanceIdentityTestWorker(1, $guestAgent);
            $accountAgent = new PerInstanceIdentityTestAgent('7');
            $hookOrder = [];
            $guestAgent->onRelease = static function () use (&$hookOrder): void {
                $hookOrder[] = ['agent release', ExecutionContext::currentAgentId()];
            };
            $accountAgent->onSubscribe = static function () use (&$hookOrder): void {
                $hookOrder[] = ['account subscribe', ExecutionContext::currentAgentId()];
            };
            $worker->onPageRelease = static function () use (&$hookOrder): void {
                $hookOrder[] = ['page release', ExecutionContext::currentAgentId()];
            };
            $worker->addFixtureAgent($accountAgent);
            Hilos::$sr = $masterRouter;
            $initial = $this->subscribe($client, $page);
            $this->worker->requests[] = new DaemonAgentMessageDTO($guest, $initial);
            $this->admitLocalHandoffWorker($worker);
            self::assertNull($worker->idleDeadline($guest));
            self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));

            $this->applyHandoffIdentity($this->master, $acceptKey);
            $this->master->drain();
            self::assertCount(2, $this->worker->requests);
            $signIn = array_pop($this->worker->requests);
            $lateRelease = array_pop($this->worker->requests);
            self::assertSame($guest, $lateRelease->agentId);
            self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $lateRelease->signal->signalType->getType());
            self::assertSame($initial->data->deliveryId, $lateRelease->signal->meta[WebSocketPageSubscribeSignalDTO::DELIVERY_ID]);
            self::assertSame([], $this->heldAgentFrames());
            $this->worker->requests[] = $signIn;
            self::assertSame($initial->data->deliveryId, $worker->router->pageSubscription($acceptKey)?->deliveryId);
            $this->admitLocalHandoffWorker($worker);
            self::assertSame('7', $worker->router->pageSubscription($acceptKey)?->agentIndex);
            self::assertNotNull($worker->idleDeadline($guest));
            self::assertNull($worker->idleDeadline($account));
            $expectedHooks = [['agent release', $guest], ['page release', $guest], ['account subscribe', $account]];
            self::assertSame($expectedHooks, $hookOrder);

            $this->applyHandoffIdentity($this->master, $acceptKey, null);
            $this->master->drain();
            $logout = array_pop($this->worker->requests);
            $worker->deliver(array_pop($this->worker->requests));
            $this->worker->requests[] = $logout;
            $this->admitLocalHandoffWorker($worker);
            $reopened = $this->subscribe($client, $page);
            $this->worker->requests[] = new DaemonAgentMessageDTO($guest, $reopened);
            $worker->deliver(array_pop($this->worker->requests));
            $this->queueServingWorkerReply($this->master, $worker->router->getNextQueuedSignal());
            $worker->deliver(array_pop($this->worker->requests));
            $mirror = $worker->router->pageSubscription($acceptKey);
            self::assertSame($reopened->data->deliveryId, $mirror?->deliveryId);
            self::assertTrue($client->isDeliveryPending($page, $reopened->data->deliveryId));
            $beforeLateRelease = $hookOrder;
            $worker->deliver($lateRelease);
            self::assertSame($beforeLateRelease, $hookOrder);
            self::assertSame($mirror, $worker->router->pageSubscription($acceptKey));
            self::assertNull($worker->idleDeadline($guest));
            self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
            $agents = $this->master->getAgentManagerDaemon();
            $agents->stopped[$guest] = true;
            $this->worker->awaiting = true;
            $this->master->deliverSignalToAgent($guest, null, $reopened);
            $pending = $this->pending()[$acceptKey][$reopened->data->deliveryId];
            $holds = SignalDispatcherFixture::owner($this->master)->agentSignalHolds();
            $newHold = $holds->pageDeliveryHold($pending);
            self::assertNotNull($newHold);
            $startRequests = $this->worker->startRequests;
            $this->master->deliverSignalToAgent($guest, null, $lateRelease->signal);
            $this->master->drain();
            self::assertSame($pending, $this->pending()[$acceptKey][$reopened->data->deliveryId]);
            self::assertSame($newHold, $holds->pageDeliveryHold($pending));
            self::assertSame([$newHold->parked], $this->heldAgentFrames());
            self::assertSame($startRequests, $this->worker->startRequests);
        } finally {
            $app->setValue(null, $previousApp);
            Hilos::$sr = $masterRouter;
            Hilos::$rt = $previousRt;
        }
    }

    private function admitLocalHandoffWorker(PerInstanceIdentityTestWorker $worker): void
    {
        $worker->deliver(array_pop($this->worker->requests));
        $probe = $worker->router->getNextQueuedSignal();
        self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
        $this->queueServingWorkerReply($this->master, $probe);
        $proof = array_pop($this->worker->requests);
        self::assertSame(PageHandoffIdentity::GRANTED, $proof?->signal->meta[PageHandoffIdentity::META_PROOF]);
        $worker->deliver($proof);
        $this->flushLocalHandoffWorker($worker);
    }

    private function flushLocalHandoffWorker(PerInstanceIdentityTestWorker $worker): void
    {
        self::assertSame(SignalTypeConstants::RT_STALENESS, $worker->router->getNextQueuedSignal()?->signalName->getName());
        $answer = $worker->router->getNextQueuedSignal();
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer?->signalName->getName());
        $this->queueServingWorkerReply($this->master, $answer);
        $complete = $worker->router->getNextQueuedSignal();
        self::assertSame(SignalConstants::DELIVERY_COMPLETE, $complete?->signalName->getName());
        $this->queueServingWorkerReply($this->master, $complete);
        self::assertNull($worker->router->getNextQueuedSignal());
    }

    #[DataProvider('handoffProofLifetimes')]
    public function testRelayedHandoffAsksTheSocketAfterTheServingWorkersIdentityArrives(
        ?string $replacement, bool $placementInFlight, ?string $authorityEnds,
    ): void {
        $previousRt = Hilos::$rt;
        $previousCluster = Hilos::$cluster;
        $app = new ReflectionProperty(Hilos::class, 'appClass');
        $previousApp = Hilos::appClass();
        $app->setValue(null, DeliveryFlowRelayHilos::class);
        [$client, $socket] = $this->connect();
        $page = PerInstancePublicIdentityPage::PAGE;
        $type = PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE;
        $acceptKey = $client->acceptKey;
        Hilos::$browser = new PerInstanceRosterIdentityBrowser();
        $socketRouter = Hilos::$sr;
        [$outbound, $socketLink, $replies] = $this->connectPeer('socket', 'serving');
        [, $socketRelayLink, $relayReplies] = $this->connectPeer('socket', 'relay', $outbound);
        [$inbound, $servingLink, $probes] = $this->connectPeer('serving', 'socket');
        [$relayInbound, $relayInboundLink, $relayInboundReader] = $this->connectPeer('serving', 'relay');
        $historical = in_array($authorityEnds, ['historical expiry', 'historical reassess', 'historical completed'], true);
        $workerLink = null;
        $workerReader = null;
        $replacementLink = null;
        $replacementReader = null;
        try {
            $socketRt = $this->handoffRuntime($acceptKey);
            $workerRt = $this->handoffRuntime($acceptKey);
            Hilos::$rt = $socketRt;
            $this->master->registerServer($outbound);
            $socketCluster = new ClusterContext();
            Hilos::$cluster = $socketCluster;
            $socketCluster->registerClientSignalSink($this->master);
            $socketLeadership = new SnapshotTestLeadership('socket', 1);
            $socketCluster->registerLeadership($socketLeadership);
            $socketPlacement = new ClusterPlacement(
                'socket', $this->createStub(PlacementMesh::class), $this->createStub(PlacementExecutor::class),
            );
            $socketCluster->registerPlacement($socketPlacement);
            $socketCluster->registerWorkerPlacement($socketPlacement);
            $this->announceHandoffPlacement($socketPlacement, $type, 'relay', 1);

            $this->subscribe($client, $page);
            $fact = $this->applyHandoffIdentity($this->master, $acceptKey);
            $this->master->drain();
            [$handoff] = $this->takePeerFrames($socketRelayLink, $relayReplies, 1);
            self::assertInstanceOf(PeerSignalDTO::class, $handoff);
            self::assertSame(SignalTypeConstants::PAGE_ACCESS_REASSESS, $handoff->signal->signalType->getType());
            if ($replacement === 'disconnect') {
                $client->close();
            } elseif ($replacement !== null) {
                $client->acceptJson(json_encode(['type' => 'page_unsubscribe', 'page' => $page], JSON_THROW_ON_ERROR));
                $this->master->drain();
                if ($replacement === 'reopen') {
                    $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => $page], JSON_THROW_ON_ERROR));
                    $this->master->drain();
                }
                $this->takePeerFrames($socketRelayLink, $relayReplies, $replacement === 'reopen' ? 2 : 1);
            }

            $relay = $this->relayHandoffAttempt($handoff->signal);
            $serving = new DeliveryFlowMaster();
            $servingRouter = Hilos::$sr;
            $agents = $serving->getAgentManagerDaemon();
            $workerServer = new DeliveryFlowWorkerServer($agents);
            $serving->registerServer($workerServer);
            $serving->registerServer($inbound);
            $agent = new PerInstanceIdentityTestAgent('7');
            $worker = new PerInstanceIdentityTestWorker(2, $agent);
            Hilos::$sr = $servingRouter;
            Hilos::$rt = $workerRt;
            Hilos::$cluster = new ClusterContext();
            $servingLeadership = new SnapshotTestLeadership('socket', 1);
            Hilos::$cluster->registerLeadership($servingLeadership);
            $servingPlacement = $this->mountHandoffPlacement('serving', $type, '7', new PlacementId('socket', 1, 2));
            Hilos::$cluster->registerAgentSignalSink($serving);
            $locations = new ClusterClientLocation('serving');
            $locations->applySnapshot('socket', [$acceptKey]);
            Hilos::$cluster->registerClientLocation($locations);
            if ($historical) {
                $neighbor = new PerInstanceIdentityTestAgent('8');
                $worker->addFixtureAgent($neighbor);
                [$workerLink, $workerReader] = $this->connectSharedWorker($workerServer, $agents, $agent, $neighbor);
                $neighborKey = $acceptKey . '-neighbor';
                $neighborConsumer = SourceConsumer::page($neighborKey);
                $workerRt->connectionsSource()->add(RosterConnection::create($neighborKey, 8));
                $worker->router->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($neighborKey, $page));
                $worker->router->bindPageAgent($neighborKey, $page, $type, $neighbor->getIndex());
                $neighborMirror = $worker->router->pageSubscription($neighborKey);
                SourceInterestRegistry::register(SourceChange::KIND_RT, RosterRtContext::connections, $neighborConsumer);
                $hookOrder = [];
                $agent->onRelease = static function () use (&$hookOrder): void {
                    $hookOrder[] = ['agent release', ExecutionContext::currentAgentId()];
                };
                $worker->onPageRelease = static function () use (&$hookOrder): void {
                    $hookOrder[] = ['page release', ExecutionContext::currentAgentId()];
                };
                $agent->onStopping = static function () use (&$hookOrder): void {
                    $hookOrder[] = ['agent stop', ExecutionContext::currentAgentId()];
                };
            }
            self::assertSame($type, $relay->agentType);
            $relayInbound->onSignalReceived($relayInboundLink, $relay);
            $serving->drain();
            self::assertCount(1, $workerServer->requests);
            $worker->deliver(array_pop($workerServer->requests));
            self::assertNull($worker->router->getNextQueuedSignal());
            self::assertNull($worker->router->pageSubscription($acceptKey));
            if ($authorityEnds === 'before probe loss') {
                $token = $handoff->signal->data->deliveryId;
                Hilos::$sr = $socketRouter;
                Hilos::$rt = $socketRt;
                Hilos::$cluster = $socketCluster;
                $socketLink->close();
                $this->master->noteNodeUnreachable('serving', microtime(true));
                $this->master->drain();
                self::assertFalse($outbound->hasActiveLinkToNode('serving'));
                self::assertTrue($outbound->hasActiveLinkToNode('relay'));
                self::assertSame('relay', $this->pending()[$acceptKey][$token]->nodeId);
                $client->sendFrame('{"type":"neighbor","data":1}');
                $client->write();
                self::assertSame([], $this->read($socket));
                $client->now += (int) (2 * AgentConstants::START_DEADLINE_SECONDS * 1000);
                $client->onTick();
                self::assertFalse($client->isDeliveryPending($page, $token));
                self::assertSame([], $this->pending());

                $this->deliver($this->addressedPageReply($handoff->signal->data, false, 'late success'), $client);
                $client->write();
                [$frame] = $this->read($socket);
                self::assertSame(['neighbor', SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frame['data']['frames'], 'type'));
                self::assertSame(503, $frame['data']['frames'][1]['data']['httpCode']);
                $client->onTick();
                $client->write();
                self::assertSame([], $this->read($socket));

                Hilos::$sr = $servingRouter;
                Hilos::$rt = $workerRt;
                $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
                self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::pageHandoff($token), SourceChange::KIND_RT));
                return;
            }
            $worker->applyConnectionUpdate($fact);
            $probe = $worker->router->getNextQueuedSignal();
            self::assertSame(PageHandoffIdentity::PROBE, $probe?->meta[PageHandoffIdentity::META_PROOF]);
            $this->queueServingWorkerReply($serving, $probe);
            [$query] = $this->takePeerFrames($servingLink, $probes, 1);
            self::assertInstanceOf(PeerClientSignalDTO::class, $query);
            self::assertNull($worker->router->pageSubscription($acceptKey));

            $servingCluster = Hilos::$cluster;
            Hilos::$sr = $socketRouter;
            Hilos::$rt = $socketRt;
            Hilos::$cluster = $socketCluster;
            if (!$placementInFlight) {
                $this->announceHandoffPlacement($socketPlacement, $type, 'serving', 2);
            }
            if ($replacement === null && !$placementInFlight) {
                $token = $handoff->signal->data->deliveryId;
                $original = $this->pending()[$acceptKey][$token];
                self::assertSame('relay', $original->nodeId);
                $meta = $query->signal->meta;
                unset($meta[PageHandoffIdentity::META_RELAY_FROM]);
                $meta[PageHandoffIdentity::META_CHALLENGE] = str_repeat('a', PageHandoffIdentity::CHALLENGE_BYTES * 2);
                $outbound->onClientSignalReceived($socketRelayLink, new PeerClientSignalDTO(
                    'relay',
                    'socket',
                    $acceptKey,
                    new SignalDTO($query->signal->signalSource, $query->signal->signalType, $query->signal->signalName, $query->signal->data, $meta),
                ));
                $this->master->drain();
                [$staleProof] = $this->takePeerFrames($socketRelayLink, $relayReplies, 1);
                self::assertSame(PageHandoffIdentity::REFUSED, $staleProof->signal->meta[PageHandoffIdentity::META_PROOF]);
                self::assertSame($original, $this->pending()[$acceptKey][$token]);
            }
            if ($authorityEnds === 'probe' || $authorityEnds === 'expiry') {
                $token = $handoff->signal->data->deliveryId;
                $original = $this->pending()[$acceptKey][$token];
                $this->announceHandoffPlacement($socketPlacement, $type, 'serving', 2);
                $socketPlacement->onPlacementQuery('next', new PeerPlacementQueryDTO('new-leader', 2));
                $outbound->onClientSignalReceived($socketLink, $query);
                $this->master->drain();
                [$denied] = $this->takePeerFrames($socketLink, $replies, 1);
                self::assertSame(PageHandoffIdentity::REFUSED, $denied->signal->meta[PageHandoffIdentity::META_PROOF]);
                self::assertSame($original, $this->pending()[$acceptKey][$token]);
                self::assertTrue($client->isDeliveryPending($page, $token));

                if ($authorityEnds === 'expiry') {
                    Hilos::$cluster = $servingCluster;
                    Hilos::$sr = $servingRouter;
                    Hilos::$rt = $workerRt;
                    $inbound->onSignalReceived($servingLink, $denied);
                    $serving->drain();
                    $worker->deliver(array_pop($workerServer->requests));
                    $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
                    $this->queueServingWorkerReply($serving, $worker->router->getNextQueuedSignal());
                    [$expired] = $this->takePeerFrames($servingLink, $probes, 1);
                    self::assertNull($worker->router->pageSubscription($acceptKey));

                    Hilos::$cluster = $socketCluster;
                    Hilos::$sr = $socketRouter;
                    Hilos::$rt = $socketRt;
                    $outbound->onClientSignalReceived($socketLink, $expired);
                    $this->master->drain();
                    self::assertFalse($client->isDeliveryPending($page, $token));
                    self::assertArrayNotHasKey($token, $this->pending()[$acceptKey] ?? []);
                    $client->write();
                    self::assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($this->read($socket), 'type'));
                    return;
                }

                $this->announceHandoffPlacement($socketPlacement, $type, 'serving', 2, 'next', 2);
                $outbound->onClientSignalReceived($socketLink, $query);
                $this->master->drain();
                [$unconfirmed] = $this->takePeerFrames($socketLink, $replies, 1);
                self::assertSame(PageHandoffIdentity::REFUSED, $unconfirmed->signal->meta[PageHandoffIdentity::META_PROOF]);
                $socketLeadership->leader = 'next';
                $socketLeadership->currentTerm = 2;
                $this->announceHandoffPlacement($socketPlacement, $type, 'serving', 2, 'next', 2);
            }
            $outbound->onClientSignalReceived($socketLink, $query);
            $this->master->drain();
            [$proof] = $this->takePeerFrames($socketLink, $replies, 1);
            self::assertInstanceOf(PeerSignalDTO::class, $proof);
            self::assertSame($replacement === null ? PageHandoffIdentity::GRANTED : PageHandoffIdentity::REFUSED,
                $proof->signal->meta[PageHandoffIdentity::META_PROOF]);
            if ($replacement === null) {
                self::assertSame('serving', $this->pending()[$acceptKey][$handoff->signal->data->deliveryId]->nodeId);
            }
            if ($authorityEnds === 'after probe loss') {
                $this->master->noteNodeUnreachable('serving', microtime(true));
                $this->master->drain();
                self::assertFalse($client->isDeliveryPending($page, $handoff->signal->data->deliveryId));
                self::assertSame([], $this->pending());
                $client->write();
                self::assertSame([['type' => 'page_resubscribe', 'data' => ['page' => $page, 'params' => []]]], $this->read($socket));
                return;
            }

            Hilos::$cluster = $servingCluster;
            Hilos::$sr = $servingRouter;
            Hilos::$rt = $workerRt;
            if ($replacement === null) {
                foreach (['socket', 'relay'] as $claimedOrigin) {
                    $relayInbound->onSignalReceived($relayInboundLink, new PeerSignalDTO(
                        $claimedOrigin, 'serving', $type, '7', $proof->signal,
                    ));
                    $serving->drain();
                    self::assertSame([], $workerServer->requests);
                    self::assertNull($worker->router->pageSubscription($acceptKey));
                }
            }
            if ($authorityEnds === 'grant') {
                $servingPlacement->onPlacementQuery('next', new PeerPlacementQueryDTO('new-leader', 2));
            } elseif ($authorityEnds === 'probe') {
                $servingLeadership->leader = 'next';
                $servingLeadership->currentTerm = 2;
                $servingPlacement->onPlacementQuery('next', new PeerPlacementQueryDTO('adopt', 2));
                $this->announceHandoffPlacement($servingPlacement, $type, 'serving', 2, 'next', 2);
            }
            $inbound->onSignalReceived($servingLink, $proof);
            $serving->drain();
            if ($authorityEnds === 'grant') {
                self::assertSame([], $workerServer->requests);
                self::assertNull($worker->router->pageSubscription($acceptKey));
                return;
            }
            self::assertCount(1, $workerServer->requests);
            $worker->deliver(array_pop($workerServer->requests));
            if ($replacement !== null) {
                self::assertNull($worker->router->pageSubscription($acceptKey));
                self::assertNull($worker->router->getNextQueuedSignal());
                return;
            }

            self::assertSame($handoff->signal->data->deliveryId, $worker->router->pageSubscription($acceptKey)?->deliveryId);
            self::assertSame(SignalTypeConstants::RT_STALENESS, $worker->router->getNextQueuedSignal()?->signalName->getName());
            $answer = $worker->router->getNextQueuedSignal();
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer?->signalName->getName());
            $this->queueServingWorkerReply($serving, $answer);
            $complete = $worker->router->getNextQueuedSignal();
            self::assertSame(SignalConstants::DELIVERY_COMPLETE, $complete?->signalName->getName());
            $this->queueServingWorkerReply($serving, $complete);
            [$reply, $finished] = $this->takePeerFrames($servingLink, $probes, 2);
            self::assertInstanceOf(PeerClientSignalDTO::class, $reply);
            self::assertInstanceOf(PeerClientSignalDTO::class, $finished);
            self::assertSame([], $this->pending($serving));
            $token = $handoff->signal->data->deliveryId;
            if ($historical) {
                $agentId = $agent->getId();
                $consumer = SourceConsumer::page($acceptKey);
                self::assertSame([RosterRtContext::connections], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
                self::assertNull($worker->idleDeadline($agentId));
                $order = new PlacementId('socket', 1, 2);
                $servingPlacement->beginLocalPlacementRecovery($agentId, $order);
                $servingPlacement->suspendLocalPlacement($agentId, $order);
                $workerServer->revokePlacement($type, $agent->getIndex(), immediateStartSink: $serving);
                self::assertFalse($agents->isAgentStarted($agentId));
                $stop = WorkerDTO::factoryWorkerDTO(trim($this->drainWorkerBytes($workerLink, $workerReader)));
                self::assertInstanceOf(AgentStopDTO::class, $stop);
                self::assertSame($agentId, $stop->agentId);
                $worker->deliver($stop);
                $serving->drain();
                self::assertNotNull($servingPlacement->placementRecovery($agentId));
                self::assertSame([], $this->pending($serving));
            }

            Hilos::$sr = $socketRouter;
            Hilos::$rt = $socketRt;
            if ($historical) {
                Hilos::$cluster = $socketCluster;
                if ($authorityEnds === 'historical completed') {
                    $outbound->onClientSignalReceived($socketLink, $reply);
                    $outbound->onClientSignalReceived($socketLink, $finished);
                    $this->master->drain();
                    self::assertSame([], $this->pending());
                    $client->write();
                    self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->read($socket), 'type'));
                } else {
                    self::assertTrue($client->isDeliveryPending($page, $token));
                    self::assertSame('serving', $this->pending()[$acceptKey][$token]->nodeId);
                }
                $this->announceHandoffPlacement($socketPlacement, $type, 'next-owner', 3);
                self::assertSame('next-owner', $socketPlacement->locate($type, '7')->nodeId);
                if ($authorityEnds === 'historical expiry') {
                    $client->sendFrame('{"type":"neighbor","data":1}');
                    $client->now += (int) (2 * AgentConstants::START_DEADLINE_SECONDS * 1000);
                    $client->onTick();
                    self::assertFalse($client->isDeliveryPending($page, $token));
                } else {
                    $this->applyHandoffIdentity($this->master, $acceptKey, null);
                    $this->master->drain();
                    self::assertNotSame($token, Hilos::$sr->pageSubscription($acceptKey)?->deliveryId);
                }
                self::assertTrue($socketLink->hasPendingWrite(), 'The release must use the historical peer link');
                [$release] = $this->takePeerFrames($socketLink, $replies, 1);
                self::assertInstanceOf(PeerSignalDTO::class, $release);
                self::assertSame('serving', $release->targetNodeId);
                self::assertSame($type, $release->agentType);
                self::assertSame('7', $release->agentIndex);
                self::assertSame($acceptKey, $release->signal->data->acceptKey);
                self::assertSame($page, $release->signal->signalName->getName());
                self::assertSame(SignalTypeConstants::PAGE_UNSUBSCRIBE, $release->signal->signalType->getType());
                self::assertSame($token, $release->signal->meta[WebSocketPageSubscribeSignalDTO::DELIVERY_ID]);
                if ($authorityEnds === 'historical expiry') {
                    $this->assertSocketHandoffExpiry($client, $socket, $page, $token);
                    [, $replacementLink, $replacementReader] = $this->connectPeer('socket', 'next-owner', $outbound);
                    $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => $page], JSON_THROW_ON_ERROR));
                    $this->master->drain();
                }
                $replacementSubscription = $socketRouter->pageSubscription($acceptKey);
                self::assertNotNull($replacementSubscription?->deliveryId);
                self::assertNotSame($token, $replacementSubscription->deliveryId);
                $replacementPending = $this->pending()[$acceptKey][$replacementSubscription->deliveryId];
                Hilos::$sr = $servingRouter;
                Hilos::$rt = $workerRt;
                Hilos::$cluster = $servingCluster;
                self::assertTrue($inbound->hasActiveLinkToNode('socket'));
                [, $nextLink, $nextReader] = $this->connectPeer('serving', 'next-owner', $inbound);
                try {
                    $inbound->onSignalReceived($servingLink, $release);
                    $serving->drain();
                    $held = $this->heldAgentFrames($serving);
                    $this->announceHandoffPlacement($servingPlacement, $type, 'next-owner', 3);
                    self::assertSame('next-owner', $servingPlacement->locate($type, '7')->nodeId);
                    $inbound->onSignalReceived($servingLink, $release);
                    $serving->drain();
                    $nextLink->write();
                    self::assertSame('', $this->readBytes($nextReader), 'The next worker must receive no release frame');
                    self::assertSame([], $held, 'The historical master must create no recovery hold');
                    self::assertSame([], $this->heldAgentFrames($serving, released: true));
                    self::assertSame(0, $workerServer->startRequests);
                    self::assertSame([], $workerServer->requests);
                    self::assertSame('', $this->drainWorkerBytes($workerLink, $workerReader));
                    self::assertSame([], $this->pending($serving));
                    self::assertNull($worker->router->pageSubscription($acceptKey));
                    self::assertSame([], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_RT));
                    self::assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::pageHandoff($token), SourceChange::KIND_RT));
                    self::assertSame([['agent release', $agentId], ['page release', $agentId], ['agent stop', $agentId]], $hookOrder);
                    self::assertSame($neighborMirror, $worker->router->pageSubscription($neighborKey));
                    self::assertSame([RosterRtContext::connections],
                        SourceInterestRegistry::collectionsOfConsumer($neighborConsumer, SourceChange::KIND_RT));
                    $neighborCalls = 0;
                    $neighbor->onSubscribe = static function () use ($neighborKey, &$neighborCalls): void {
                        self::assertSame(8, PageHandoffIdentity::current($neighborKey)?->userId);
                        ++$neighborCalls;
                    };
                    $workerLink->sendInTurn(new DaemonAgentMessageDTO($neighbor->getId(), new SignalDTO(
                        new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                        new SignalName($page), new WebSocketPageSubscribeSignalDTO($neighborKey, $page),
                    ))->toJson(), $neighbor->getId());
                    $worker->deliver(WorkerDTO::factoryWorkerDTO(trim($this->drainWorkerBytes($workerLink, $workerReader))));
                    self::assertSame(1, $neighborCalls);

                    Hilos::$sr = $socketRouter;
                    Hilos::$rt = $socketRt;
                    Hilos::$cluster = $socketCluster;
                    $outbound->onClientSignalReceived($socketLink, $reply);
                    $outbound->onClientSignalReceived($socketLink, $finished);
                    $this->master->drain();
                    self::assertSame($replacementSubscription, $socketRouter->pageSubscription($acceptKey));
                    self::assertSame($replacementPending, $this->pending()[$acceptKey][$replacementSubscription->deliveryId]);
                    self::assertTrue($client->isDeliveryPending($page, $replacementSubscription->deliveryId));
                    $client->write();
                    self::assertSame([], $this->read($socket));
                } finally {
                    $nextLink->close();
                    socket_close($nextReader);
                }
                return;
            }
            $this->deliver($reply->signal, $client, peer: true);
            $client->write();
            self::assertSame([], $this->read($socket));
            $this->deliver($finished->signal, $client, peer: true);
            $client->write();
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->read($socket), 'type'));
            $client->acceptJson(json_encode(['type' => 'page_subscribe', 'page' => $page], JSON_THROW_ON_ERROR));
            $this->deliver($reply->signal, $client, peer: true);
            $client->write();
            self::assertSame([], $this->read($socket));
        } finally {
            if (isset($neighborConsumer)) {
                SourceInterestRegistry::releaseConsumer($neighborConsumer);
            }
            $replacementLink?->close();
            if ($replacementReader !== null) {
                socket_close($replacementReader);
            }
            $workerLink?->close();
            if ($workerReader !== null) {
                socket_close($workerReader);
            }
            $socketLink->close();
            $socketRelayLink->close();
            $servingLink->close();
            $relayInboundLink->close();
            socket_close($replies);
            socket_close($relayReplies);
            socket_close($probes);
            socket_close($relayInboundReader);
            Hilos::$sr = $socketRouter;
            Hilos::$rt = $previousRt;
            Hilos::$cluster = $previousCluster;
            $app->setValue(null, $previousApp);
        }
    }

    /** @return iterable<string, array{string, bool, bool}> Placement and attempt changes around the IPC write */
    public static function grantWriteBoundaries(): iterable
    {
        yield 'policy order lost in the RT stream' => ['policy loss', false, false];
        yield 'policy order lost in the IPC output buffer' => ['policy loss', true, false];
        yield 'policy order lost after the IPC line starts' => ['policy loss', true, true];
        yield 'serving attempt gone before peer ingress' => ['expired at ingress', true, false];
        yield 'serving attempt gone after the RT turn' => ['expired after turn', true, false];
        yield 'serving attempt and order survive until write' => ['policy current', true, false];
        yield 'fallback leader lost after the RT turn' => ['leader loss', true, false];
        yield 'fallback leader survives until write' => ['leader current', true, false];
    }

    /**
     * @param string $ending Which serving fact changes before the write
     * @param bool $released Whether the grant has passed the RT turn when the fact changes
     * @param bool $started Whether part of the IPC line has been written
     */
    #[DataProvider('grantWriteBoundaries')]
    public function testPageGrantRechecksPlacementWhenItsWorkerIpcTurnArrives(string $ending, bool $released, bool $started): void
    {
        $previousRt = Hilos::$rt;
        $previousCluster = Hilos::$cluster;
        $previousRouter = Hilos::$sr;
        $appClass = new ReflectionProperty(Hilos::class, 'appClass');
        $previousApp = Hilos::appClass();
        $appClass->setValue(null, DeliveryFlowRelayHilos::class);
        $master = new DeliveryFlowMaster();
        $agents = $master->getAgentManagerDaemon();
        $workers = new class ($agents) extends WorkerServer {
            public function __construct(AgentManagerDaemon $agents)
            {
                new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $agents);
            }

            protected function onStart(): void
            {
            }
        };
        $master->registerServer($workers);
        [$peer, $peerLink, $peerReader] = $this->connectPeer('serving', 'socket');
        $master->registerServer($peer);

        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        self::assertTrue(socket_set_nonblock($pair[0]));
        self::assertTrue(socket_set_nonblock($pair[1]));
        self::assertTrue(socket_set_option($pair[0], SOL_SOCKET, SO_SNDBUF, 4096));
        $link = new WorkerClient($pair[0], $agents, $workers);
        $stream = new RtSyncStreamChannel(static function (): void {}, static function (): void {}, static function (): void {});
        new ReflectionProperty(WorkerClient::class, 'rtSyncStream')->setValue($link, $stream);
        try {
            Hilos::$cluster = new ClusterContext();
            $leaderFallback = in_array($ending, ['leader loss', 'leader current'], true);
            $leadership = new SnapshotTestLeadership($leaderFallback ? 'serving' : 'socket', 1);
            Hilos::$cluster->registerLeadership($leadership);
            Hilos::$cluster->registerAgentSignalSink($master);
            $agent = $leaderFallback ? new DeliveryFlowGuestAgent() : new PerInstanceIdentityTestAgent('7');
            $type = $agent->getType();
            $index = $agent->getIndex();
            $agentId = $agent->getId();
            $order = $leaderFallback ? null : new PlacementId('socket', 1, 2);
            if ($order === null) {
                $placement = new ClusterPlacement('serving', $this->createStub(PlacementMesh::class), $this->createStub(PlacementExecutor::class));
            } else {
                $placement = $this->mountHandoffPlacement('serving', $type, $index, $order);
                $this->announceHandoffPlacement($placement, $type, 'serving', 2);
                self::assertEquals($order, $placement->confirmedLocalWorkerOrder($agentId));
            }
            Hilos::$cluster->registerWorkerPlacement($placement);

            $daemon = new class ($type, $index) extends AbstractAgentDaemon {
                public function __construct(private readonly string $type, ?string $index)
                {
                    $this->agentIndex = $index;
                }

                public function getType(): string
                {
                    return $this->type;
                }

                public function requiresMonopolisticProcess(): bool
                {
                    return false;
                }
            };
            $daemon->setWorkerClient($link);
            $agents->addAgent($agentId, $daemon, 1, false);
            new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($link, 1);
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($workers, $link);

            $page = PerInstancePublicIdentityPage::PAGE;
            $params = $started ? ['padding' => str_repeat('x', 65536)] : [];
            $request = new WebSocketPageSubscribeSignalDTO('queued-grant', $page, $params, deliveryId: 'queued-grant:1');
            $identity = new PageHandoffIdentity(DeliveryFlowSessionRuntime::connections, $leaderFallback ? null : 7, 101);
            $locations = new ClusterClientLocation('serving');
            $locations->applySnapshot('socket', [$request->acceptKey]);
            Hilos::$cluster->registerClientLocation($locations);
            $router = Hilos::$sr;
            $calls = 0;
            $agent->onSubscribe = static function () use (&$calls): void {
                ++$calls;
            };
            $worker = new PerInstanceIdentityTestWorker(1, $agent);
            Hilos::$sr = $router;
            Hilos::$browser = new PerInstanceRosterIdentityBrowser();
            Hilos::$rt = new DeliveryFlowSessionRuntime();
            Hilos::$rt->configure();
            Hilos::$rt->connectionsSource()->add(SessionViewConnection::create($request->acceptKey, $identity->userId, sessionId: 101));
            SourceInterestRegistry::markReady(SourceChange::KIND_RT, DeliveryFlowSessionRuntime::connections);
            $grant = $this->workerPlacementGrant($master, $peer, $peerLink, $link, $pair[1], $worker, $agent, $request, $identity);
            $pendingIndex = SignalDispatcherFixture::owner($master)->pendingPageIndex();
            $pending = $pendingIndex->get($request->acceptKey, $request->deliveryId);
            self::assertInstanceOf(PendingPageDelivery::class, $pending);
            self::assertSame($agentId, $pending->agentId);
            self::assertNull($pending->nodeId);
            self::assertEquals($order, $pending->placementOrder);
            self::assertEquals($order, PageHandoffIdentity::workerOrder($grant->meta));

            if ($ending === 'expired at ingress') {
                $this->expireWorkerGrant($master, $worker, $request);
            }
            $stream->send(new RtSyncCreatedSignalData(
                DeliveryFlowSessionRuntime::connections, $request->acceptKey, [StateHilosConnection::userId => $identity->userId],
            ));
            $peer->onSignalReceived($peerLink, PeerDTO::fromWire(new PeerSignalDTO('socket', 'serving', $type, $index, $grant)->toJson()));
            $master->drain();
            $waiting = new ReflectionProperty(WorkerClient::class, 'waitingInTurn');
            $bytes = '';
            if ($released) {
                $link->send('{"neighbor":1}');
                if ($ending === 'policy loss') {
                    $link->sendAgentStop($type, $index);
                }
                while ($stream->pump(new RtWorkBudget())) {
                }
                self::assertTrue($link->hasPendingWrite());
                self::assertSame([], $waiting->getValue($link));
                $admissions = new ReflectionProperty(WorkerClient::class, 'writePermissions')->getValue($link);
                self::assertSame($ending === 'expired at ingress' ? 0 : 1, $admissions?->count() ?? 0);
                $link->send('{"neighbor":2}');
                if ($started) {
                    $link->write();
                    $bytes = $this->readBytes($pair[1]);
                    self::assertStringStartsWith("{\"neighbor\":1}\n{", $bytes);
                    self::assertSame(1, substr_count($bytes, "\n"));
                    self::assertTrue($link->hasPendingWrite());
                }
            } else {
                self::assertSame([$agentId => 1], $waiting->getValue($link));
            }

            if ($ending === 'expired after turn') {
                self::assertSame($pending, $pendingIndex->get($request->acceptKey, $request->deliveryId));
                self::assertSame('', $this->readBytes($pair[1]));
                $this->expireWorkerGrant($master, $worker, $request);
            } elseif ($ending === 'policy loss') {
                $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('new-leader', 2));
                self::assertNull($placement->confirmedLocalWorkerOrder($agentId));
            } elseif ($ending === 'leader loss' || $ending === 'leader current') {
                self::assertSame($pending, $pendingIndex->get($request->acceptKey, $request->deliveryId));
                self::assertNull($worker->router->pageSubscription($request->acceptKey));
                if ($ending === 'leader loss') {
                    // The stop queues its ledger cleanup behind work already in the master.
                    SignalDispatcherFixture::queue($master, static function (): Generator {
                        yield;
                        for ($step = 0; $step < 256; $step++) {
                            yield;
                        }
                    });
                    $leadership->leader = 'next';
                    $leadership->currentTerm = 2;
                    $workers->onLostSingletonHost();
                    self::assertSame('next', $placement->locate($type, $index)->nodeId);
                    self::assertFalse($agents->hasAgent($agentId));
                    self::assertSame($pending, $pendingIndex->get($request->acceptKey, $request->deliveryId));
                }
            }
            if ($order !== null && $ending !== 'policy loss') {
                self::assertEquals($order, $placement->confirmedLocalWorkerOrder($agentId));
            }

            while ($stream->pump(new RtWorkBudget())) {
            }
            self::assertSame([], $waiting->getValue($link));
            $bytes .= $this->drainWorkerBytes($link, $pair[1]);
            if ($released) {
                $lines = explode("\n", trim($bytes));
                self::assertSame('{"neighbor":1}', array_shift($lines));
                $granted = in_array($ending, ['policy current', 'leader current'], true);
                if ($started || $granted) {
                    $message = WorkerDTO::factoryWorkerDTO(array_shift($lines));
                    self::assertInstanceOf(DaemonAgentMessageDTO::class, $message);
                    self::assertSame($agentId, $message->agentId);
                    self::assertEquals($grant, $message->signal);
                    if ($granted) {
                        $worker->deliver($message);
                    }
                }
                if ($ending === 'policy loss') {
                    $stop = WorkerDTO::factoryWorkerDTO(array_shift($lines));
                    self::assertInstanceOf(AgentStopDTO::class, $stop);
                }
                self::assertSame('{"neighbor":2}', array_shift($lines));
                if ($ending === 'leader loss') {
                    self::assertCount(1, $lines);
                    $stop = WorkerDTO::factoryWorkerDTO(array_shift($lines));
                    self::assertInstanceOf(AgentStopDTO::class, $stop);
                    self::assertSame($agentId, $stop->agentId);
                    $master->drain();
                    self::assertNull($pendingIndex->get($request->acceptKey, $request->deliveryId));
                }
                self::assertSame([], $lines);
                self::assertSame($granted ? 1 : 0, $calls);
                self::assertSame($granted ? $request->deliveryId : null, $worker->router->pageSubscription($request->acceptKey)?->deliveryId);
            } else {
                self::assertSame('', $bytes);
            }

            if ($ending === 'policy loss') {
                $this->announceHandoffPlacement($placement, $type, 'serving', 2, 'next', 2);
                self::assertNull($placement->confirmedLocalWorkerOrder($agentId));
                $leadership->leader = 'next';
                $leadership->currentTerm = 2;
                $this->announceHandoffPlacement($placement, $type, 'serving', 2, 'next', 2);
                self::assertEquals($order, $placement->confirmedLocalWorkerOrder($agentId));
                self::assertSame(0, $calls);
                $nextRequest = new WebSocketPageSubscribeSignalDTO($request->acceptKey, $page, deliveryId: 'queued-grant:2');
                $grant = $this->workerPlacementGrant($master, $peer, $peerLink, $link, $pair[1], $worker, $agent, $nextRequest, $identity);
                $peer->onSignalReceived($peerLink, PeerDTO::fromWire(new PeerSignalDTO('socket', 'serving', $type, $index, $grant)->toJson()));
                $master->drain();
                $message = WorkerDTO::factoryWorkerDTO(trim($this->drainWorkerBytes($link, $pair[1])));
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $message);
                self::assertEquals($grant, $message->signal);
                $worker->deliver($message);
                self::assertSame(1, $calls);
                self::assertSame($nextRequest->deliveryId, $worker->router->pageSubscription($request->acceptKey)?->deliveryId);
            }
        } finally {
            $link->close();
            socket_close($pair[1]);
            $peerLink->close();
            socket_close($peerReader);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page('queued-grant'));
            SourceInterestRegistry::releaseConsumer(SourceConsumer::pageHandoff('queued-grant:1'));
            SourceInterestRegistry::releaseConsumer(SourceConsumer::pageHandoff('queued-grant:2'));
            Hilos::$rt = $previousRt;
            Hilos::$cluster = $previousCluster;
            Hilos::$sr = $previousRouter;
            $appClass->setValue(null, $previousApp);
        }
    }

    private function workerPlacementGrant(
        DeliveryFlowMaster $master,
        PeerServer $peer,
        PeerLink $peerLink,
        WorkerClient $link,
        Socket $reader,
        PerInstanceIdentityTestWorker $worker,
        AgentInterface $agent,
        WebSocketPageSubscribeSignalDTO $request,
        PageHandoffIdentity $identity,
    ): SignalDTO {
        $subscribe = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($request->page), $request, $identity->toMeta());
        $peer->onSignalReceived($peerLink, PeerDTO::fromWire(
            new PeerSignalDTO('socket', 'serving', $agent->getType(), $agent->getIndex(), $subscribe)->toJson(),
        ));
        $master->drain();
        $message = WorkerDTO::factoryWorkerDTO(trim($this->drainWorkerBytes($link, $reader)));
        self::assertInstanceOf(DaemonAgentMessageDTO::class, $message);
        self::assertSame($agent->getId(), $message->agentId);
        self::assertSame($request->deliveryId, $message->signal->data->deliveryId);
        $worker->deliver($message);
        $probe = $this->nextWorkerHandoffFrame($worker, SignalTypeConstants::PAGE_ACCESS_REASSESS);
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS),
            new SignalName($request->page),
            $request,
            [
                ...$message->signal->meta,
                PageHandoffIdentity::META_PROOF => PageHandoffIdentity::GRANTED,
                PageHandoffIdentity::META_CHALLENGE => $probe->meta[PageHandoffIdentity::META_CHALLENGE],
            ],
        );
    }

    private function expireWorkerGrant(
        DeliveryFlowMaster $master, PerInstanceIdentityTestWorker $worker, WebSocketPageSubscribeSignalDTO $request,
    ): void {
        $worker->passAt(microtime(true) + AgentConstants::START_DEADLINE_SECONDS);
        $terminal = $this->nextWorkerHandoffFrame($worker, SignalTypeConstants::PAGE_ACCESS_REASSESS);
        self::assertSame($request->deliveryId, $terminal->meta[PageHandoffIdentity::META_TERMINAL_REFUSAL]);
        $this->queueServingWorkerReply($master, $terminal);
        self::assertArrayNotHasKey($request->deliveryId, $this->pending($master)[$request->acceptKey] ?? []);
    }

    private function drainWorkerBytes(WorkerClient $link, Socket $reader): string
    {
        $bytes = '';
        for ($round = 0; $round < 64 && $link->hasPendingWrite(); $round++) {
            $link->write();
            $bytes .= $this->readBytes($reader);
        }
        self::assertFalse($link->hasPendingWrite());
        return $bytes;
    }

    /**
     * @param DeliveryFlowWorkerServer $server Server that owns the IPC link
     * @param DeliveryFlowAgentManagerDaemon $agents Roster shared by the server and serving master
     * @param AgentInterface ...$workerAgents Instances sharing the regular worker
     * @return array{WorkerClient, Socket} Master link and the worker's end of the pair
     */
    private function connectSharedWorker(
        DeliveryFlowWorkerServer $server,
        DeliveryFlowAgentManagerDaemon $agents,
        AgentInterface ...$workerAgents,
    ): array {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        self::assertTrue(socket_set_nonblock($pair[0]));
        self::assertTrue(socket_set_nonblock($pair[1]));
        $link = new WorkerClient($pair[0], $agents, $server);
        new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($link, 2);
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($server, $link);
        $agents->usesRoster = true;
        foreach ($workerAgents as $agent) {
            $daemon = $this->createStub(AgentDaemonInterface::class);
            $daemon->method('hasWorkerClient')->willReturn(true);
            $daemon->method('getWorkerClient')->willReturn($link);
            $agents->addAgent($agent->getId(), $daemon, 2, false);
        }

        return [$link, $pair[1]];
    }

    private function announceHandoffPlacement(
        ClusterPlacement $placement, string $type, string $host, int $sequence, string $leader = 'socket', int $term = 1,
    ): void {
        $order = new PlacementId('socket', 1, $sequence);
        $record = new PlacementRecord($type, '7', $host, PlacementState::Started, placementId: $order, workerStarted: true);
        $placement->onPlacementView($leader, new PeerPlacementViewDTO(
            $leader, [$host => [new PeerPlacedAgentEntry($type, '7')]], new PlacementSnapshot($leader, $term, $sequence, [$record]),
        ));
    }

    private function mountHandoffPlacement(string $node, string $type, ?string $index, PlacementId $order): ClusterPlacement
    {
        $executor = $this->createStub(PlacementExecutor::class);
        $executor->method('placementProfile')->willReturn(ResourceProfile::none());
        $executor->method('executePlacement')->willReturn(1);
        $executor->method('placedWorkerId')->willReturn(1);
        $mesh = $this->createStub(PlacementMesh::class);
        $mesh->method('nodeCapabilities')->willReturn(['slots=10']);
        $placement = new ClusterPlacement($node, $mesh, $executor);
        Hilos::$cluster->registerPlacement($placement);
        $placement->onPlacementQuery($order->leaderNodeId, new PeerPlacementQueryDTO('query', $order->term));
        $placement->onPlaceAgent($order->leaderNodeId, new PeerPlaceAgentDTO($type, $index, $order));
        $placement->noteAgentStarted($type, $index, placementId: $order);
        return $placement;
    }

    private function relayHandoffAttempt(SignalDTO $handoff): PeerSignalDTO
    {
        $previousRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        $type = PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE;
        [$peer, $link, $reader] = $this->connectPeer('relay', 'serving');
        [, $socketLink, $socketReader] = $this->connectPeer('relay', 'socket', $peer);
        try {
            $relay = new DeliveryFlowMaster();
            $relay->registerServer(new DeliveryFlowWorkerServer());
            $relay->registerServer($peer);
            $placement = $this->beginRelayRecovery($type, '7');
            Hilos::$cluster->registerAgentSignalSink($relay);
            $incoming = PeerDTO::fromWire(new PeerSignalDTO('socket', 'relay', $type, '7', $handoff)->toJson());
            self::assertInstanceOf(PeerSignalDTO::class, $incoming);
            $peer->onSignalReceived($socketLink, $incoming);
            $relay->drain();
            self::assertCount(1, $this->heldAgentFrames($relay));
            $this->announceHandoffPlacement($placement, $type, 'serving', 2);
            $relay->drain();
            [$forwarded] = $this->takePeerFrames($link, $reader, 1);
            self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
            self::assertTrue($forwarded->placementRecoveryRelay);
            self::assertSame($handoff->data->deliveryId, $forwarded->signal->data->deliveryId);
            return $forwarded;
        } finally {
            $link->close();
            $socketLink->close();
            socket_close($reader);
            socket_close($socketReader);
            Hilos::$sr = $previousRouter;
            Hilos::$cluster = $previousCluster;
        }
    }

    private function queueServingWorkerReply(DeliveryFlowMaster $serving, SignalDTO $reply): void
    {
        $ipc = WorkerDTO::factoryWorkerDTO(new WorkerAgentMessageDTO(null, $reply)->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $ipc);
        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($serving);
        $agents->handleAgentMessage($ipc);
        $serving->drain();
    }

    private function handoffRuntime(string $acceptKey): RosterRtContext
    {
        $runtime = new RosterRtContext();
        $runtime->configure();
        $previous = Hilos::$rt;
        Hilos::$rt = $runtime;
        try {
            $this->applyHandoffFact($this->master, SignalTypeConstants::RT_SYNC_CREATED, new RtSyncCreatedSignalData(
                RosterRtContext::connections, $acceptKey, RosterConnection::create($acceptKey, null)->toArray(),
            ));
        } finally {
            Hilos::$rt = $previous;
        }

        return $runtime;
    }

    private function applyHandoffIdentity(DeliveryFlowMaster $master, string $acceptKey, ?int $userId = 7): RtSyncUpdatedSignalData
    {
        $fact = new RtSyncUpdatedSignalData(RosterRtContext::connections, $acceptKey, [StateHilosConnection::userId => $userId]);
        $this->applyHandoffFact($master, SignalTypeConstants::RT_SYNC_UPDATED, $fact);

        return $fact;
    }

    private function applyHandoffFact(
        DeliveryFlowMaster $master, string $type, RtSyncCreatedSignalData|RtSyncUpdatedSignalData $fact,
    ): void {
        new ReflectionMethod(DaemonManager::class, 'handleDaemonSignal')->invoke(
            $master, new SignalDTO(new SignalSource(SignalSource::RT), new SignalType($type), new SignalName($type), $fact),
        );
    }

    /**
     * @param string $type Agent whose recovery is held by the relay
     * @param ?string $index Its index when testing a per-instance page
     * @return ClusterPlacement Relay placement waiting for confirmed recovery proof
     */
    private function beginRelayRecovery(string $type = 'owner', ?string $index = null): ClusterPlacement
    {
        Hilos::$cluster = new ClusterContext();
        $leadership = $this->createStub(Leadership::class);
        $leadership->method('leaderId')->willReturn('socket');
        Hilos::$cluster->registerLeadership($leadership);
        $executor = $this->createStub(PlacementExecutor::class);
        $executor->method('placementProfile')->willReturn(ResourceProfile::none());
        $executor->method('executePlacement')->willReturn(1);
        $mesh = $this->createStub(PlacementMesh::class);
        $mesh->method('nodeCapabilities')->willReturn(['slots=10']);
        $placement = new ClusterPlacement('relay', $mesh, $executor);
        Hilos::$cluster->registerPlacement($placement);
        $order = new PlacementId('socket', 1, 1);
        $placement->onPlacementQuery('socket', new PeerPlacementQueryDTO('query', 1));
        $placement->onPlaceAgent('socket', new PeerPlaceAgentDTO($type, $index, $order));
        $agentId = $index === null ? $type : $type . ':' . $index;
        $placement->beginLocalPlacementRecovery($agentId, $order);
        self::assertNotNull($placement->placementRecovery($agentId));
        return $placement;
    }

    /**
     * Drive the serving master's ordinary WS_USER remote route over a real PeerLink buffer.
     *
     * @param DeliveryFlowMaster $serving Node whose page worker replied
     * @param list<SignalDTO> $signals Output taken at its original worker queue boundary
     * @param string $acceptKey Browser attached to the socket master
     * @return list<SignalDTO> Addressed peer frames decoded by the production parser
     */
    private function forwardFromServing(DeliveryFlowMaster $serving, array $signals, string $acceptKey): array
    {
        $previousCluster = Hilos::$cluster;
        [$peer, $link, $reader] = $this->connectPeer('remote', 'socket');
        try {
            $serving->registerServer($peer);
            Hilos::$cluster = new ClusterContext();
            $locations = new ClusterClientLocation('remote');
            $locations->applySnapshot('socket', [$acceptKey]);
            Hilos::$cluster->registerClientLocation($locations);
            foreach ($signals as $signal) {
                Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
            }
            $serving->drain();
            $forwarded = [];
            foreach ($this->takePeerFrames($link, $reader, count($signals)) as $frame) {
                self::assertInstanceOf(PeerClientSignalDTO::class, $frame);
                self::assertSame('socket', $frame->targetNodeId);
                self::assertSame($acceptKey, $frame->acceptKey);
                $forwarded[] = $frame->signal;
            }
            return $forwarded;
        } finally {
            Hilos::$cluster = $previousCluster;
            $link->close();
            socket_close($reader);
        }
    }

    /**
     * @param string $localNode Node that owns the outgoing buffer
     * @param string $remoteNode Address of the peer that drains it
     * @param ?PeerServer $server Existing server when adding a second authenticated link
     * @return array{PeerServer, PeerLink, Socket} Addressed queue, link and its controlled socket
     */
    private function connectPeer(string $localNode, string $remoteNode, ?PeerServer $server = null): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $peer = $server ?? new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        $identity = NodeIdentity::of($localNode, 'Node ' . $localNode, NodeRole::Master, []);
        if ($server === null) {
            new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($peer, $identity);
        }
        $link = new PeerLink($pair[0], $peer, $identity, false, new PlainSocketTransport($pair[0]));
        $link->activate(NodeIdentity::of($remoteNode, 'Node ' . $remoteNode, NodeRole::Master, []));
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($peer, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($peer, $link);
        $links = new ReflectionProperty(PeerServer::class, 'activeLinks');
        $links->setValue($peer, [...$links->getValue($peer), $remoteNode => $link]);
        return [$peer, $link, $pair[1]];
    }

    /**
     * @param PeerLink $link Current addressed write slot
     * @param Socket $reader Socket that receives the write
     * @param int $count Expected number of physical frames
     * @return list<PeerDTO> Addressed frames decoded by the production parser
     */
    private function takePeerFrames(PeerLink $link, Socket $reader, int $count): array
    {
        $link->write();
        $wire = new PlainSocketTransport($reader)->read(65536);
        self::assertIsString($wire);
        $frames = array_map(PeerDTO::fromWire(...), explode("\n", trim($wire)));
        self::assertCount($count, $frames);
        return $frames;
    }

    public function testRemoteServingNodeUsesItsOwnWorkerLossToRecoverTheOriginSocket(): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        $router = Hilos::$sr;
        $serving = new DeliveryFlowMaster();
        $serving->registerServer(new DeliveryFlowWorkerServer());
        $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $request)->toJson());
        self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
        $serving->deliverSignalToAgent('owner', null, $forwarded->signal);
        self::assertCount(1, new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($serving)));
        $client->sendFrame('{"type":"own_state","data":{"incomplete":true}}');
        $serving->reportAgentsLostWithWorker(4, false, ['owner']);
        $signals = $this->takeSignals();
        self::assertCount(1, $signals);
        self::assertSame('page_resubscribe', $signals[0]->signalName->getName());
        $serving->drain();
        self::assertSame([], new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($serving)));
        Hilos::$sr = $router;
        $this->deliver($signals[0], $client, peer: true);
        $client->write();
        self::assertSame([['type' => 'page_resubscribe', 'data' => ['page' => 'delivery', 'params' => []]]], $this->read($socket));
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertSame([], $this->pending());
    }

    /** @return iterable<string, array{bool}> Last boundary crossed by the old control */
    public static function ordinaryRecoveryLifetimes(): iterable
    {
        yield 'replace before dispatch' => [false];
        yield 'replace after packing' => [true];
    }

    #[DataProvider('ordinaryRecoveryLifetimes')]
    public function testOrdinaryRemoteLossRecoveryRetainsTheExactAttempt(bool $packed): void
    {
        [$client, $socket] = $this->connect();
        $params = ['key' => 'same'];
        $initial = $this->subscribe($client, params: $params);
        $this->master->route($initial, new RemoteAgentDestination('remote', 'owner'), $this->worker);
        if ($packed) {
            $this->master->noteNodeUnreachable('remote', microtime(true));
            $this->master->drain();
            (new ReflectionMethod(WebSocketClient::class, 'drainDelivery'))->invoke($client);
            self::assertTrue($client->hasPendingWrite());
        } else {
            $socketRouter = Hilos::$sr;
            try {
                $serving = new DeliveryFlowMaster();
                $serving->registerServer(new DeliveryFlowWorkerServer());
                $serving->deliverSignalToAgent('owner', null, $initial);
                $serving->reportAgentsLostWithWorker(4, false, ['owner']);
                $control = $this->takeSignals()[0];
                self::assertSame(SignalConstants::PAGE_RESUBSCRIBE, $control->signalName->getName());
                self::assertSame($initial->data->deliveryId, $control->meta[WebSocketPageSubscribeSignalDTO::DELIVERY_ID] ?? null);
            } finally {
                Hilos::$sr = $socketRouter;
            }
        }
        self::assertSame([], $this->read($socket));
        $fresh = $this->subscribe($client, params: $params);
        if (!$packed) {
            $this->deliver($control, $client, peer: true);
        }
        self::assertTrue($client->isDeliveryPending('delivery', $fresh->data->deliveryId));
        $this->deliver($this->addressedPageReply($fresh->data, false, 'current'), $client);
        $client->completeDelivery('delivery', $fresh->data->deliveryId);
        $client->now += 5_000;
        $client->write();
        [$answer] = $this->read($socket);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $answer['type']);
        self::assertSame('current', $answer['data']['payload']['data']['marker']);
        self::assertSame([], $this->read($socket));
    }

    public function testAReleasedStartFrameCannotRestoreAnUnsubscribedAttempt(): void
    {
        [$client, $socket] = $this->connect();
        $agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->master);
        $agents->started = false;
        $this->worker->awaiting = true;
        $client->acceptJson('{"type":"page_subscribe","page":"delivery"}');
        $this->master->drain();
        self::assertSame([], $this->worker->requests);
        self::assertCount(1, $this->pending()[$client->acceptKey]);
        $this->master->onAgentStarted('owner');
        self::assertCount(1, $this->heldAgentFrames(released: true));
        $client->acceptJson('{"type":"page_unsubscribe","page":"delivery"}');
        $agents->started = true;
        $this->master->drain();
        self::assertSame([], $this->pending());
        self::assertSame(['page_unsubscribe'], array_map(
            static fn (DaemonAgentMessageDTO $message): string => $message->signal->signalType->getType(), $this->worker->requests));
        $this->master->onAgentStarted('owner');
        $this->master->drain();
        $client->now = 10_000;
        $client->write();
        self::assertSame([], $this->read($socket));
    }

    public function testForwardedUnsubscribeClearsTheServingNodesAttemptBeforeItsWorkerRuns(): void
    {
        [$client] = $this->connect();
        $request = $this->subscribe($client);
        $router = Hilos::$sr;
        $serving = new DeliveryFlowMaster();
        $serving->registerServer(new DeliveryFlowWorkerServer());
        $serving->deliverSignalToAgent('owner', null, $request);
        self::assertCount(1, new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($serving)));
        $client->acceptJson('{"type":"page_unsubscribe","page":"delivery"}');
        $unsubscribe = Hilos::$sr->getNextQueuedSignal();
        $forwarded = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $unsubscribe)->toJson());
        self::assertInstanceOf(PeerSignalDTO::class, $forwarded);
        $serving->deliverSignalToAgent('owner', null, $forwarded->signal);
        self::assertSame([], new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($serving)));
        $serving->reportAgentsLostWithWorker(4, false, ['owner']);
        self::assertSame([], $this->takeSignals());
        Hilos::$sr = $router;
        Hilos::$sr->queueSignal($unsubscribe->signalSource, $unsubscribe->signalType, $unsubscribe->signalName, $unsubscribe->data);
        $this->master->drain();
        self::assertSame([], $this->pending());
    }

    public function testQueuedSupersededAndClosedRequestsAreNeverDispatched(): void
    {
        [$client] = $this->connect();
        $client->acceptJson('{"type":"page_subscribe","page":"old"}');
        $client->acceptJson('{"type":"page_subscribe","page":"delivery"}');
        $this->master->drain();
        self::assertCount(1, $this->worker->requests);
        self::assertSame('delivery', $this->worker->requests[0]->signal->data->page);
        $this->worker->requests = [];
        $client->acceptJson('{"type":"page_subscribe","page":"closed"}');
        $this->server->dropClient($client);
        $this->master->drain();
        self::assertSame([], $this->worker->requests);
        self::assertSame([], $this->pending());
    }

    public function testNodePageMoveCancelsThePendingFallbackAndRequestsANewBrowserAttempt(): void
    {
        $previousCluster = Hilos::$cluster;
        $environment = [];
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE', 'CLUSTER_PEER_ADVERTISE'] as $key) {
            $environment[$key] = getenv($key);
        }
        try {
            putenv('CLUSTER_ENABLED=true');
            putenv('CLUSTER_NODE_ID=socket');
            putenv('CLUSTER_NODE_NAME=Local node');
            putenv('CLUSTER_NODE_ROLE=master');
            putenv('CLUSTER_PEER_ADVERTISE=127.0.0.1:7000');
            Hilos::$env = new EnvAccessor();
            Hilos::$cluster = new ClusterContext();
            [$client, $socket] = $this->connect();
            $client->acceptJson('{"type":"page_subscribe","page":"node","params":{"nodeId":"remote"}}');
            $this->master->drain();
            $request = array_pop($this->worker->requests)->signal;
            self::assertTrue($client->isDeliveryPending('node', $request->data->deliveryId));
            self::assertCount(1, $this->pending()[$client->acceptKey]);
            self::assertNull(Hilos::$sr->pageSubscription($client->acceptKey)->agentNode);
            $client->sendFrame('{"type":"own_state","data":{"incomplete":true}}');
            $identity = NodeIdentity::of('remote', 'Node remote', NodeRole::Master, []);
            Hilos::$cluster->registry()->recordPeer($identity, microtime(true));
            $this->master->onNodeJoined(ClusterNode::fromIdentity($identity, true, microtime(true)));
            self::assertSame('remote', Hilos::$sr->pageSubscription($client->acceptKey)->agentNode);
            self::assertFalse($client->isDeliveryPending('node', $request->data->deliveryId));
            $this->master->drain();
            self::assertSame([], $this->pending());
            $client->write();
            self::assertSame([
                ['type' => 'page_resubscribe', 'data' => ['page' => 'node', 'params' => ['nodeId' => 'remote']]],
            ], $this->read($socket));
            self::assertSame(['page_unsubscribe'], array_map(
                static fn (DaemonAgentMessageDTO $message): string => $message->signal->signalType->getType(), $this->worker->requests));
        } finally {
            Hilos::$cluster = $previousCluster;
            foreach ($environment as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }

    public function testUnsubscribeAndSocketDropDiscardUnfinishedWorkAndOwnerReferences(): void
    {
        [$client, $socket] = $this->connect();
        $request = $this->subscribe($client);
        $client->sendFrame('{"type":"own_state","data":1}');
        $client->acceptJson('{"type":"page_unsubscribe","page":"delivery"}');
        $this->master->drain();
        self::assertSame([], $this->pending());
        $client->completeDelivery('delivery', $request->data->deliveryId);
        $client->write();
        self::assertSame([], $this->read($socket));
        $this->subscribe($client);
        self::assertCount(1, $this->pending()[$client->acceptKey]);
        $this->server->dropClient($client);
        self::assertSame([], $this->pending());
        self::assertFalse($client->hasPendingWrite());
    }

    public function testGracefulShutdownForcesCompletedWorkAndNeverReleasesAnUnfinishedHold(): void
    {
        [$held, $heldSocket] = $this->connect();
        [$complete, $completeSocket] = $this->connect();
        $this->subscribe($held);
        $held->sendFrame('{"type":"own_state","data":1}');
        $complete->sendFrame('{"type":"toast","data":1}');
        $complete->write();
        $this->read($completeSocket);
        $complete->now = 100;
        $complete->sendFrame('{"type":"toast","data":2}');
        $held->closeOnceWritten();
        $complete->closeOnceWritten();
        $held->now = 10_000;
        $held->write();
        $complete->write();
        self::assertSame([], $this->read($heldSocket));
        self::assertSame([['type' => 'toast', 'data' => 2]], $this->read($completeSocket));
    }

    /** @return array{DeliveryFlowClient, Socket} Accepted socket and its browser end */
    private function connect(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new DeliveryFlowClient($pair[0]);
        $this->server->attach($client);
        $this->connections[] = [$client, $pair[1]];
        return [$client, $pair[1]];
    }

    private function subscribe(DeliveryFlowClient $client, string $page = 'delivery', array $params = []): SignalDTO
    {
        $client->acceptJson(json_encode([
            'type' => 'page_subscribe', 'page' => $page, 'params' => $params, 'deliveryId' => 'forged',
        ], JSON_THROW_ON_ERROR));
        $this->master->drain();
        $message = array_pop($this->worker->requests);
        self::assertInstanceOf(DaemonAgentMessageDTO::class, $message);
        return $message->signal;
    }

    private function acceptUpdate(DeliveryFlowClient $client, array $params): SignalDTO
    {
        $client->acceptJson(json_encode(['type' => 'page_update_subscription', 'page' => 'delivery', 'params' => $params], JSON_THROW_ON_ERROR));
        $request = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $request?->data);
        return $request;
    }

    private function assertUpdateAnswer(DeliveryFlowClient $client, Socket $socket, SignalDTO $request): void
    {
        $this->handle($request);
        $signals = $this->takeSignals();
        self::assertCount(5, $signals);
        self::assertSame(SignalConstants::DELIVERY_COMPLETE, $signals[4]->signalName->getName());
        self::assertSame($request->data->deliveryId, $signals[4]->data->data->deliveryId);
        foreach ($signals as $signal) {
            $this->deliver($signal, $client, peer: true);
        }
        $client->now += 5000;
        $client->write();
        $frames = $this->read($socket);
        self::assertCount(1, $frames);
        self::assertSame(['own_state', 'page_response', 'table_facet_counts', 'after_response'], array_column($frames[0]['data']['frames'], 'type'));
        self::assertFalse($client->isDeliveryPending('delivery', $request->data->deliveryId));
        self::assertArrayNotHasKey($request->data->deliveryId, $this->pending()[$client->acceptKey] ?? []);
    }

    private function handle(SignalDTO $request): void
    {
        if ($request->data instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
            $this->pages->dispatchPageUpdateSubscription($request->data, SignalSource::WEBSOCKET, $request->signalName->getName());
        } else {
            $this->pages->dispatchPageSubscribe($request->data, SignalSource::WEBSOCKET, $request->signalName->getName());
        }
    }

    /** @return list<SignalDTO> All output actually queued by the page handler */
    private function takeSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }

    private function deliver(SignalDTO $signal, DeliveryFlowClient $client, bool $peer = false): void
    {
        $message = WorkerDTO::factoryWorkerDTO(new WorkerAgentMessageDTO('owner', $signal)->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $message);
        $signal = $message->signal;
        if ($peer) {
            $frame = PeerDTO::fromWire(new PeerClientSignalDTO('remote', 'socket', $client->acceptKey, $signal)->toJson());
            self::assertInstanceOf(PeerClientSignalDTO::class, $frame);
            $signal = $frame->signal;
        }
        $this->master->deliverSignalToClient($client->acceptKey, $signal);
    }

    /** @return array<string, mixed> Master process's actual pending address ledger */
    private function pending(?DaemonManager $master = null): array
    {
        return new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')
            ->getValue(SignalDispatcherFixture::owner($master ?? $this->master));
    }

    /**
     * @param ?DaemonManager $manager Exact serving master, or this socket master
     * @param bool $released Whether to inspect released rather than blocked entries
     * @return list<ParkedAgentSignal> Actual owner's original envelopes in their existing order
     */
    private function heldAgentFrames(?DaemonManager $manager = null, bool $released = false): array
    {
        $owner = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager ?? $this->master);
        $entries = new ReflectionProperty(AgentSignalHolds::class, 'entries')->getValue($owner);
        $frames = [];
        foreach ($entries as $hold) {
            if (($released ? $hold->released : $hold->isParked()) && $hold->parked !== null) {
                $frames[] = $hold->parked;
            }
        }
        return $frames;
    }

    private function remoteNode(): ClusterNode
    {
        return ClusterNode::fromIdentity(NodeIdentity::of('remote', 'Node remote', NodeRole::Master, []), false, microtime(true));
    }

    /** @return list<array<string, mixed>> Physical text frames, with no flattening of signal_batch */
    private function read(Socket $socket): array
    {
        return $this->decodeFrames($this->readBytes($socket));
    }

    private function readBytes(Socket $socket): string
    {
        $bytes = '';
        $transport = new PlainSocketTransport($socket);
        while (($chunk = $transport->read(65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
        return $bytes;
    }

    /** @return list<array<string, mixed>> Physical text frames, with no flattening of signal_batch */
    private function decodeFrames(string $bytes): array
    {
        $frames = [];
        while ($bytes !== '') {
            self::assertSame(1, ord($bytes[0]) & 15);
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
            $json = substr($bytes, $offset, $length);
            self::assertStringNotContainsString('hilos_delivery_complete', $json);
            $frames[] = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $bytes = substr($bytes, $offset + $length);
        }
        return $frames;
    }
}

final class DeliveryFlowClient extends WebSocketClient
{
    public int $now = 0;

    public function __construct(Socket $socket, ?SocketTransportInterface $transport = null)
    {
        parent::__construct($socket, $transport);
        $this->handshakeCompleted = true;
        $this->acceptKey = 'flow-' . spl_object_id($this);
    }

    public function acceptJson(string $json): void
    {
        $this->onFrame($json);
    }

    protected function deliveryNowMs(): int
    {
        return $this->now;
    }

    protected function onHandshake(array $headers, string $acceptKey, array $cookies, ?string $clientIp, RequestQueryParams $queryParams): void
    {
    }

    protected function onClose(): void
    {
    }
}

final class DeliveryFlowServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    public function attach(WebSocketClient $client): void
    {
        $this->addClient($client);
    }

    protected function onStart(): void
    {
    }

    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new DeliveryFlowClient($socket);
    }
}

final class DeliveryFlowWorkerServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> Requests decoded by the actual typed IPC parser */
    public array $requests = [];
    public bool $refuse = false;
    public bool $awaiting = false;
    public int $startRequests = 0;

    /** @param ?AgentManagerDaemon $agents Roster for fixture cases with a real stop link */
    public function __construct(?AgentManagerDaemon $agents = null)
    {
        if ($agents !== null) {
            new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $agents);
        }
    }

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function ensureAgentUp(string $agentType, ?string $agentIndex, ?AgentStartSink $immediateStartSink = null): void
    {
        ++$this->startRequests;
    }

    public function isAgentAwaitingWorker(string $agentId): bool
    {
        return $this->awaiting;
    }

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param DaemonAgentMessageDTO $messageDto Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function sendSignalToAgent(
        string $agentType,
        ?string $agentIndex,
        DaemonAgentMessageDTO $messageDto,
        ?AgentStartSink $immediateStartSink = null,
    ): void
    {
        if ($this->refuse) {
            throw new NoSuitableWorkerException('regular', false);
        }
        $this->requests[] = WorkerDTO::factoryWorkerDTO($messageDto->toJson());
    }

    protected function onStart(): void
    {
    }
}

final class DeliveryFlowMaster extends DaemonManager
{
    public function __construct()
    {
        parent::__construct();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this, new EventLoop());
    }

    public function drain(): void
    {
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        do {
            $dispatch->invoke($this);
        } while ($runnable->invoke($this));
    }

    public function route(SignalDTO $signal, AgentAddressedDestination $destination, WorkerServer $worker): void
    {
        $this->deliverToAgentDestination(SignalDispatcherFixture::direct($signal), $worker, new DeliveryFlowMesh(), $destination, $signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new DeliveryFlowRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DeliveryFlowAgentManagerDaemon();
    }
}

final class DeliveryFlowRouter extends SignalRouter
{
    protected const bool USE_DESTINATION_CALLBACK = true;

    public function getPageSubscriptionAgentType(string $page): ?string
    {
        return match ($page) {
            'unserved' => null,
            PerInstancePublicIdentityPage::PAGE => PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE,
            default => 'owner',
        };
    }

    public function pageAgentIndexRoute(string $page): ?PageAgentIndexRoute
    {
        return match ($page) {
            'node' => new PageAgentIndexRoute(PageAgentIndexSource::NODE_PARAM, 'nodeId', 'owner'),
            'identity' => new PageAgentIndexRoute(PageAgentIndexSource::SESSION_USER, null, 'owner'),
            PerInstancePublicIdentityPage::PAGE => new PageAgentIndexRoute(
                PageAgentIndexSource::SESSION_USER_OR_GUEST, null, PerInstanceFallbackAgent::AGENT_TYPE,
            ),
            default => null,
        };
    }

    public function getDestinations(SignalDTO $signal): array
    {
        if ($signal->signalName->getName() === PerInstancePublicIdentityPage::PAGE) {
            return parent::getDestinations($signal);
        }
        if (in_array($signal->signalType->getType(), ['page_subscribe', 'page_update_subscription'], true)) {
            if ($signal->signalName->getName() === 'unreachable') {
                return [new RemoteAgentDestination('remote', 'owner')];
            }
            return $signal->signalName->getName() === 'unserved' ? [] : [new AgentDestination('owner')];
        }
        return parent::getDestinations($signal);
    }
}

final class DeliveryFlowAgentManagerDaemon extends AgentManagerDaemon
{
    public bool $started = true;
    public bool $usesRoster = false;
    public bool $usesStartReports = false;
    public ?AgentDaemonInterface $restartDaemon = null;

    /** @var array<string, true> Agents individually held on the fixture worker */
    public array $stopped = [];

    public function isAgentStarted(string $agentId): bool
    {
        if ($this->usesStartReports) {
            return parent::isAgentStarted($agentId);
        }

        return $this->usesRoster ? $this->hasAgent($agentId) : $this->started && !isset($this->stopped[$agentId]);
    }

    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return $this->restartDaemon ?? throw new AgentDaemonCreationFailedException('The transport test creates no worker processes');
    }
}

abstract class DeliveryFlowHandoffHilos extends Hilos
{
    public const array PAGES = [PerInstancePublicIdentityPage::PAGE => DeliveryFlowHandoffPage::class];
}

abstract class DeliveryFlowRelayHilos extends DeliveryFlowHandoffHilos
{
    public const array AGENTS = [
        PerInstancePublicIdentityPage::SUBSCRIPTION_AGENT_TYPE => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}

abstract class DeliveryFlowProductHilos extends DeliveryFlowRelayHilos
{
    public const array PAGES = [PerInstancePublicIdentityPage::PAGE => DeliveryFlowProductPage::class];
}

final class DeliveryFlowSessionRuntime extends RtContext
{
    public const string connections = 'deliveryFlowSessionConnections';

    /** @param string $connectionCollection Connection source the fixture mounts */
    public function __construct(private readonly string $connectionCollection = self::connections)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->_stateCollections[$this->connectionCollection] = SessionViewStates::init();
        $this->setRepresent($this->connectionCollection, SessionViewConnections::class);
    }
}

final class DeliveryFlowGuestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = PerInstanceFallbackAgent::AGENT_TYPE;

    /** @var list<?int> Sessions observed inside a guest subscribe hook */
    public array $subscribedSessions = [];

    public ?Closure $onRelease = null;
    public ?Closure $onSubscribe = null;

    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        $this->onSubscribe?->__invoke();
        $row = Hilos::$rt?->connectionsSource()?->get($data->acceptKey);
        $this->subscribedSessions[] = $row instanceof StateHilosSessionConnection ? $row->sessionId : null;
    }

    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        $this->onRelease?->__invoke();
    }

    public function onStop(): void
    {
    }
}

class DeliveryFlowHandoffPage extends AbstractPage
{
    public const array READS_RT = [RosterRtContext::connections];
}

final class DeliveryFlowProductPage extends DeliveryFlowHandoffPage
{
    public const array READS_DB = ['products'];
}

final class DeliveryFlowMesh implements AgentSignalMesh
{
    public function sendSignalToNode(
        string $targetNodeId,
        string $agentType,
        ?string $agentIndex,
        SignalDTO $signal,
        bool $placementRecoveryRelay = false,
    ): bool
    {
        $restored = PeerDTO::fromWire(new PeerSignalDTO('socket', $targetNodeId, $agentType, $agentIndex, $signal)->toJson());
        TestCase::assertInstanceOf(PeerSignalDTO::class, $restored);
        TestCase::assertSame($signal->data->deliveryId, $restored->signal->data->deliveryId);
        return true;
    }
}

abstract class DeliveryFlowPlacementHilos extends Hilos
{
    public const array AGENTS = [
        DeliveryFlowAgent::AGENT_TYPE => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}

final class DeliveryFlowAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'owner';

    public int $pageReleases = 0;

    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        ++$this->pageReleases;
    }

    public function onStop(): void
    {
    }
}

final class DeliveryFlowWorker extends WorkerManager
{
    public function __construct(private readonly PageSignalRouter $pages, private readonly ?SignalRouter $router = null)
    {
        parent::__construct(1);
    }

    public function pass(): void
    {
        $this->releaseParkedFrames(microtime(true));
    }

    protected function createSignalRouter(): SignalRouter
    {
        return $this->router ?? new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        $manager = new DeliveryFlowAgentManager();
        $manager->addAgent('owner', new DeliveryFlowAgent());
        return $manager;
    }

    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return $this->pages;
    }
}

final class DeliveryFlowAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new DeliveryFlowAgent();
    }
}

class DeliveryFlowPage extends AbstractPage
{
    public const string PAGE = 'delivery';
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
    public ?Throwable $failure = null;
    public ?Closure $beforeResponse = null;
    public ?Closure $projection = null;
    public ?PageResource $resource = null;

    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): ?PageResource
    {
        return $this->resource;
    }

    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $this->projection?->__invoke($acceptKey);

        return null;
    }

    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->beforeResponse !== null) {
            ($this->beforeResponse)($acceptKey);
            return;
        }
        $this->sendToUser('own_state', $acceptKey, new SignalData());
    }

    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser('after_response', $acceptKey, new SignalData());
    }
}

final class DeliveryFlowIdentityPage extends DeliveryFlowPage
{
    public const string PAGE = 'identity';
}

/** @extends AbstractPageFactory<DeliveryFlowAgent> */
final class DeliveryFlowFactory extends AbstractPageFactory
{
    public function page(): DeliveryFlowPage
    {
        return $this->getPage('delivery');
    }

    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, ['delivery', 'identity'], true);
    }

    protected function createPage(string $pageName): AbstractPage
    {
        return $pageName === 'identity' ? new DeliveryFlowIdentityPage($this->agent) : new DeliveryFlowPage($this->agent);
    }
}

final class DeliveryFlowBrowser extends BrowserContext
{
    public ConnectionIdentity $identity;
    public bool $sendWholeOnFact = false;
    /** @var ?Closure(string): void */
    public ?Closure $duringAccess = null;

    protected function observesOwnPageChange(string $page, string $acceptKey, SourceChange $change): bool
    {
        return $this->sendWholeOnFact && $page === DeliveryFlowPage::PAGE && $change->sourceKey === 'fixture';
    }

    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(null);
    }

    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
        $callback = $this->duringAccess;
        $this->duringAccess = null;
        $callback?->__invoke($acceptKey);
    }

    public function sendSnapshotFacetCounts(string $page, string $acceptKey, PagePayload $snapshot): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('table_facet_counts'), new WebSocketSignalData(new SignalData(), targetAcceptKey: $acceptKey));
    }

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }
}

/** Real browser snapshot and facet emitters, with only page declarations and identity supplied. */
final class DeliveryRestoredWindowBrowser extends BrowserContext
{
    public ConnectionIdentity $identity;

    /** Starts with an identified anonymous connection. */
    public function __construct(private readonly bool $secondWindow = false)
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(null);
    }

    /**
     * Public fixture pages have no access guards.
     *
     * @param string $page Fixture page
     * @param string $acceptKey Real socket identity
     * @param PageRouteParams $params Fixture route parameters
     */
    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
    }

    /**
     * @param string $acceptKey Connection being resolved
     * @return ConnectionIdentity Test-controlled identity verdict
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return $this->identity;
    }

    /**
     * @param string $page Fixture page
     * @return ?BrowserPageConfig Public page configuration
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'own_state']);
    }

    /**
     * @param string $page Fixture page
     * @return BrowserPageBindings Table whose retained window the page restores
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray($this->secondWindow ? ['hilosLogKeys' => [], 'secondWindow' => []] : ['hilosLogKeys' => []]);
    }
}

/** Mounts a memory-backed table so transport integration needs no database lifecycle. */
final class DeliveryRestoredWindowTables extends TableContext
{
    public function __construct(
        private readonly DeliveryRestoredWindowTable $table,
        private readonly ?DeliveryRestoredWindowTable $second = null,
    ) {
    }

    /** Mounts the fixture under its browser keys. */
    public function configure(): void
    {
        $this->register('hilosLogKeys', $this->table);
        if ($this->second !== null) {
            $this->register('secondWindow', $this->second);
        }
    }
}

/** Uses production window filtering and facet tallying over fixed, non-database rows. */
final class DeliveryRestoredWindowTable extends TableDefinition implements SelfSnapshotTable
{
    private const array ROWS = [
        ['id' => 'a', 'class' => 'daemon', 'label' => 'match'],
        ['id' => 'b', 'class' => 'agent', 'label' => 'match'],
        ['id' => 'c', 'class' => 'worker', 'label' => 'match'],
        ['id' => 'd', 'class' => 'agent', 'label' => 'match'],
        ['id' => 'e', 'class' => 'worker', 'label' => 'other'],
    ];

    public ?TableQueryDTO $firstQuery = null;

    /** @var ?Closure(): list<array{id: string, class: string, label: string}> Replacement during the table query */
    public ?Closure $duringQuery = null;

    /** @var ?Closure(): list<TableProgressDTO> Callback while the whole reads table progress */
    public ?Closure $duringProgress = null;

    /** @var array<string, list<int|float|string|bool>> Facets declared when the first snapshot query begins */
    public array $facetsAtFirstQuery = [];

    /** @param string $acceptKey Connection whose declarations are observed during the query */
    public function __construct(private readonly string $acceptKey)
    {
        parent::__construct();
    }

    /**
     * @param SourceChange $change Unused source event
     * @return ?TableRowMutationDTO No source fanout in this fixture
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Snapshot row
     * @return array<string, mixed> Browser row before wire serialization
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [BrowserPageSignalData::rowKey => $row->requireRowKey(), BrowserPageSignalData::sources => ['logs' => $row->toArray()]];
    }

    /**
     * @param TableQueryDTO $query Restored search and filters
     * @param array<string, list<int|float|string|bool>> $wanted Declared options
     * @return ?array<string, mixed> Counts from the same query path that builds windows
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters($query, $wanted,
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO($this->getPage($set)->totalCount, true));
    }

    /** @return array<string, string> Text searched by the fixture */
    protected function searchableFields(): array
    {
        return ['label' => 'label'];
    }

    /**
     * @return list<TableProgressDTO> Progress to include in a whole response
     */
    public function progressSnapshot(): array
    {
        return $this->duringProgress?->__invoke() ?? [];
    }

    /**
     * @param TableQueryDTO $query Window or facet set query
     * @return TableSnapshotDTO Production-filtered snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($this->firstQuery === null) {
            $this->firstQuery = $query;
            $this->facetsAtFirstQuery = Hilos::$sr->getTableFacets($this->acceptKey, 'hilosLogKeys');
        }
        $rows = array_values(array_filter($this->duringQuery?->__invoke() ?? self::ROWS,
            static fn(array $row): bool => !isset($query->filter['class']) || $query->filter['class'] === $row['class']));

        return $this->filterInMemory($rows, $query);
    }
}
