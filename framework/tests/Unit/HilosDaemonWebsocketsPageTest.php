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
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalDataInterface;
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
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonWebsocketsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonNodePage;
use Hilos\Pages\Daemon\DTO\HilosDaemonWebsocketsData;
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

/** Addressed WebSocket counts through the actual factory, subscription guard and live page_response path. */
final class HilosDaemonWebsocketsPageTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?TableContext $previousTables;
    private string|false $previousClusterEnabled;
    private string $previousAppClass;
    private string $logFile;
    private WebsocketsTestBrowser $browser;
    private PageSignalRouter $pageRouter;
    private HilosPageFactory $factory;
    private WebsocketsTestAgent $agent;

    /** Mounts isolated facade bindings while retaining the prior process context. */
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
        Hilos::$sr = new WebsocketsTestSignalRouter();
        Hilos::$rt = new WebsocketsTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = null;
        Hilos::$table = null;
        Hilos::$browser = $this->browser = new WebsocketsTestBrowser();
        $this->browser->bindHilosFacade(WebsocketsTestHilos::class);
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, WebsocketsTestHilos::class);
        $this->agent = new WebsocketsTestAgent();
        $this->factory = new HilosPageFactory($this->agent, WebsocketsTestHilos::class);
        $this->pageRouter = new PageSignalRouter($this->factory, new ActionRouteConfig());
        $this->browser->bindPageResender(new WebsocketsTestResender($this->pageRouter));
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonWebsocketsPage::onPictureForgotten();
        WebsocketsTestPage::$fails = false;
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-websockets-');
        Logger::setLogFile($this->logFile);
    }

    /** Releases fixture claims and restores the prior facade and logger. */
    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonWebsocketsPage::onPictureForgotten();
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
        WebsocketsTestPage::$fails = false;
        Logger::resetLogFile();
        unlink($this->logFile);
        parent::tearDown();
    }

    /** An unrelated slot cannot supply a missing report. */
    public function testProjectionWaitsForFullSnapshotAndAddressedReport(): void
    {
        $picture = ClusterDaemonPicture::empty()->withNode($this->node($this->report('a')));
        self::assertNull(HilosDaemonWebsocketsData::fromPicture(null, 'a', true, true));
        self::assertNull(HilosDaemonWebsocketsData::fromPicture($picture, 'a', true, false));
        self::assertNull(HilosDaemonWebsocketsData::fromPicture($picture, 'missing', true, true));
        self::assertNull(HilosDaemonWebsocketsData::fromPicture(ClusterDaemonPicture::empty(), 'a', true, true));
        foreach ([true, false] as $online) {
            $missingReport = $picture->withNode(new ClusterDaemonNodeView('b', $online, null));
            self::assertNull(HilosDaemonWebsocketsData::fromPicture($missingReport, 'b', true, true));
        }
        self::assertSame(['connections' => 7, 'dataNode' => false], $this->projection($picture, 'a'));
    }

    /** The exact wire shape excludes every unrelated report section. */
    public function testProjectionSelectsOnlyTheAddressedCountAndPreservesKnownZero(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node($this->report('a', 99)))
            ->withNode($this->node($this->report('b', 0)));
        self::assertSame(['connections' => 0, 'dataNode' => false], $this->projection($picture, 'b'));
        self::assertSame(['connections' => 99, 'dataNode' => false], $this->projection($picture, 'a'));
        $picture = $picture->withNode($this->node($this->report('b')->withStanding(null)));
        self::assertSame(['connections' => null, 'dataNode' => false], $this->projection($picture, 'b'));
    }

    /** Membership does not erase the last reported role or browser count. */
    public function testDataNodeIsDerivedFromClusterModeAndRetainedRoleEvenWhenSilent(): void
    {
        foreach ([true, false] as $online) {
            foreach ([0, 12] as $connections) {
                $picture = ClusterDaemonPicture::empty()->withNode($this->node($this->report('a', $connections, NodeRole::Slave), $online));
                self::assertSame(['connections' => null, 'dataNode' => true], $this->projection($picture, 'a'));
                self::assertSame(['connections' => $connections, 'dataNode' => false], $this->projection($picture, 'a', false));
            }
            $picture = ClusterDaemonPicture::empty()
                ->withNode($this->node($this->report('a', 15), $online));
            self::assertSame(['connections' => 15, 'dataNode' => false], $this->projection($picture, 'a'));
        }
        $picture = ClusterDaemonPicture::empty()->withNode($this->node(new NodeDaemonPicture('a', NodeRole::Slave, 100)));
        self::assertSame(['connections' => null, 'dataNode' => true], $this->projection($picture, 'a'));
        self::assertSame(['connections' => null, 'dataNode' => false], $this->projection($picture, 'a', false));
    }

    /** The first answer goes through the project factory and inherited subscription hooks. */
    public function testColdSubscriptionAnswersOnceWithOwnDataAndSharedHeadWithoutRootOrTables(): void
    {
        $this->deliver([$this->node($this->report('a', 99)), $this->node($this->report('b', 0))]);
        $this->subscribe('reader', 'b');
        $response = $this->onlyResponse();
        self::assertSame(HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, $response->pageKey);
        self::assertSame([
            'node', 'websockets', 'pageLabel', 'pageLead', 'pageBreadcrumb', 'pageChildren', 'pageHeadOwner', 'pageTabs',
        ], array_keys($response->payload->data));
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'master'], $response->payload->data['node']);
        self::assertSame(['connections' => 0, 'dataNode' => false], $response->payload->data['websockets']);
        self::assertSame(HilosPageConstants::HILOS_DAEMON_NODE, $response->payload->data['pageHeadOwner']['page']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_DAEMON, 'label' => 'Daemon'],
            ['page' => HilosPageConstants::HILOS_DAEMON_NODE, 'label' => 'Node'],
        ], $response->payload->data['pageBreadcrumb']);
        self::assertSame([], $response->payload->data['pageChildren']);
        self::assertSame(['data'], array_keys($response->payload->toArray()));
        self::assertEquals($response, PageResponseSignalData::fromArray($response->toArray()));
        self::assertSame(['reader'], ClusterDaemonPictureMirror::viewerKeys());
        self::assertSame([], Hilos::$sr->getAcceptKeysForPage(HilosPageConstants::HILOS_DAEMON));
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosDaemonWebsocketsPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, AbstractHilosDaemonWebsocketsPage::REACH);
        self::assertSame([HilosClusterNode::RT_COLLECTION], AbstractHilosDaemonWebsocketsPage::READS_RT);
        self::assertSame([], AbstractHilosDaemonWebsocketsPage::SUBSCRIPTION_AGENT_INDEX);
    }

    /** Only the shared node line may change before a complete mirror arrives. */
    public function testPartialFullEmptyAndMissingPicturesKeepUnknownDistinctFromZero(): void
    {
        $this->subscribe('reader', 'a');
        self::assertNull($this->onlyResponse()->payload->data['websockets']);
        $this->deliver([$this->node($this->report('a', 0))], false);
        $partial = $this->onlyResponse()->payload->data;
        self::assertSame(['node', 'websockets'], array_keys($partial));
        self::assertNull($partial['websockets']);
        $this->deliver([]);
        self::assertNull($this->onlyResponse()->payload->data['websockets']);
        $this->deliver([$this->node($this->report('b'))]);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($this->report('a', 0))]);
        self::assertSame(['connections' => 0, 'dataNode' => false], $this->onlyResponse()->payload->data['websockets']);
        $this->deliver([]);
        self::assertNull($this->onlyResponse()->payload->data['websockets']);
    }

    /** The publisher compares page data while leaving unrelated viewport state intact. */
    public function testLiveChangesAreAddressedDataOnlyAndUnchangedOtherNodeAndSampleTimePublishNothing(): void
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
        $changed = $b->withStanding(new DaemonNodeStanding(2, 9, false, null, null));
        $this->deliver([$this->node($changed)], false);
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame('bob', $signals[0]->data->targetAcceptKey);
        $payload = $signals[0]->data->data->payload;
        self::assertSame(['data'], array_keys($payload->toArray()));
        self::assertSame(['node', 'websockets'], array_keys($payload->data));
        self::assertSame(['connections' => 9, 'dataNode' => false], $payload->data['websockets']);
        self::assertSame($viewport, Hilos::$sr->getTableViewport('alice', 'unrelated'));
        $this->deliver([$this->node($changed)], false);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($changed->sampledAt(400))], false);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($changed->withStanding(new DaemonNodeStanding(3, 9, false, null, 9013)))], false);
        self::assertSame([], $this->drain());
        $this->deliver([$this->node($this->report('unwatched', 50))], false);
        self::assertSame([], $this->drain());
        self::assertSame(['a', 'b'], array_keys($this->fingerprints()));
    }

    /** A state-only change still owes a data-only page update. */
    public function testSilentNodeRetainsReportAndLiveRoleChangeSuppressesTheDataNodeCount(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->deliver([$this->node($this->report('a', 13))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        $this->deliver([$this->node($this->report('a', 13), false, 777)]);
        $silent = $this->onlyResponse()->payload->data;
        self::assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => 777000, 'role' => 'master'], $silent['node']);
        self::assertSame(['connections' => 13, 'dataNode' => false], $silent['websockets']);
        $this->deliver([$this->node($this->report('a', 13, NodeRole::Slave), false, 777)]);
        $data = $this->onlyResponse()->payload->data;
        self::assertSame('silent', $data['node']['state']);
        self::assertSame(['connections' => null, 'dataNode' => true], $data['websockets']);
        $this->deliver([$this->node($this->report('a', 13, NodeRole::Slave))]);
        self::assertSame('data', $this->onlyResponse()->payload->data['node']['state']);
    }

    /** Both delivery paths use the same non-personal field declarations. */
    public function testAdminAndViewModeViewerReceiveInitialAndLiveFieldsUnhidden(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
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
            'pageLabel', 'pageLead', 'pageBreadcrumb', 'pageChildren', 'pageHeadOwner', 'pageTabs', 'node', 'websockets',
        ], array_keys($viewer));
        foreach ($admin->payload->data as $key => $value) {
            self::assertSame($value, $viewer[$key], $key);
        }
        $this->deliver([$this->node($this->report('a', 0), false, 777)]);
        $signals = $this->drain();
        self::assertCount(2, $signals);
        foreach ($signals as $signal) {
            self::assertSame(['connections' => 0, 'dataNode' => false], $signal->data->data->payload->data['websockets']);
            self::assertSame(['clustered' => false, 'state' => 'silent', 'silentSince' => 777000, 'role' => 'master'],
                $signal->data->data->payload->data['node']);
            self::assertStringNotContainsString('_hidden', json_encode($signal->data->data->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    /** The inherited gates run before data assembly and viewer registration. */
    public function testUnknownNodeGuestAndNonAdminAreRefusedWithoutDataOrLease(): void
    {
        $this->subscribe('unknown', 'missing', known: false);
        $this->assertError(404);
        $this->browser->admin = false;
        $this->subscribe('non-admin', 'a');
        $this->assertError(403);
        $this->browser->userId = null;
        $this->subscribe('guest', 'a');
        $this->assertError(401);
        self::assertSame([], $this->fingerprints());
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
    }

    /** Each lifecycle boundary releases only its abandoned address comparison. */
    public function testAddressChangeUnsubscribeAndStopReleaseComparisonState(): void
    {
        $this->deliver([$this->node($this->report('a')), $this->node($this->report('b', 22))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        self::assertSame(['a'], array_keys($this->fingerprints()));
        $this->known('b');
        $update = new WebSocketPageUpdateSubscriptionSignalDTO('reader', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, ['nodeId' => 'b']);
        Hilos::$sr->updatePageSubscription(HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, $update);
        $this->pageRouter->dispatchPageUpdateSubscription($update, 'websocket', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS);
        self::assertSame(['connections' => 22, 'dataNode' => false], $this->onlyResponse()->payload->data['websockets']);
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
        self::assertSame(['connections' => 7, 'dataNode' => false], $this->onlyResponse()->payload->data['websockets']);
        $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('reader'), 'websocket', 'connection_close');
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->leave('reader');
        self::assertSame([], $this->fingerprints());
    }

    /** An address shared by two viewers remains retained until its last viewer leaves. */
    public function testResubscribeToAnotherAddressAndLeavingOneViewerPreserveOnlyActiveComparisons(): void
    {
        $this->deliver([$this->node($this->report('a')), $this->node($this->report('b', 22))]);
        foreach (['one', 'two'] as $key) {
            $this->subscribe($key, 'a');
            $this->onlyResponse();
        }
        $this->subscribe('one', 'b');
        self::assertSame(['connections' => 22, 'dataNode' => false], $this->onlyResponse()->payload->data['websockets']);
        self::assertSame(['a', 'b'], array_keys($this->fingerprints()));
        $this->leave('two');
        self::assertSame(['b'], array_keys($this->fingerprints()));
        $this->deliver([$this->node($this->report('b', 22))], false);
        self::assertSame([], $this->drain());
        $this->leave('one');
        self::assertSame([], $this->fingerprints());
    }

    /** A refused live subscription remains registered without retaining page-owned state. */
    public function testRevocationUsesTheRealGuardAndReleasesFingerprintsAndLease(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        $this->browser->admin = false;
        $this->pageRouter->dispatchPageAccessReassess(new WebSocketPageSubscribeSignalDTO(
            'reader', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, ['nodeId' => 'a'],
        ), 'worker', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS);
        $this->assertError(403);
        self::assertSame([], $this->fingerprints());
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->deliver([$this->node($this->report('a', 10))]);
        self::assertSame([], $this->drain());
        self::assertSame([], $this->fingerprints());
    }

    /** Successful assembly alone does not pay a failed response delivery. */
    public function testFailedResponseQueueRecoversWholeAnswerOnUnchangedPictureFact(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        Hilos::$sr->failResponse = true;
        $this->subscribe('reader', 'a');
        $this->assertError(500);
        self::assertSame([], Hilos::$sr->viewersAtResponse);
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('reader'));
        Hilos::$sr->failResponse = false;
        $this->deliver([$this->node($this->report('a'))]);
        $this->assertRecovered('reader', ['connections' => 7, 'dataNode' => false]);
        $this->deliver([$this->node($this->report('a'))]);
        self::assertSame([], $this->drain());
    }

    /** Delivery debt is found through subscription records rather than the picture roster. */
    public function testFailedFirstAnswerForKnownRtNodeWithoutAnyPictureSlotRecoversOnAnotherNodesFact(): void
    {
        WebsocketsTestPage::$fails = true;
        $this->subscribe('reader', 'no-slot');
        $this->assertError(500);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('reader'));
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        $this->deliver([$this->node($this->report('other'))]);
        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('reader'));
        WebsocketsTestPage::$fails = false;
        $this->deliver([$this->node($this->report('other'))]);
        $this->assertRecovered('reader', null);
        self::assertSame(['no-slot'], array_keys($this->fingerprints()));
        $this->deliver([$this->node($this->report('other'))]);
        self::assertSame([], $this->drain());
    }

    /** A silent failed probe keeps its debt and can reacquire the viewer lease later. */
    public function testWholeAnswerDebtRecoversAfterFailedProbeReleasedTheLease(): void
    {
        $this->deliver([$this->node($this->report('a'))]);
        $this->subscribe('reader', 'a');
        $this->onlyResponse();
        Hilos::$sr->markPageDeliveryFailure('reader');
        WebsocketsTestPage::$fails = true;
        $this->deliver([$this->node($this->report('a', 8))]);
        self::assertSame([], $this->drain());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('reader'));
        self::assertSame([], ClusterDaemonPictureMirror::viewerKeys());
        self::assertSame([], $this->fingerprints());
        WebsocketsTestPage::$fails = false;
        $this->deliver([$this->node($this->report('a', 8))]);
        $this->assertRecovered('reader', ['connections' => 8, 'dataNode' => false]);
    }

    /** Framework metadata alone cannot supply a missing project binding. */
    public function testKnownFactoryIdDoesNotActivateThePageInAnUnboundProject(): void
    {
        $factory = new HilosPageFactory($this->agent, WebsocketsUnboundTestHilos::class);
        self::assertTrue($factory->hasPage('hilos_daemon_websockets'));
        self::assertSame('subscription_page_hilos_daemon_websockets', HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_WEBSOCKETS);
        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('requires implementation in project');
        $factory->getPage('hilos_daemon_websockets');
    }

    /** @param int $code Expected subscription refusal or delivery error */
    private function assertError(int $code): void
    {
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertSame($code, $signals[0]->data->data->httpCode);
    }

    /**
     * @param string $key Recovered connection
     * @param ?array{connections: ?int, dataNode: bool} $websockets Expected own projection
     */
    private function assertRecovered(string $key, ?array $websockets): void
    {
        $response = $this->onlyResponse();
        self::assertSame([
            'node', 'websockets', 'pageLabel', 'pageLead', 'pageBreadcrumb', 'pageChildren', 'pageHeadOwner', 'pageTabs',
        ], array_keys($response->payload->data));
        self::assertSame(['data'], array_keys($response->payload->toArray()));
        self::assertSame($websockets, $response->payload->data['websockets']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure($key));
        self::assertSame([$key], ClusterDaemonPictureMirror::viewerKeys());
    }

    /**
     * @param ClusterDaemonPicture $picture Source picture
     * @param string $nodeId Addressed node
     * @param bool $clustered Installation mode
     * @return array<string, mixed> Own projection of one reported node
     */
    private function projection(ClusterDaemonPicture $picture, string $nodeId, bool $clustered = true): array
    {
        $websockets = HilosDaemonWebsocketsData::fromPicture($picture, $nodeId, $clustered, true);
        self::assertNotNull($websockets);
        return $websockets->toArray();
    }

    /**
     * @param string $nodeId Addressed node
     * @param int $connections Reported sockets, distinct from the session count
     * @param NodeRole $role Reported role
     * @return NodeDaemonPicture Report with unrelated private sections to exclude
     */
    private function report(string $nodeId, int $connections = 7, NodeRole $role = NodeRole::Master): NodeDaemonPicture
    {
        return new NodeDaemonPicture($nodeId, $role, 279, new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 1048576, [
                new DaemonAgentPicture('hidden:1', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY),
            ]),
        ], [], 0), standing: new DaemonNodeStanding(0, $connections, false, null, null));
    }

    /**
     * @param NodeDaemonPicture $report Retained source report
     * @param bool $online Membership verdict
     * @param int $receivedAt Receipt time in seconds
     * @return ClusterDaemonNodeView Report in its membership slot
     */
    private function node(NodeDaemonPicture $report, bool $online = true, int $receivedAt = 300): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($report->nodeId, $online, new ClusterDaemonNodeSlot($report->nodeId, $report, $receivedAt));
    }

    /**
     * @param list<ClusterDaemonNodeView> $nodes Whole slots crossing the real IPC roundtrip
     * @param bool $snapshot Whether this portion replaces the mirror
     */
    private function deliver(array $nodes, bool $snapshot = true): void
    {
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $this->agent->onSignalAgent(new AgentSignalData(DaemonClusterPicturePortionSignalData::fromArray($portion->toArray())),
            'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([], $this->browser->flushToSignalRouter());
    }

    /** @param string $nodeId Node to admit through the real runtime roster guard */
    private function known(string $nodeId): void
    {
        Hilos::$rt->hilosClusterNodes->actions->publish($nodeId, NodeRole::Master->value, [], null, true, 100.0);
    }

    /**
     * @param string $key Subscribing connection
     * @param string $nodeId Addressed node
     * @param bool $known Whether to publish the runtime roster prerequisite
     */
    private function subscribe(string $key, string $nodeId, bool $known = true): void
    {
        if ($known) {
            $this->known($nodeId);
        }
        Hilos::$rt->connectionsSource()->add(WebsocketsTestConnection::create($key, null));
        $request = new WebSocketPageSubscribeSignalDTO($key, HilosPageConstants::HILOS_DAEMON_WEBSOCKETS,
            [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]);
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, $request);
        $this->pageRouter->dispatchPageSubscribe($request, 'websocket', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS);
    }

    /** @param string $key Connection leaving through the actual subscription router */
    private function leave(string $key): void
    {
        $request = new WebSocketPageUnsubscribeSignalDTO($key, HilosPageConstants::HILOS_DAEMON_WEBSOCKETS);
        Hilos::$sr->unsubscribeFromPage(HilosPageConstants::HILOS_DAEMON_WEBSOCKETS, $request);
        $this->pageRouter->dispatchPageUnsubscribe($request, 'websocket', HilosPageConstants::HILOS_DAEMON_WEBSOCKETS);
    }

    /** @return array<string, string> Addressed fingerprints of only the new page */
    private function fingerprints(): array
    {
        return (new ReflectionProperty(AbstractHilosDaemonNodePage::class, 'nodeDataFingerprints'))->getValue()
            [HilosPageConstants::HILOS_DAEMON_WEBSOCKETS] ?? [];
    }

    /** @return PageResponseSignalData The only frame, carrying the node line and WebSocket count */
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

