<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Daemon\SignalDispatcher;
use Hilos\Tests\Fixtures\SignalDispatcherFixture;
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
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\SignalConstants;
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
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\PendingPageDelivery;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageRouteParams;
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
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use Throwable;

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
        $this->worker = new DeliveryFlowWorkerServer();
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
    public function testRestoredWindowDeclaresFacetsDuringSnapshotAndReleasesOnePhysicalAnswer(
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
        self::assertSame($descriptor['facets'], $table->facetsAtFirstQuery, 'Facets are declared before the snapshot query.');
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

    /** @return ClusterPlacement Relay placement waiting for confirmed recovery proof */
    private function beginRelayRecovery(): ClusterPlacement
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
        $placement->onPlaceAgent('socket', new PeerPlaceAgentDTO('owner', null, $order));
        $placement->beginLocalPlacementRecovery('owner', $order);
        self::assertNotNull($placement->placementRecovery('owner'));
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
     * @return array{PeerServer, PeerLink, Socket} Addressed queue, link and its controlled socket
     */
    private function connectPeer(string $localNode, string $remoteNode): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $peer = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        $identity = NodeIdentity::of($localNode, 'Node ' . $localNode, NodeRole::Master, []);
        new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($peer, $identity);
        $link = new PeerLink($pair[0], $peer, $identity, false, new PlainSocketTransport($pair[0]));
        $link->activate(NodeIdentity::of($remoteNode, 'Node ' . $remoteNode, NodeRole::Master, []));
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($peer, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($peer, $link);
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($peer, [$remoteNode => $link]);
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
    private function pending(): array
    {
        return new ReflectionProperty(SignalDispatcher::class, 'pendingPageDeliveries')->getValue(SignalDispatcherFixture::owner($this->master));
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
        $bytes = '';
        while (($chunk = new PlainSocketTransport($socket)->read(65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
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

    public function __construct(Socket $socket)
    {
        parent::__construct($socket);
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

    public function __construct()
    {
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

    public function isAgentAwaitingStopReport(string $agentId): bool
    {
        return false;
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
        return $page === 'unserved' ? null : 'owner';
    }

    public function pageAgentIndexRoute(string $page): ?PageAgentIndexRoute
    {
        return match ($page) {
            'node' => new PageAgentIndexRoute(PageAgentIndexSource::NODE_PARAM, 'nodeId', 'owner'),
            'identity' => new PageAgentIndexRoute(PageAgentIndexSource::SESSION_USER, null, 'owner'),
            default => null,
        };
    }

    public function getDestinations(SignalDTO $signal): array
    {
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

    public function isAgentStarted(string $agentId): bool
    {
        return $this->started;
    }

    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('The transport test creates no worker processes');
    }
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

    public function onStop(): void
    {
    }
}

final class DeliveryFlowWorker extends WorkerManager
{
    public function __construct(private readonly PageSignalRouter $pages)
    {
        parent::__construct(1);
    }

    public function pass(): void
    {
        $this->releaseParkedFrames(microtime(true));
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
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

    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
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

    public function __construct()
    {
        parent::__construct();
        $this->identity = ConnectionIdentity::resolved(null);
    }

    public function assertSubscriptionAccess(string $page, string $acceptKey, PageRouteParams $params): void
    {
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
    public function __construct()
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
        return BrowserPageBindings::fromArray(['hilosLogKeys' => []]);
    }
}

/** Mounts a memory-backed table so transport integration needs no database lifecycle. */
final class DeliveryRestoredWindowTables extends TableContext
{
    /** @param DeliveryRestoredWindowTable $table Actual table definition */
    public function __construct(private readonly DeliveryRestoredWindowTable $table)
    {
    }

    /** Mounts the fixture under the restored browser key. */
    public function configure(): void
    {
        $this->register('hilosLogKeys', $this->table);
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
     * @param TableQueryDTO $query Window or facet set query
     * @return TableSnapshotDTO Production-filtered snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($this->firstQuery === null) {
            $this->firstQuery = $query;
            $this->facetsAtFirstQuery = Hilos::$sr->getTableFacets($this->acceptKey, 'hilosLogKeys');
        }
        $rows = array_values(array_filter(self::ROWS,
            static fn(array $row): bool => !isset($query->filter['class']) || $query->filter['class'] === $row['class']));

        return $this->filterInMemory($rows, $query);
    }
}
