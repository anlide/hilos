<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonPlacementPage;
use Hilos\Pages\Daemon\DTO\HilosDaemonOverviewData;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Placement page through the real factory, guards, viewer bridge and window publication. */
final class HilosDaemonPlacementPageTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private string|false $previousClusterEnabled;
    private ?TableContext $previousTable;
    private ?DbContext $previousDb;
    private string $previousAppClass;
    /** @var array<string, mixed> Driver configuration restored after the page cases */
    private array $previousDatabase = [];
    private string $logFile;
    private DaemonPlacementTestBrowser $browser;
    private DaemonPlacementTestSignalRouter $signalRouter;
    private PageSignalRouter $pageRouter;
    private DaemonPlacementTestResender $resender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        $this->previousTable = Hilos::$table;
        $this->previousDb = Hilos::$db;
        $this->previousAppClass = Hilos::appClass();
        foreach (['configurations', 'currentIndex', 'connections', 'resultSets', 'lastSql', 'closedByHand'] as $name) {
            $this->previousDatabase[$name] = (new ReflectionProperty(Database::class, $name))->getValue();
        }
        (new ReflectionProperty(Database::class, 'configurations'))->setValue(null, []);
        (new ReflectionProperty(Database::class, 'currentIndex'))->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->signalRouter = new DaemonPlacementTestSignalRouter();
        Hilos::$sr = $this->signalRouter;
        Hilos::$rt = new DaemonPlacementTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = null;
        Hilos::$db = null;
        Hilos::$table = new DaemonPlacementTestTableContext();
        Hilos::$table->configure();
        $this->browser = new DaemonPlacementTestBrowser();
        Hilos::$browser = $this->browser;
        $this->browser->bindHilosFacade(DaemonPlacementTestHilos::class);
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, DaemonPlacementTestHilos::class);
        $this->pageRouter = new PageSignalRouter(
            new HilosPageFactory(new DaemonPlacementTestAgent(), DaemonPlacementTestHilos::class), new ActionRouteConfig(),
        );
        $this->resender = new DaemonPlacementTestResender($this->pageRouter);
        $this->browser->bindPageResender($this->resender);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        AbstractHilosDaemonPlacementPage::onPictureForgotten();
        DaemonPlacementTestPage::$fails = false;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-daemon-placement-');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        AbstractHilosDaemonPlacementPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        $this->previousClusterEnabled === false
            ? putenv('CLUSTER_ENABLED')
            : putenv('CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        Hilos::$table = $this->previousTable;
        Hilos::$db = $this->previousDb;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousAppClass);
        foreach ($this->previousDatabase as $name => $value) {
            (new ReflectionProperty(Database::class, $name))->setValue(null, $value);
        }
        DaemonPlacementTestPage::$fails = false;
        Logger::resetLogFile();
        unlink($this->logFile);
        parent::tearDown();
    }

    public function testDeclarationUsesTheApprovedKeySignalMembershipAndInheritedAdminGate(): void
    {
        self::assertSame('hilos_daemon_placement', AbstractHilosDaemonPlacementPage::PAGE);
        self::assertSame(PageReach::ROUTE, AbstractHilosDaemonPlacementPage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => 'subscription_page_hilos_daemon_placement',
        ], AbstractHilosDaemonPlacementPage::BROWSER);
        self::assertSame([HilosClusterNode::RT_COLLECTION], AbstractHilosDaemonPlacementPage::READS_RT);
        self::assertSame([], AbstractHilosDaemonPlacementPage::READS_DB);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosDaemonPlacementPage::ACCESS_LEVEL);
        self::assertSame(AbstractPage::class,
            (new ReflectionMethod(AbstractHilosDaemonPlacementPage::class, 'onSubscribeBeforeResponse'))->getDeclaringClass()->getName());
        $factory = new HilosPageFactory(new DaemonPlacementTestAgent(), DaemonPlacementTestHilos::class);
        self::assertInstanceOf(DaemonPlacementTestPage::class, $factory->getPage(HilosPageConstants::HILOS_DAEMON_PLACEMENT));
        self::assertTrue((new HilosPageFactory(new DaemonPlacementTestAgent(), Hilos::class))
            ->hasPage(HilosPageConstants::HILOS_DAEMON_PLACEMENT));
    }

    public function testMismatchTabNeedsAKnownClusterMode(): void
    {
        self::assertFalse(AbstractHilosDaemonEnvMismatchPage::isTabApplicable(new PageRouteParams([])));
        Hilos::$cluster = new ClusterContext();
        putenv('CLUSTER_ENABLED=false');
        self::assertFalse(AbstractHilosDaemonEnvMismatchPage::isTabApplicable(new PageRouteParams([])));
        putenv('CLUSTER_ENABLED=true');
        self::assertTrue(AbstractHilosDaemonEnvMismatchPage::isTabApplicable(new PageRouteParams([])));
        putenv('CLUSTER_ENABLED=unknown');
        self::assertFalse(AbstractHilosDaemonEnvMismatchPage::isTabApplicable(new PageRouteParams([])));
    }

    public function testInitialResponseContainsOwnIdentityProjectionAndFirstWindowWithoutDatabase(): void
    {
        $report = $this->report();
        $this->deliver([$this->node($report)]);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, 'primary', 'private-user', 'private-password', 'daemon-db');
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->subscribe('placement');
        $response = $this->onlyResponse();
        self::assertSame(HilosPageConstants::HILOS_DAEMON_PLACEMENT, $response->pageKey);
        self::assertSame([], Hilos::$sr->getAcceptKeysForPage(HilosPageConstants::HILOS_DAEMON));
        self::assertSame('Agent placement', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame([
            'page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon',
            'lead' => 'Nodes and their processes, with agent placement across them.', 'icon' => 'bi-cpu',
        ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_DAEMON_PLACEMENT, 'label' => 'Agent placement'],
        ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_TABS]);
        self::assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_BREADCRUMB, $response->payload->data);
        self::assertSame($this->projection(), $response->payload->data['daemon']);
        self::assertSame(['nodeId' => 'n1', 'commandPort' => 9013, 'httpPort' => 8081],
            $response->payload->data['daemon']['entryPoints']);
        self::assertArrayNotHasKey('database', $response->payload->data);
        self::assertArrayHasKey(HilosDaemonPlacementTable::TABLE, $response->payload->windows);
        self::assertSame(['search' => 'x:1'], Hilos::$sr->getTableViewport('placement', HilosDaemonPlacementTable::TABLE)->filter);
        self::assertSame(['placement'], ClusterDaemonPictureMirror::viewerKeys());
        self::assertSame([], $this->signalRouter->viewersAtResponse);
        self::assertEquals($response, PageResponseSignalData::fromArray($response->toArray()));
    }

    public function testPartialSnapshotEmptySnapshotAndResetKeepUnknownDistinctFromKnown(): void
    {
        $this->deliver([$this->node($this->report())], snapshot: false);
        $this->subscribe('placement');
        self::assertNull($this->onlyResponse()->payload->data['daemon']);
        $this->deliver([]);
        $signals = $this->drain();
        self::assertSame([
            SignalTypeConstants::PAGE_RESPONSE, SignalTypeConstants::TABLE_VIEWPORT_DELTA, SignalTypeConstants::TABLE_VIEWPORT_COUNT,
        ], array_map(static fn ($frame): string => $frame->signalName->getName(), $signals));
        $response = $signals[0]->data->data;
        self::assertSame([
            'clustered' => false, 'nodes' => [], 'consensus' => null,
            'placement' => ['instances' => null, 'types' => 3, 'clusterTypes' => 2, 'unplacedTypes' => null],
            'entryPoints' => null,
        ], $response->payload->data['daemon']);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPlacementPage::onPictureForgotten();
        AbstractHilosDaemonPlacementPage::onPictureChanged(null);
        self::assertSame([], $this->browser->flushToSignalRouter());
        self::assertNull($this->onlyResponse()->payload->data['daemon']);
        $this->deliver([$this->node($this->report())]);
        $signals = $this->drain();
        $responses = array_values(array_filter($signals,
            static fn ($signal): bool => $signal->data->data instanceof PageResponseSignalData));
        self::assertCount(1, $responses);
        self::assertSame($this->projection(), $responses[0]->data->data->payload->data['daemon']);
    }

    public function testClusteredPageUsesTheExistingEntryPointSourceProjection(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->deliver([
            $this->node($this->master('a', ConsensusRole::Leader, 3, 'a', 9101, 8101)),
            $this->node($this->master('b', ConsensusRole::Follower, 3, 'a', 9102, 8102)),
        ]);
        $this->subscribe('placement');
        $initial = $this->onlyResponse();
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_DAEMON_PLACEMENT, 'label' => 'Agent placement'],
            ['page' => HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, 'label' => 'Env mismatch'],
        ], $initial->payload->data[PageCatalogConstants::WIRE_PAGE_TABS]);
        self::assertSame(['nodeId' => 'a', 'commandPort' => 9101, 'httpPort' => 8101],
            $initial->payload->data['daemon']['entryPoints']);
        $this->deliver([
            $this->node($this->master('a', ConsensusRole::Follower, 4, 'b', 9101, 8101)),
            $this->node($this->master('b', ConsensusRole::Leader, 4, 'b', 9102, 8102)),
        ]);
        $changed = $this->onlyResponse()->payload->data['daemon'];
        self::assertSame($this->projection(), $changed);
        self::assertSame(['nodeId' => 'b', 'commandPort' => 9102, 'httpPort' => 8102], $changed['entryPoints']);
        $this->deliver([
            $this->node($this->master('a', ConsensusRole::Candidate, 5, null, null, null)),
            $this->node($this->master('b', ConsensusRole::Leader, 4, 'b', 9102, 8102), online: false),
        ]);
        $withoutLeader = $this->onlyResponse()->payload->data['daemon'];
        self::assertSame($this->projection(), $withoutLeader);
        self::assertNull($withoutLeader['entryPoints']);
        $this->deliver([$this->node($this->master('a', ConsensusRole::Leader, 5, 'a', null, null))]);
        self::assertSame(['nodeId' => 'a', 'commandPort' => null, 'httpPort' => null],
            $this->onlyResponse()->payload->data['daemon']['entryPoints']);
    }

    public function testAdminAndAllowedViewerGetTheSameNotPersonalDataInitiallyAndLive(): void
    {
        $this->deliver([$this->node($this->report())]);
        $this->subscribe('admin');
        $admin = $this->onlyResponse();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $this->drain();
        $this->browser->userId = null;
        $this->browser->admin = false;
        $this->subscribe('viewer');
        $viewer = $this->onlyResponse();
        self::assertSame($admin->payload->data['daemon'], $viewer->payload->data['daemon']);
        self::assertSame($admin->payload->windows, $viewer->payload->windows);
        self::assertArrayNotHasKey('database', $viewer->payload->data);
        $this->deliver([$this->node($this->report()->withStanding(new DaemonNodeStanding(4, 5, false, null, null)))]);
        $updates = $this->drain();
        self::assertCount(2, $updates);
        self::assertSame(['admin', 'viewer'], array_map(static fn ($signal): ?string => $signal->data->targetAcceptKey, $updates));
        foreach ($updates as $update) {
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $update->signalName->getName());
            self::assertSame(['daemon' => $this->projection()], $update->data->data->payload->data);
            self::assertSame(['data'], array_keys($update->data->data->payload->toArray()));
        }
    }

    public function testUnsubscribeAndConnectionCloseReleaseTheSharedViewerLease(): void
    {
        $this->deliver([$this->node($this->report())]);
        $this->subscribe('placement');
        $this->onlyResponse();
        $request = new WebSocketPageUnsubscribeSignalDTO('placement', HilosPageConstants::HILOS_DAEMON_PLACEMENT);
        Hilos::$sr->unsubscribeFromPage(HilosPageConstants::HILOS_DAEMON_PLACEMENT, $request);
        $this->pageRouter->dispatchPageUnsubscribe($request, 'websocket', HilosPageConstants::HILOS_DAEMON_PLACEMENT);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        self::assertNull(Hilos::$sr->getTableViewport('placement', HilosDaemonPlacementTable::TABLE));
        $this->subscribe('closed');
        $this->onlyResponse();
        (new DaemonPlacementTestAgent())->onSignalConnectionClose(new WebSocketCloseSignalDTO('closed'), 'websocket', 'connection_close');
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testFailedResponseDeliveryLeavesNoViewerLease(): void
    {
        $this->deliver([$this->node($this->report())]);
        $this->signalRouter->failResponse = true;
        $this->subscribe('undelivered');
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame(500, $signals[0]->data->data->httpCode);
        self::assertSame([], $this->signalRouter->viewersAtResponse);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('undelivered'));
        $this->signalRouter->failResponse = false;
        $this->deliver([$this->node($this->report())]);
        self::assertArrayHasKey(HilosDaemonPlacementTable::TABLE, $this->onlyResponse()->payload->windows);
        self::assertSame(['undelivered'], ClusterDaemonPictureMirror::viewerKeys());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('undelivered'));
    }

    public function testFailedFirstAnswerAndRefusalAcquireNoLeaseAndRecoveryReacquiresIt(): void
    {
        $this->deliver([$this->node($this->report())]);
        DaemonPlacementTestPage::$fails = true;
        $this->subscribe('failed');
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame(500, $signals[0]->data->data->httpCode);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('failed'));
        $this->deliver([$this->node($this->report()->withStanding(new DaemonNodeStanding(1, 2, false, null, 9013)))]);
        self::assertSame([], $this->drain());
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        DaemonPlacementTestPage::$fails = false;
        $this->deliver([$this->node($this->report())]);
        $recovered = $this->onlyResponse();
        self::assertArrayHasKey(HilosDaemonPlacementTable::TABLE, $recovered->payload->windows);
        self::assertSame(['failed'], ClusterDaemonPictureMirror::viewerKeys());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('failed'));
        $this->browser->admin = false;
        $this->pageRouter->resendPage(HilosPageConstants::HILOS_DAEMON_PLACEMENT, 'failed', []);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame(403, $signals[0]->data->data->httpCode);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->subscribe('refused');
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(403, $signals[0]->data->data->httpCode);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testFingerprintsAndDataPublicationAreIndependentOfTheRoot(): void
    {
        $this->deliver([$this->node($this->report())]);
        $this->subscribe('root', HilosPageConstants::HILOS_DAEMON);
        $this->onlyResponse();
        $this->subscribe('placement');
        $this->onlyResponse();
        $this->subscribe('gone', dispatch: false, connected: false);
        $this->deliver([$this->node($this->report()->withStanding(new DaemonNodeStanding(2, 3, false, null, 9014)))]);
        $updates = $this->drain();
        self::assertCount(1, $updates);
        self::assertSame('placement', $updates[0]->data->targetAcceptKey);
        self::assertSame(HilosPageConstants::HILOS_DAEMON_PLACEMENT, $updates[0]->data->data->pageKey);
        self::assertSame(['daemon' => $this->projection()], $updates[0]->data->data->payload->data);
        AbstractHilosDaemonPage::onPictureChanged(ClusterDaemonPictureMirror::picture());
        $updates = $this->drain();
        self::assertCount(1, $updates);
        self::assertSame('root', $updates[0]->data->targetAcceptKey);
        AbstractHilosDaemonPlacementPage::onPictureChanged(ClusterDaemonPictureMirror::picture());
        self::assertSame([], $this->drain());
        AbstractHilosDaemonPlacementPage::onPictureForgotten();
        AbstractHilosDaemonPlacementPage::onPictureChanged(ClusterDaemonPictureMirror::picture());
        self::assertSame(HilosPageConstants::HILOS_DAEMON_PLACEMENT, $this->onlyResponse()->pageKey);
        AbstractHilosDaemonPage::onPictureChanged(ClusterDaemonPictureMirror::picture());
        self::assertSame([], $this->drain());
    }

    public function testTableMutationsReachOnlyPlacementWindowsBesideTheRoot(): void
    {
        $report = $this->report();
        $this->deliver([$this->node($report)]);
        $this->subscribe('root', HilosPageConstants::HILOS_DAEMON);
        $this->onlyResponse();
        $this->subscribe('placement');
        $this->onlyResponse();
        $this->deliver([$this->node($report->withProcesses($this->roster('x:2')))]);
        $signals = $this->drain();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA, SignalTypeConstants::TABLE_VIEWPORT_COUNT],
            array_map(static fn ($frame): string => $frame->signalName->getName(), $signals));
        foreach ($signals as $signal) {
            self::assertSame('placement', $signal->data->targetAcceptKey);
        }
        self::assertSame('row_removed', $signals[0]->data->data->kind);
        self::assertSame('left_set', $signals[0]->data->data->reason);
        self::assertSame(0, $signals[1]->data->data->totalCount);
        self::assertSame([], $this->resender->attempts);
    }

    public function testProductionAgentInvokesPlacementPublisherAndStopResetsItsFingerprint(): void
    {
        $this->deliver([$this->node($this->report())]);
        $this->subscribe('placement');
        $this->onlyResponse();
        $portion = new DaemonClusterPicturePortionSignalData(true, [
            $this->node($this->report()->withStanding(new DaemonNodeStanding(1, 2, false, null, 9014))),
        ]);
        (new DaemonPlacementTestAgent())->onSignalAgent(new AgentSignalData($portion), 'collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([], $this->browser->flushToSignalRouter());
        self::assertSame(['nodeId' => 'n1', 'commandPort' => 9014, 'httpPort' => 8081],
            $this->onlyResponse()->payload->data['daemon']['entryPoints']);
        $agent = new DaemonPlacementTestAgent();
        $agent->onStop();
        self::assertFalse(ClusterDaemonPictureMirror::hasFullSnapshot());
        $agent->onSignalAgent(new AgentSignalData($portion), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame(['nodeId' => 'n1', 'commandPort' => 9014, 'httpPort' => 8081],
            $this->onlyResponse()->payload->data['daemon']['entryPoints']);
    }

    public function testPlacementPayloadAndPublisherNeverReadDatabaseMetadata(): void
    {
        $source = file_get_contents((new ReflectionMethod(AbstractHilosDaemonPlacementPage::class, 'buildPagePayload'))->getFileName());
        self::assertStringNotContainsString('Database::', $source);
        self::assertStringNotContainsString('HilosDaemonDatabaseReader', $source);
        self::assertStringNotContainsString('Hilos::$db', $source);
    }

    /** @return array<string, mixed> The existing whole-picture projection */
    private function projection(): array
    {
        return HilosDaemonOverviewData::fromPicture(ClusterDaemonPictureMirror::picture(), Hilos::$cluster !== null,
            ClusterDaemonPictureMirror::hasFullSnapshot())->toArray();
    }

    /** @return NodeDaemonPicture Standalone report with distinct command and HTTP ports */
    private function report(): NodeDaemonPicture
    {
        return new NodeDaemonPicture('n1', NodeRole::Master, 100, $this->roster('x:1'),
            http: new DaemonHttpPicture('127.0.0.1', 8081, 1, [], new HttpRouteTally(0, 0, 0, null)),
            standing: new DaemonNodeStanding(0, 0, false, null, 9013));
    }

    /** @return NodeDaemonPicture Clustered master with its own entry points */
    private function master(string $id, ConsensusRole $role, int $term, ?string $leaderId, ?int $commandPort, ?int $httpPort): NodeDaemonPicture
    {
        return new NodeDaemonPicture($id, NodeRole::Master, 100,
            standing: new DaemonNodeStanding(1, 2, true, new DaemonConsensusPicture($role, $term, $leaderId, 1, 3, 2), $commandPort),
            http: $httpPort === null ? null : new DaemonHttpPicture('127.0.0.1', $httpPort, 1, [], new HttpRouteTally(0, 0, 0, null)));
    }

    /** @return ClusterDaemonNodeView One whole source slot */
    private function node(NodeDaemonPicture $picture, bool $online = true): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($picture->nodeId, $online, new ClusterDaemonNodeSlot($picture->nodeId, $picture, 100));
    }

    /** @return DaemonProcessRoster One actual worker with the supplied instances */
    private function roster(string ...$ids): DaemonProcessRoster
    {
        sort($ids, SORT_STRING);
        return new DaemonProcessRoster([new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 1048576,
            array_map(static fn (string $id): DaemonAgentPicture => new DaemonAgentPicture(
                $id, DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY,
            ), $ids))], [], 0);
    }

    /** @param list<ClusterDaemonNodeView> $nodes Whole slots received through the existing IPC payload */
    private function deliver(array $nodes, bool $snapshot = true): void
    {
        $before = ClusterDaemonPictureMirror::picture();
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray($portion->toArray()));
        AbstractHilosDaemonPlacementPage::onPictureChanged($before);
        self::assertSame([], $this->browser->flushToSignalRouter());
    }

    private function subscribe(string $key, string $page = HilosPageConstants::HILOS_DAEMON_PLACEMENT,
        bool $dispatch = true, bool $connected = true): void
    {
        $request = new WebSocketPageSubscribeSignalDTO($key, $page, tableWindows: [
            HilosDaemonPlacementTable::TABLE => new TableWindowDescriptorDTO(filter: [TableConstants::FILTER_KEY_SEARCH => 'x:1'], limit: 7),
        ]);
        Hilos::$sr->subscribeToPage($page, $request);
        if ($connected) {
            Hilos::$rt->connectionsSource()->add(DaemonPlacementTestConnection::create($key, null));
        }
        if ($dispatch) {
            $this->pageRouter->dispatchPageSubscribe($request, 'websocket', $page);
        }
    }

    /** @return PageResponseSignalData Exactly one response without a companion header or window frame */
    private function onlyResponse(): PageResponseSignalData
    {
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signals[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signals[0]->data->data);
        return $signals[0]->data->data;
    }

    /** @return list<SignalDTO> Queued frames */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }
}

