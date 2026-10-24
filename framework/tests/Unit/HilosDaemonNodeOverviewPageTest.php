<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Context\TableContext;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodeOverviewPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodePage;
use Hilos\Pages\Daemon\DTO\HilosDaemonNodeOverviewData;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Additive node diagnostics through the actual factory, subscription guard and live page_response path. */
final class HilosDaemonNodeOverviewPageTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?TableContext $previousTables;
    private string|false $previousClusterEnabled;
    private string $previousAppClass;
    private string $logFile;
    private NodeOverviewTestBrowser $browser;
    private PageSignalRouter $pageRouter;
    private HilosPageFactory $factory;
    private NodeOverviewTestAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousTables = Hilos::$table;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        $this->previousAppClass = Hilos::appClass();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new NodeOverviewTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = null;
        Hilos::$table = null;
        Hilos::$browser = $this->browser = new NodeOverviewTestBrowser();
        $this->browser->bindHilosFacade(NodeOverviewTestHilos::class);
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, NodeOverviewTestHilos::class);
        $this->agent = new NodeOverviewTestAgent();
        $this->factory = new HilosPageFactory($this->agent, NodeOverviewTestHilos::class);
        $this->pageRouter = new PageSignalRouter($this->factory, new ActionRouteConfig());
        $this->browser->bindPageResender(new NodeOverviewTestResender($this->pageRouter));
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonNodeOverviewPage::onPictureForgotten();
        NodeOverviewTestPage::$fails = false;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-node-overview-');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonNodeOverviewPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$table = $this->previousTables;
        $this->previousClusterEnabled === false ? putenv('CLUSTER_ENABLED')
            : putenv('CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousAppClass);
        NodeOverviewTestPage::$fails = false;
        Logger::resetLogFile();
        unlink($this->logFile);
        parent::tearDown();
    }

    public function testProjectionWaitsForFullSnapshotAndNeverSubstitutesAnotherNode(): void
    {
        $picture = ClusterDaemonPicture::empty()->withNode($this->node($this->report('a')));
        self::assertNull(HilosDaemonNodeOverviewData::fromPicture(null, 'a', true, true));
        self::assertNull(HilosDaemonNodeOverviewData::fromPicture($picture, 'a', true, false));
        self::assertNull(HilosDaemonNodeOverviewData::fromPicture($picture, 'missing', true, true));
        self::assertNull(HilosDaemonNodeOverviewData::fromPicture(ClusterDaemonPicture::empty(), 'a', true, true));
        self::assertSame('a', $this->projection($picture, 'a')['nodeId']);
    }

    public function testKnownNodeWithoutSlotKeepsEveryUnknownSectionNull(): void
    {
        foreach ([true, false] as $online) {
            $picture = ClusterDaemonPicture::empty()->withNode(new ClusterDaemonNodeView('no-report', $online, null));
            self::assertSame([
                'nodeId' => 'no-report', 'online' => $online,
                'workers' => null, 'workerRestarts24h' => null, 'uptimeMinutes' => null,
                'measurements' => null, 'standing' => null, 'environment' => null,
                'environmentComparison' => ['answeringNodes' => 0, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
            ], $this->projection($picture, 'no-report'));
        }
    }

    public function testProjectionKeepsFullMeasurementsAndSafeCountsOfOnlyTheAddressedNode(): void
    {
        $report = $this->report('b');
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node(new NodeDaemonPicture('a', NodeRole::Master, 100)))
            ->withNode($this->node($report));
        $overview = $this->projection($picture, 'b');
        self::assertSame([
            'nodeId' => 'b', 'online' => true,
            'workers' => [['index' => 1, 'kind' => 'regular', 'agentCount' => 1],
                ['index' => 4, 'kind' => 'monopolistic', 'agentCount' => 0]],
            'workerRestarts24h' => 0, 'uptimeMinutes' => 2,
            'measurements' => [
                'startedAt' => 100, 'cores' => 2.5, 'coresBound' => 'quota', 'daemonCores' => 0.0,
                'masterCores' => 0.0, 'machineLoad' => 0, 'masterMemory' => 1048576, 'workersMemory' => 2097152,
                'workersMeasured' => 2, 'memoryCeiling' => 1073741824, 'memoryCeilingBound' => 'cgroup',
                'phpMemoryLimit' => 134217728,
            ],
            'standing' => ['sessions' => 0, 'connections' => 0],
            'environment' => ['missingRequired' => 0, 'fromExample' => 2, 'drifted' => 3, 'orphans' => 4],
            'environmentComparison' => ['answeringNodes' => 1, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
        ], $overview);
        self::assertEquals($report->measurements, DaemonStatusDTO::measurementsFromArray($overview['measurements']));
        $wire = json_encode($overview, JSON_THROW_ON_ERROR);
        foreach (['role', 'state', 'clustered', 'APP_ENV', 'aaaaaaaaaaaaaaaa', 'fingerprints',
            'catalogKeys', 'hidden:', 'memoryBytes', 'pid'] as $key) {
            self::assertStringNotContainsString($key, $wire);
        }
    }

    public function testOfflineKeepsLastSampleAndUptimeDoesNotAdvanceWithReceiptTime(): void
    {
        $report = $this->report('a');
        $picture = ClusterDaemonPicture::empty()->withNode($this->node($report, false, 99999));
        $overview = $this->projection($picture, 'a');
        self::assertFalse($overview['online']);
        self::assertSame(2, $overview['uptimeMinutes']);
        self::assertSame(DaemonStatusDTO::measurementsToArray($report->measurements), $overview['measurements']);
        $picture = $picture->withNode($this->node($report->sampledAt(50)));
        self::assertSame(0, $this->projection($picture, 'a')['uptimeMinutes']);
    }

    public function testUnknownMeasurementsStayNullAndReportedEmptyWorkersAndZeroStayKnown(): void
    {
        $report = new NodeDaemonPicture('a', NodeRole::Slave, 100,
            new DaemonProcessRoster([], [], 0), measurements: new DaemonMeasurements(
                100, null, null, null, null, null, null, null, 0, null, null, null,
            ));
        $overview = $this->projection(ClusterDaemonPicture::empty()->withNode($this->node($report)), 'a');
        self::assertSame([], $overview['workers']);
        self::assertSame(0, $overview['workerRestarts24h']);
        self::assertSame(0, $overview['uptimeMinutes']);
        self::assertSame(0, $overview['measurements']['workersMeasured']);
        foreach (['cores', 'coresBound', 'daemonCores', 'masterCores', 'machineLoad', 'masterMemory',
            'workersMemory', 'memoryCeiling', 'memoryCeilingBound', 'phpMemoryLimit'] as $field) {
            self::assertNull($overview['measurements'][$field], $field);
        }
    }

    public function testComparisonIsClusterWideAndUnknownOnStandaloneOrWithTooFewAnswers(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node($this->report('a')))
            ->withNode($this->node($this->report('b')->withEnvironment($this->environment('bbbbbbbbbbbbbbbb'))));
        foreach (['a', 'b'] as $id) {
            self::assertSame(['answeringNodes' => 2, 'divergedKeys' => 1, 'sourcesDifferKeys' => 0],
                $this->projection($picture, $id)['environmentComparison']);
            self::assertSame(['answeringNodes' => 2, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
                $this->projection($picture, $id, false)['environmentComparison']);
        }
        $picture = $picture->withNode($this->node($this->report('b'), false));
        self::assertSame(['answeringNodes' => 1, 'divergedKeys' => null, 'sourcesDifferKeys' => null],
            $this->projection($picture, 'b')['environmentComparison']);
        $picture = $picture->withNode($this->node($this->report('b')->withEnvironment($this->environment('aaaaaaaaaaaaaaaa', EnvSource::EXAMPLE))));
        self::assertSame(['answeringNodes' => 2, 'divergedKeys' => 0, 'sourcesDifferKeys' => 1],
            $this->projection($picture, 'a')['environmentComparison']);
    }

    public function testColdAddressReceivesNodeAndOverviewTogetherWithoutRootOrTables(): void
    {
        $this->deliver([$this->node($this->report('a')), $this->node($this->report('b'))]);
        $this->subscribe('reader', 'b');
        $response = $this->onlyResponse();
        self::assertSame(HilosPageConstants::HILOS_DAEMON_NODE, $response->pageKey);
        self::assertSame([
            'node', 'overview', 'pageLabel', 'pageLead', 'pageBreadcrumb', 'pageChildren', 'pageHeadOwner', 'pageTabs',
        ], array_keys($response->payload->data));
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'master'], $response->payload->data['node']);
        self::assertSame([
            'page' => HilosPageConstants::HILOS_DAEMON_NODE,
            'label' => 'Node',
            'lead' => 'One node: processor, memory, environment summary, and maintenance.',
            'icon' => 'bi-hdd-network',
        ], $response->payload->data['pageHeadOwner']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon'],
            ['page' => HilosPageConstants::HILOS_DAEMON_NODE, 'label' => 'Node'],
        ], $response->payload->data['pageBreadcrumb']);
        self::assertSame([], $response->payload->data['pageChildren']);
        self::assertSame('b', $response->payload->data['overview']['nodeId']);
        self::assertSame(2, $response->payload->data['overview']['uptimeMinutes']);
        self::assertSame(['data'], array_keys($response->payload->toArray()));
        self::assertEquals($response, PageResponseSignalData::fromArray($response->toArray()));
        self::assertSame(['reader'], ClusterDaemonPictureMirror::viewerKeys());
        self::assertSame([], Hilos::$sr->getAcceptKeysForPage(HilosPageConstants::HILOS_DAEMON));
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosDaemonNodeOverviewPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosDaemonNodeOverviewPage::REACH);
        self::assertSame([HilosClusterNode::RT_COLLECTION], AbstractHilosDaemonNodeOverviewPage::READS_RT);
        self::assertSame([], AbstractHilosDaemonNodeOverviewPage::SUBSCRIPTION_AGENT_INDEX);
    }

    public function testPartialPicturesNeverReleaseOverviewAndFullEmptyAndMissingStayNull(): void
    {
        $this->subscribe('reader', 'a');
        self::assertNull($this->onlyResponse()->payload->data['overview']);
        $this->deliver([$this->node($this->report('a'))], false);
        $partial = $this->onlyResponse()->payload->data;
        self::assertSame(['node', 'overview'], array_keys($partial));
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'master'], $partial['node']);
        self::assertNull($partial['overview']);
        $this->deliver([]);
        $empty = $this->onlyResponse()->payload->data;
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => null], $empty['node']);
        self::assertNull($empty['overview']);
        $this->deliver([$this->node($this->report('b'))]);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($this->report('a'))]);
        self::assertSame('a', $this->onlyResponse()->payload->data['overview']['nodeId']);
    }

    public function testFullSnapshotRemovingTheAddressedNodePublishesNullAndRecoveryPublishesItsReport(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        $this->deliver([]);
        self::assertNull($this->onlyResponse()->payload->data['overview']);
        $this->deliver([$this->node($this->report('a'), false, 777)]);
        $response = $this->onlyResponse();
        self::assertSame(['clustered' => false, 'state' => 'silent', 'silentSince' => 777000, 'role' => 'master'], $response->payload->data['node']);
        self::assertFalse($response->payload->data['overview']['online']);
        self::assertSame(2, $response->payload->data['overview']['uptimeMinutes']);
    }

    public function testLiveChangesAreAddressedDataOnlyAndKeepOtherNodesAndWindowsAlone(): void
    {
        $a = $this->report('a');
        $b = $this->report('b');
        $this->deliver([$this->node($a), $this->node($b)]);
        $this->subscribe('alice', 'a');
        $this->onlyResponse();
        $this->subscribe('bob', 'b');
        $this->onlyResponse();
        $viewport = new TableViewportSubscription('unrelated', filter: ['search' => 'retained'], limit: 7);
        Hilos::$sr->setTableViewport('alice', $viewport);
        $this->deliver([$this->node($b->sampledAt(400))], false);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame('bob', $signals[0]->data->targetAcceptKey);
        self::assertSame(['data'], array_keys($signals[0]->data->data->payload->toArray()));
        self::assertSame(5, $signals[0]->data->data->payload->data['overview']['uptimeMinutes']);
        self::assertSame($viewport, Hilos::$sr->getTableViewport('alice', 'unrelated'));
        $this->deliver([$this->node($b->sampledAt(400))], false);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($b->sampledAt(401))], false);
        self::assertSame([], $this->drain());
    }

    public function testAnotherNodesComparisonChangeReachesBothOverviewsWithoutReplacingTheirOwnMetrics(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $a = $this->report('a');
        $b = $this->report('b');
        $this->deliver([$this->node($a), $this->node($b)]);
        $this->subscribe('alice', 'a');
        $first = $this->onlyResponse()->payload->data['overview'];
        $this->subscribe('bob', 'b');
        $this->onlyResponse();
        $this->deliver([$this->node($b->withEnvironment($this->environment('bbbbbbbbbbbbbbbb')))], false);
        $signals = $this->drain();
        self::assertCount(2, $signals);
        foreach ($signals as $signal) {
            $overview = $signal->data->data->payload->data['overview'];
            self::assertSame(1, $overview['environmentComparison']['divergedKeys']);
            self::assertSame($first['measurements'], $overview['measurements']);
            self::assertSame($signal->data->targetAcceptKey === 'alice' ? 'a' : 'b', $overview['nodeId']);
        }
    }

    public function testAdminAndViewModeViewerReceiveBothInitialAndLiveFieldsUnhidden(): void
    {
        $report = $this->report('a');
        $this->deliver([$this->node($report)]);
        $this->subscribe('admin', 'a');
        $admin = $this->onlyResponse();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $this->drain();
        $this->browser->userId = null;
        $this->browser->admin = false;
        $this->subscribe('viewer', 'a');
        $viewer = $this->onlyResponse()->payload->data;
        self::assertSame([
            'pageLabel', 'pageLead', 'pageBreadcrumb', 'pageChildren', 'pageHeadOwner', 'pageTabs', 'node', 'overview',
        ], array_keys($viewer));
        foreach ($admin->payload->data as $key => $value) {
            self::assertSame($value, $viewer[$key], $key);
        }
        $this->deliver([$this->node($report->sampledAt(460))], false);
        $signals = $this->drain();
        self::assertCount(2, $signals);
        foreach ($signals as $signal) {
            self::assertSame(6, $signal->data->data->payload->data['overview']['uptimeMinutes']);
            self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'master'],
                $signal->data->data->payload->data['node']);
            self::assertStringNotContainsString('_hidden', json_encode($signal->data->data->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public function testUnknownNodeAndGuestAreRefusedWithoutDataOrViewerLease(): void
    {
        $this->subscribe('unknown', 'missing', known: false);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame(404, $signals[0]->data->data->httpCode);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->browser->userId = null;
        $this->browser->admin = false;
        $this->subscribe('guest', 'a');
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(401, $signals[0]->data->data->httpCode);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testAddressChangeUnsubscribeAndStopReleaseComparisonState(): void
    {
        $this->deliver([$this->node($this->report('a')), $this->node($this->report('b'))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        self::assertSame(['a'], array_keys($this->fingerprints()));
        $this->known('b');
        $update = new WebSocketPageUpdateSubscriptionSignalDTO('reader', HilosPageConstants::HILOS_DAEMON_NODE, ['nodeId' => 'b']);
        Hilos::$sr->updatePageSubscription(HilosPageConstants::HILOS_DAEMON_NODE, $update);
        $this->pageRouter->dispatchPageUpdateSubscription($update, 'websocket', HilosPageConstants::HILOS_DAEMON_NODE);
        self::assertSame('b', $this->onlyResponse()->payload->data['overview']['nodeId']);
        self::assertSame(['b'], array_keys($this->fingerprints()));
        self::assertSame(['reader'], ClusterDaemonPictureMirror::viewerKeys());
        $this->leave('reader');
        self::assertSame([], $this->fingerprints());
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        $this->agent->onStop();
        self::assertNull(ClusterDaemonPictureMirror::picture());
        self::assertFalse(ClusterDaemonPictureMirror::hasFullSnapshot());
        self::assertSame([], $this->fingerprints());
        $this->deliver([$this->node($this->report('a'))]);
        self::assertSame('a', $this->onlyResponse()->payload->data['overview']['nodeId']);
        $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('reader'), 'websocket', 'connection_close');
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testLeavingOneViewerRetainsTheOtherViewersComparison(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        foreach (['one', 'two'] as $key) {
            $this->subscribe($key, 'a');
            $this->onlyResponse();
        }
        $this->leave('one');
        self::assertSame(['a'], array_keys($this->fingerprints()));
        self::assertSame(['two'], ClusterDaemonPictureMirror::viewerKeys());
        $this->deliver([$this->node($this->report('a'))]);
        self::assertSame([], $this->drain());
        $this->leave('two');
        self::assertSame([], $this->fingerprints());
    }

    public function testWholePageDebtRecoversThroughFullSubscribeEvenAfterFailureReleasedTheLease(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        Hilos::$sr->markPageDeliveryFailure('reader');
        NodeOverviewTestPage::$fails = true;
        $this->deliver([$this->node($this->report('a')->sampledAt(400))]);
        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('reader'));
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        NodeOverviewTestPage::$fails = false;
        $this->deliver([$this->node($this->report('a')->sampledAt(460))]);
        $response = $this->onlyResponse();
        self::assertSame(6, $response->payload->data['overview']['uptimeMinutes']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('reader'));
        self::assertSame(['reader'], ClusterDaemonPictureMirror::viewerKeys());
    }

    public function testKnownFactoryIdDoesNotActivateThePageInAnUnboundProject(): void
    {
        $factory = new HilosPageFactory($this->agent, NodeOverviewUnboundTestHilos::class);
        self::assertTrue($factory->hasPage('hilos_daemon_node'));
        self::assertSame('subscription_page_hilos_daemon_node', HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_NODE);
        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('requires implementation in project');
        $factory->getPage('hilos_daemon_node');
    }

    /** @return array<string, mixed> Full projection of one known node */
    private function projection(ClusterDaemonPicture $picture, string $nodeId, bool $clustered = true): array
    {
        $overview = HilosDaemonNodeOverviewData::fromPicture($picture, $nodeId, $clustered, true);
        self::assertNotNull($overview);
        return $overview->toArray();
    }

    /** @return NodeDaemonPicture Report with zeroes, complete measurements and private fields to exclude */
    private function report(string $nodeId): NodeDaemonPicture
    {
        return new NodeDaemonPicture($nodeId, NodeRole::Master, 279, new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 1048576, [
                new DaemonAgentPicture('hidden:1', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
            new DaemonWorkerPicture(4, WorkerConstants::TYPE_MONOPOLISTIC, null, null, []),
        ], ['hidden:lost'], 0), environment: $this->environment(), standing: new DaemonNodeStanding(0, 0, false, null),
            measurements: new DaemonMeasurements(100, 2.5, DaemonMeasurements::CORES_QUOTA, 0.0, 0.0, 0,
                1048576, 2097152, 2, 1073741824, DaemonMeasurements::CEILING_CGROUP, 134217728));
    }

    /** @return NodeEnvironmentSummary Value-free summary with a comparison label that must not reach Overview */
    private function environment(string $digest = 'aaaaaaaaaaaaaaaa', EnvSource $source = EnvSource::PROCESS): NodeEnvironmentSummary
    {
        return new NodeEnvironmentSummary(9, 0, 2, 3, 4, [
            new NodeEnvironmentFingerprint('APP_ENV', 'string', $source, false, $digest),
        ]);
    }

    /** @return ClusterDaemonNodeView Report in its membership slot */
    private function node(NodeDaemonPicture $report, bool $online = true, int $receivedAt = 300): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($report->nodeId, $online, new ClusterDaemonNodeSlot($report->nodeId, $report, $receivedAt));
    }

    /** @param list<ClusterDaemonNodeView> $nodes Whole slots crossing the real IPC roundtrip */
    private function deliver(array $nodes, bool $snapshot = true): void
    {
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $this->agent->onSignalAgent(new AgentSignalData(DaemonClusterPicturePortionSignalData::fromArray($portion->toArray())),
            'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([], $this->browser->flushToSignalRouter());
    }

    private function known(string $nodeId): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish($nodeId, NodeRole::Master->value, [], null, true, 100.0);
    }

    private function subscribe(string $key, string $nodeId, bool $known = true): void
    {
        if ($known) {
            $this->known($nodeId);
        }
        Hilos::$rt->connectionsSource()->add(NodeOverviewTestConnection::create($key, null));
        $request = new WebSocketPageSubscribeSignalDTO($key, HilosPageConstants::HILOS_DAEMON_NODE,
            [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]);
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_DAEMON_NODE, $request);
        $this->pageRouter->dispatchPageSubscribe($request, 'websocket', HilosPageConstants::HILOS_DAEMON_NODE);
    }

    private function leave(string $key): void
    {
        $request = new WebSocketPageUnsubscribeSignalDTO($key, HilosPageConstants::HILOS_DAEMON_NODE);
        Hilos::$sr->unsubscribeFromPage(HilosPageConstants::HILOS_DAEMON_NODE, $request);
        $this->pageRouter->dispatchPageUnsubscribe($request, 'websocket', HilosPageConstants::HILOS_DAEMON_NODE);
    }

    /** @return array<string, string> Addressed fingerprints of only the new page */
    private function fingerprints(): array
    {
        return (new ReflectionProperty(AbstractHilosDaemonNodePage::class, 'nodeDataFingerprints'))->getValue()
            [HilosPageConstants::HILOS_DAEMON_NODE] ?? [];
    }

    /** @return PageResponseSignalData The only frame, carrying both node sections */
    private function onlyResponse(): PageResponseSignalData
    {
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signals[0]->signalName->getName());
        self::assertInstanceOf(PageResponseSignalData::class, $signals[0]->data->data);
        return $signals[0]->data->data;
    }

    /** @return list<SignalDTO> Frames queued for browser delivery */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }
}

final class NodeOverviewTestAgent extends AbstractHilosDaemonAgent
{
}

final class NodeOverviewTestPage extends AbstractHilosDaemonNodeOverviewPage
{
    public static bool $fails = false;

    /** @return PagePayload Real node data with an injectable assembly failure */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        if (self::$fails) {
            throw new HilosException('Node overview assembly failed');
        }
        return parent::buildPagePayload($acceptKey, $params);
    }
}

abstract class NodeOverviewTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_DAEMON_NODE => NodeOverviewTestPage::class];
}

abstract class NodeOverviewUnboundTestHilos extends Hilos
{
}

final class NodeOverviewTestBrowser extends BrowserContext
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

final class NodeOverviewTestResender implements PageResender
{
    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        return $this->router->resendPage($page, $acceptKey, $params);
    }

    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return PageResendOutcome::Unserved;
    }
}

final class NodeOverviewTestConnection extends HilosConnection
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

/** @extends HilosConnections<NodeOverviewTestConnection> */
final class NodeOverviewTestConnections extends HilosConnections
{
    public const string STATE_CLASS = NodeOverviewTestConnection::class;
}

final class NodeOverviewTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections['nodeOverviewTestConnections'] = NodeOverviewTestConnections::init();
    }
}
