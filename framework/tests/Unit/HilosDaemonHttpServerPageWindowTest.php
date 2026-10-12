<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Node data changes resend whole pages; route-only changes resend open HTTP windows. */
final class HilosDaemonHttpServerPageWindowTest extends TestCase
{
    private const string VIEWER = 'http-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?ClusterContext $previousCluster = null;

    /** Installs isolated delivery and connection sources. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonHttpServerPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonHttpServerWindowTestBrowser();
        Hilos::$rt = new DaemonHttpServerWindowRtContext();
        Hilos::$rt->configure();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonHttpRoutesTable::TABLE));
    }

    /** Clears both page comparisons and restores the previous facade sources. */
    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonHttpServerPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testOnlyHttpChangesResendOpenWindowsAndForgetAllowsTheSamePictureAgain(): void
    {
        $agent = new DaemonHttpServerWindowTestAgent();
        $http = $this->http(new HttpRouteTally(2, 0, 0, 12));
        $agent->onSignalAgent(new AgentSignalData($this->portion($http)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onSignalAgent(new AgentSignalData($this->portion($http)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        $agent->onSignalAgent(
            new AgentSignalData($this->portion($http, cron: true)),
            'collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );
        self::assertSame([], $this->browser()->windows);

        $changed = $this->http(new HttpRouteTally(3, 1, 0, 18));
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onStop();
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->windows);
    }

    /**
     * The first known listener refreshes the entire page, including its first route window.
     *
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    public function testFirstHttpReportResendsTheWholePageWithoutAnotherWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonHttpServerWindowTestAgent();
        $this->deliverNodes($agent, [$this->node('n1', true, null)]);
        $this->clearDeliveries();

        $this->deliverNodes($agent, [$this->node('n1', true, $this->http(new HttpRouteTally(0, 0, 0, null)))]);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    /**
     * Every own summary field refreshes the page rather than sending a second window.
     *
     * @param string $field Summary field changed by this case
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    #[DataProvider('summaryChanges')]
    public function testSummaryChangesResendTheWholePageWithoutAnotherWindow(string $field): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonHttpServerWindowTestAgent();
        $this->deliverNodes($agent, [$this->node('n1', true, $this->http(new HttpRouteTally(3, 0, 0, 1200)))]);
        $this->clearDeliveries();
        $changed = new DaemonHttpPicture(
            $field === 'host' ? '::' : '127.0.0.1',
            $field === 'port' ? 8081 : 8080,
            10,
            [new DaemonHttpRoutePicture('GET', '/status', null, new HttpRouteTally(
                $field === 'requests' ? 4 : 3,
                $field === 'serverErrors' ? 1 : 0,
                $field === 'slow' ? 1 : 0,
                1200,
            ))],
            new HttpRouteTally(0, 0, 0, null),
        );

        $this->deliverNodes($agent, [$this->node('n1', true, $changed, sampledAt: $field === 'countingHours' ? 3610 : 10)]);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    /** @return iterable<string, array{string}> Every own HTTP summary field */
    public static function summaryChanges(): iterable
    {
        foreach (['host', 'port', 'countingHours', 'requests', 'serverErrors', 'slow'] as $field) {
            yield $field => [$field];
        }
    }

    /**
     * Redistributing counts or changing a duration preserves the summary and refreshes only routes.
     *
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    public function testRouteChangesWithTheSameTotalsSendOnlyTheWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonHttpServerWindowTestAgent();
        $unrouted = new HttpRouteTally(1, 0, 0, 5);
        $http = new DaemonHttpPicture('127.0.0.1', 8080, 10, [
            new DaemonHttpRoutePicture('GET', '/a', null, new HttpRouteTally(2, 1, 1, 1200)),
            new DaemonHttpRoutePicture('GET', '/b', null, new HttpRouteTally(3, 0, 0, 10)),
        ], $unrouted);
        $this->deliverNodes($agent, [$this->node('n1', true, $http)]);
        $this->clearDeliveries();
        $redistributed = new DaemonHttpPicture('127.0.0.1', 8080, 10, [
            new DaemonHttpRoutePicture('GET', '/a', null, new HttpRouteTally(3, 0, 0, 10)),
            new DaemonHttpRoutePicture('GET', '/b', null, new HttpRouteTally(2, 1, 1, 1200)),
        ], $unrouted);

        $this->deliverNodes($agent, [$this->node('n1', true, $redistributed)]);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->windows);

        $this->clearDeliveries();
        $slower = new DaemonHttpPicture('127.0.0.1', 8080, 10, $redistributed->routes, new HttpRouteTally(1, 0, 0, 20));
        $this->deliverNodes($agent, [$this->node('n1', true, $slower)]);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->windows);
    }

    /**
     * A node becoming silent refreshes its page even when HTTP did not change.
     *
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    public function testHeadingChangesResendWholePagesAndForgetResetsBothComparisons(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonHttpServerWindowTestAgent();
        $http = $this->http(new HttpRouteTally(2, 0, 0, 12));
        $this->deliverNodes($agent, [$this->node('n1', true, $http)]);
        $this->clearDeliveries();

        $this->deliverNodes($agent, [$this->node('n1', false, $http)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->clearDeliveries();
        $this->deliverNodes($agent, [$this->node('n1', false, $http)]);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $agent->onStop();
        $this->deliverNodes($agent, [$this->node('n1', false, $http)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    /**
     * A heading change on another node reaches only that node's page subscribers.
     *
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    public function testOtherNodeHeadingChangeDoesNotResendThisNodesPageOrWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $this->subscribe(self::OTHER_VIEWER, 'n2');
        $agent = new DaemonHttpServerWindowTestAgent();
        $http = $this->http(new HttpRouteTally(2, 0, 0, 12));
        $this->deliverNodes($agent, [$this->node('n1', true, $http), $this->node('n2', true, $http)]);
        $this->clearDeliveries();

        $this->deliverNodes($agent, [$this->node('n2', false, $http)], false);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_HTTP_SERVER, self::OTHER_VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    /**
     * Other node sections and sample times within the same full hour carry no HTTP page change.
     *
     * @throws HilosException When the picture handler or HTTP fixture fails
     */
    public function testOtherSectionAndMeasurementTimeWithinTheHourSendNothing(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonHttpServerWindowTestAgent();
        $http = $this->http(new HttpRouteTally(2, 0, 0, 12));
        $this->deliverNodes($agent, [$this->node('n1', true, $http)]);
        $this->clearDeliveries();

        $this->deliverNodes($agent, [$this->node('n1', true, $http, cron: true)]);
        $this->deliverNodes($agent, [$this->node('n1', true, $http, sampledAt: 3609, cron: true)]);

        self::assertSame([], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    /**
     * A subscription without a live connection receives no whole-page resend.
     *
     * @throws HilosException When the picture handler fails
     */
    public function testDisconnectedViewerIsSkippedForWholePageResend(): void
    {
        $this->subscribe(self::VIEWER, 'n1', false);
        $agent = new DaemonHttpServerWindowTestAgent();

        $this->deliverNodes($agent, [$this->node('n1', false, null)]);

        self::assertSame([], $this->browser()->pages);
    }

    /** Clears the browser spy between independent portions. */
    private function clearDeliveries(): void
    {
        $this->browser()->pages = [];
        $this->browser()->windows = [];
    }

    /**
     * @param DaemonHttpServerWindowTestAgent $agent Page agent receiving the portion
     * @param list<ClusterDaemonNodeView> $nodes Node views in the portion
     * @param bool $snapshot Whether the portion replaces the mirror
     * @throws HilosException When the picture handler fails
     */
    private function deliverNodes(DaemonHttpServerWindowTestAgent $agent, array $nodes, bool $snapshot = true): void
    {
        $agent->onSignalAgent(new AgentSignalData(new DaemonClusterPicturePortionSignalData($snapshot, $nodes)),
            'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
    }

    /**
     * @param string $nodeId Node id
     * @param bool $online Whether the node is online
     * @param ?DaemonHttpPicture $http Last HTTP section, or not yet reported
     * @param int $sampledAt Node measurement and slot arrival time
     * @param bool $cron Whether an unrelated cron section has arrived
     * @return ClusterDaemonNodeView Reported node
     */
    private function node(
        string $nodeId,
        bool $online,
        ?DaemonHttpPicture $http,
        int $sampledAt = 10,
        bool $cron = false,
    ): ClusterDaemonNodeView {
        return new ClusterDaemonNodeView($nodeId, $online, new ClusterDaemonNodeSlot(
            $nodeId, new NodeDaemonPicture($nodeId, NodeRole::Master, $sampledAt,
                cron: $cron ? new DaemonCronPicture(null, []) : null, http: $http), $sampledAt,
        ));
    }

    /**
     * @param string $acceptKey Viewer connection
     * @param string $nodeId Node named by the page
     * @param bool $connected Whether its connection row is still live
     */
    private function subscribe(string $acceptKey, string $nodeId, bool $connected = true): void
    {
        Hilos::$sr?->subscribeToPage(HilosPageConstants::HILOS_DAEMON_HTTP_SERVER,
            new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_DAEMON_HTTP_SERVER,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]));
        if ($connected) {
            Hilos::$rt?->connectionsSource()?->add(DaemonHttpServerWindowConnection::create($acceptKey, null));
        }
    }

    /**
     * @param HttpRouteTally $tally Route counts in the current window
     * @return DaemonHttpPicture HTTP section of one node
     */
    private function http(HttpRouteTally $tally): DaemonHttpPicture
    {
        return new DaemonHttpPicture('127.0.0.1', 8080, 10, [
            new DaemonHttpRoutePicture('GET', '/status', null, $tally),
        ], new HttpRouteTally(0, 0, 0, null));
    }

    /**
     * @param DaemonHttpPicture $http HTTP section in the node frame
     * @param bool $cron Whether an unrelated cron section has arrived
     * @return DaemonClusterPicturePortionSignalData Whole node portion
     */
    private function portion(DaemonHttpPicture $http, bool $cron = false): DaemonClusterPicturePortionSignalData
    {
        $picture = new NodeDaemonPicture(
            'n1',
            NodeRole::Master,
            10,
            cron: $cron ? new DaemonCronPicture(null, [
                new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120),
            ]) : null,
            http: $http,
        );

        return new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 10)),
        ]);
    }