final class WebsocketsTestAgent extends AbstractHilosDaemonAgent
{
}

final class WebsocketsTestPage extends AbstractHilosDaemonWebsocketsPage
{
    public static bool $fails = false;

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Addressed node params
     * @return PagePayload Real WebSocket data with an injectable assembly failure
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        if (self::$fails) {
            throw new HilosException('WebSockets assembly failed');
        }
        return parent::buildPagePayload($acceptKey, $params);
    }
}

abstract class WebsocketsTestHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_DAEMON_WEBSOCKETS => WebsocketsTestPage::class];
}

abstract class WebsocketsUnboundTestHilos extends Hilos
{
}

final class WebsocketsTestBrowser extends BrowserContext
{
    public ?int $userId = 1;
    public bool $admin = true;

    /**
     * @param string $acceptKey Fixture connection
     * @return ConnectionIdentity Resolved fixture identity for the real access gate
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * @param int $userId Acting fixture user
     * @return bool Current fixture administrator verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

final class WebsocketsTestResender implements PageResender
{
    /** @param PageSignalRouter $router Real page dispatcher used for whole-answer recovery */
    public function __construct(private readonly PageSignalRouter $router)
    {
    }

    /**
     * @param string $page Page owed whole
     * @param string $acceptKey Subscribed connection
     * @param array<string, mixed> $params Retained subscription address
     * @return PageResendOutcome Real subscribe verdict and delivery outcome
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        return $this->router->resendPage($page, $acceptKey, $params);
    }

    /**
     * @param string $page Unused page key
     * @param string $acceptKey Unused connection key
     * @return PageResendOutcome No database resource is served by this fixture
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return PageResendOutcome::Unserved;
    }
}

final class WebsocketsTestConnection extends HilosConnection
{
    /** The fixture adds no project connection fields. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Base connection row with no project fields */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields on this connection */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Base connection patch with no project fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<WebsocketsTestConnection> */
final class WebsocketsTestConnections extends HilosConnections
{
    public const string STATE_CLASS = WebsocketsTestConnection::class;
}

final class WebsocketsTestRtContext extends RtContext
{
    /** Mounts only the fixture connection collection beside the framework runtime. */
    public function configure(): void
    {
        $this->_stateCollections['websocketsTestConnections'] = WebsocketsTestConnections::init();
    }
}

final class WebsocketsTestSignalRouter extends SignalRouter
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
        return WebsocketsTestHilos::class;
    }
}