final class DaemonPlacementTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonPlacementTestPage extends AbstractHilosDaemonPlacementPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AbstractHilosDaemonAgent::AGENT_TYPE;
    public static bool $fails = false;

    /** @return PagePayload Real placement data; @throws HilosException On an injected assembly failure */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        if (self::$fails) {
            throw new HilosException('Placement assembly failed');
        }
        return parent::buildPagePayload($acceptKey, $params);
    }
}

final class DaemonPlacementTestResender implements PageResender
{
    /** @var list<array{string, string}> Whole-page attempts, including contained refusals and failures */
    public array $attempts = [];

    /** @var list<array{string, string}> Deletion refusals asked of this double */
    public array $deletions = [];

    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        $this->attempts[] = [$page, $acceptKey];
        return $this->router->resendPage($page, $acceptKey, $params);
    }

    /**
     * The deletion port is not this double. It forms no refusal frame of its own.
     *
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the refusal would be addressed to
     * @return PageResendOutcome Unserved, because this double does not refuse a deleted row
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        $this->deletions[] = [$page, $acceptKey];

        return PageResendOutcome::Unserved;
    }
}

final class DaemonPlacementTestBrowser extends BrowserContext
{
    public ?int $userId = 1;
    public bool $admin = true;

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }

    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

abstract class DaemonPlacementTestHilos extends Hilos
{
    public const array AGENTS = [
        'x' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'leader1' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'leader' => [],
    ];
    public const array PAGES = [
        HilosPageConstants::HILOS_DAEMON => DaemonPlacementTestRootPage::class,
        HilosPageConstants::HILOS_DAEMON_PLACEMENT => DaemonPlacementTestPage::class,
        HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH => DaemonPlacementTestMismatchPage::class,
    ];
    public const array PAGE_TABLES = [
        HilosPageConstants::HILOS_DAEMON_PLACEMENT => [HilosDaemonPlacementTable::TABLE => []],
    ];

}

final class DaemonPlacementTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosDaemonPlacementTable::TABLE, new HilosDaemonPlacementTable());
    }
}

final class DaemonPlacementTestConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    protected function hydrateOwn(array $row): void
    {
    }

    protected function ownToArray(): array
    {
        return [];
    }

    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonPlacementTestConnection> */
