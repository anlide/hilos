<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Table\DTO\TableQueryDTO;

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
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Node data changes resend whole pages; changed rules resend only open cron windows. */
final class HilosDaemonCronPageWindowTest extends TestCase
{
    private const string VIEWER = 'cron-viewer';
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
        AbstractHilosDaemonCronPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonCronWindowTestBrowser();
        Hilos::$rt = new DaemonCronWindowRtContext();
        Hilos::$rt->configure();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_DAEMON_CRON,
            new WebSocketPageSubscribeSignalDTO(self::VIEWER, HilosPageConstants::HILOS_DAEMON_CRON,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'n1']));
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonCronTable::TABLE, filter: ['node' => 'n1']));
    }

    /** Clears page comparisons and restores the previous facade sources. */
    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonCronPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testOnlyCronChangesPublishMutationsAndForgetAllowsTheSamePictureAgain(): void
    {
        $agent = new DaemonCronWindowTestAgent();
        $cron = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120)]);
        $agent->onSignalAgent(new AgentSignalData($this->portion($cron)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);

        $this->browser()->mutations = [];
        $agent->onSignalAgent(new AgentSignalData($this->portion($cron)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        $agent->onSignalAgent(
            new AgentSignalData($this->portion($cron, processes: true)),
            'collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );
        self::assertSame([], $this->browser()->mutations);

        $changed = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', 120, 180)]);
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);

        $this->browser()->mutations = [];
        $agent->onStop();
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);
    }

    /**
     * A node becoming silent refreshes its page even when its rules have not changed.
     *
     * @throws HilosException When the picture handler or schedule fixture fails
     */
    public function testSilentNodePublishesItsDataWithoutAnotherWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonCronWindowTestAgent();
        $cron = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120)]);
        $this->deliverNodes($agent, [$this->node('n1', true, $cron)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->pageData);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);
        $this->browser()->mutations = [];

        $this->browser()->pageData = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $cron)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);

        $this->browser()->pageData = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $cron)]);
        self::assertSame([], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);

        $agent->onStop();
        $this->deliverNodes($agent, [$this->node('n1', false, $cron)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->pageData);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);
    }

    /**
     * A portion changing another node refreshes only that node's subscribers.
     *
     * @throws HilosException When the picture handler or schedule fixture fails
     */
    public function testOtherNodeStateChangeDoesNotResendThisNodesPage(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $this->subscribe(self::OTHER_VIEWER, 'n2');
        $agent = new DaemonCronWindowTestAgent();
        $cron = new DaemonCronPicture(null, []);
        $this->deliverNodes($agent, [$this->node('n1', true, $cron), $this->node('n2', true, $cron)]);
        $this->browser()->pageData = [];
        $this->browser()->mutations = [];

        $this->deliverNodes($agent, [$this->node('n2', false, $cron)], false);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::OTHER_VIEWER]], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);
    }

    /**
     * The first empty schedule report refreshes the page; subsequent rules need only a window.
     *
     * @throws HilosException When the picture handler or schedule fixture fails
     */
    public function testFirstCronReportPublishesDataButLaterRuleChangesPublishOnlyMutations(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonCronWindowTestAgent();
        $this->deliverNodes($agent, [$this->node('n1', true, null)]);
        $this->browser()->pageData = [];
        $this->browser()->mutations = [];

        $this->deliverNodes($agent, [$this->node('n1', true, new DaemonCronPicture(null, []))]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);

        $this->browser()->pageData = [];
        $cron = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120)]);
        $this->deliverNodes($agent, [$this->node('n1', true, $cron)]);
        self::assertSame([], $this->browser()->pageData);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->mutations);
    }

    /**
     * A subscription with no live connection receives no whole-page resend.
     *
     * @throws HilosException When the picture handler fails
     */
    public function testClosedConnectionIsSkippedForWholePageResend(): void
    {
        $this->subscribe(self::VIEWER, 'n1', false);
        $agent = new DaemonCronWindowTestAgent();

        $this->deliverNodes($agent, [$this->node('n1', false, null)]);

        self::assertSame([], $this->browser()->pageData);
    }

    /**
     * @param DaemonCronWindowTestAgent $agent Page agent receiving the portion
     * @param list<ClusterDaemonNodeView> $nodes Node views in the portion
     * @param bool $snapshot Whether the portion replaces the mirror
     * @throws HilosException When the picture handler fails
     */
    private function deliverNodes(DaemonCronWindowTestAgent $agent, array $nodes, bool $snapshot = true): void
    {
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $agent->onSignalAgent(new AgentSignalData($portion), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
    }

    /**
     * @param string $nodeId Node id
     * @param bool $online Whether the node is online
     * @param ?DaemonCronPicture $cron Last schedule, or not yet reported
     * @return ClusterDaemonNodeView Reported node
     */
    private function node(string $nodeId, bool $online, ?DaemonCronPicture $cron): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($nodeId, $online, new ClusterDaemonNodeSlot(
            $nodeId, new NodeDaemonPicture($nodeId, NodeRole::Master, 10, cron: $cron), 10,
        ));
    }

    /**
     * @param string $acceptKey Viewer connection
     * @param string $nodeId Node named by the page
     * @param bool $connected Whether its connection row is still live
     */
    private function subscribe(string $acceptKey, string $nodeId, bool $connected = true): void
    {
        Hilos::$sr?->subscribeToPage(HilosPageConstants::HILOS_DAEMON_CRON,
            new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_DAEMON_CRON,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]));
        if ($connected) {
            Hilos::$rt?->connectionsSource()?->add(DaemonCronWindowConnection::create($acceptKey, null));
        }
    }

    /**
     * @param DaemonCronPicture $cron Cron section in the node frame
     * @param bool $processes Whether an unrelated process section has arrived
     * @return DaemonClusterPicturePortionSignalData Whole node portion
     */
    private function portion(DaemonCronPicture $cron, bool $processes = false): DaemonClusterPicturePortionSignalData
    {
        $picture = new NodeDaemonPicture(
            'n1',
            NodeRole::Master,
            10,
            $processes ? new DaemonProcessRoster([], [], 0) : null,
            $cron,
        );

        return new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 10)),
        ]);
    }

    /** @return DaemonCronWindowTestBrowser Mounted browser spy */
    private function browser(): DaemonCronWindowTestBrowser
    {
        self::assertInstanceOf(DaemonCronWindowTestBrowser::class, Hilos::$browser);
        return Hilos::$browser;
    }
}