    /** @return DaemonHttpServerWindowTestBrowser Mounted browser spy */
    private function browser(): DaemonHttpServerWindowTestBrowser
    {
        self::assertInstanceOf(DaemonHttpServerWindowTestBrowser::class, Hilos::$browser);
        return Hilos::$browser;
    }
}

final class DaemonHttpServerWindowTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonHttpServerWindowTestBrowser extends IdentityTestBrowser
{
    /** @var list<array{string, string}> Delivered page and connection pairs */
    public array $windows = [];

    /** @var list<array{string, string}> Re-answered page and connection pairs */
    public array $pages = [];

    public function __construct()
    {
        parent::__construct(userId: 1, admin: true);
    }

    /**
     * @param string $page Page served
     * @param string $acceptKey Viewer receiving the window
     * @param TableViewportSubscription $viewport Requested viewport, unused here
     * @return bool True after recording the delivery
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = [$page, $acceptKey];
        return true;
    }

    /**
     * @param string $page Page served
     * @param string $acceptKey Viewer receiving the full answer
     */
    public function resendPageWhole(string $page, string $acceptKey): void
    {
        $this->pages[] = [$page, $acceptKey];
    }
}

final class DaemonHttpServerWindowConnection extends HilosConnection
{
    /** Initializes no project fields. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized connection, with no project fields */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Project patch, with no project fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonHttpServerWindowConnection> */
final class DaemonHttpServerWindowConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonHttpServerWindowConnection::class;
}

final class DaemonHttpServerWindowRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonHttpServerWindowConnections';

    /** Mounts the test's connection roster for live-viewer checks. */
    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonHttpServerWindowConnections::init();
    }
}