final class DaemonPlacementTestConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonPlacementTestConnection::class;
}

final class DaemonPlacementTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections['daemonPlacementTestConnections'] = DaemonPlacementTestConnections::init();
    }
}

/** Root retains its own data publisher without a placement table. */
final class DaemonPlacementTestRootPage extends AbstractHilosDaemonPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AbstractHilosDaemonAgent::AGENT_TYPE;
}

final class DaemonPlacementTestMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AbstractHilosDaemonAgent::AGENT_TYPE;
}

/** Observes admission ordering and injects a failure at the response delivery boundary. */
final class DaemonPlacementTestSignalRouter extends SignalRouter
{
    public bool $failResponse = false;

    /** @var list<string> Shared viewers before the last response was queued */
    public array $viewersAtResponse = [];

    /**
     * @param SignalSourceInterface $signalSource Originating agent or worker
     * @param SignalTypeInterface $signalType Addressed signal kind
     * @param SignalNameInterface $signalName Response or other signal name
     * @param SignalDataInterface $signalData Addressed payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if ($signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
            $this->viewersAtResponse = ClusterDaemonPictureMirror::viewerKeys();
            if ($this->failResponse) {
                throw new HilosException('Injected page response delivery failure');
            }
        }
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }

    /** @return class-string<Hilos> The registered fixture pages used by the head resolver */
    protected function hilosClass(): string
    {
        return DaemonPlacementTestHilos::class;
    }
}