final class DaemonCronWindowTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonCronWindowTestBrowser extends IdentityTestBrowser
{
    /** @var list<array{string, string}> Delivered page and connection pairs */
    public array $mutations = [];

    /** @var list<array{string, string}> Re-answered page and connection pairs */
    public array $pageData = [];

    public function __construct()
    {
        parent::__construct(userId: 1, admin: true);
    }

    /** Records data-only publications and asserts the page never re-sends a window in them. */
    public function publishPageData(string $page, string $acceptKey, Closure $build, array $fields): void
    {
        $payload = $build();
        if ($payload->isEmpty()) {
            return;
        }
        TestCase::assertSame(['data'], array_keys($payload->toArray()));
        $this->pageData[] = [$page, $acceptKey];
    }

    /** Records only nonempty typed diffs for the actual held subject and query. */
    public function publishTableMutations(string $page, string $acceptKey, string $tableKey, Closure $build): void
    {
        $viewport = Hilos::$sr?->getTableViewport($acceptKey, $tableKey);
        if ($viewport === null) {
            return;
        }
        $table = new HilosDaemonCronTable();
        $mutations = $build($table, new TableQueryDTO(filter: $viewport->filter));
        if ($mutations !== []) {
            $this->mutations[] = [$page, $acceptKey];
        }
    }
}

final class DaemonCronWindowConnection extends HilosConnection
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

/** @extends HilosConnections<DaemonCronWindowConnection> */
final class DaemonCronWindowConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonCronWindowConnection::class;
}

final class DaemonCronWindowRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonCronWindowConnections';

    /** Mounts the test's connection roster for live-viewer checks. */
    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonCronWindowConnections::init();
    }
}
