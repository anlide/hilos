<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonRestartingAgent;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Node lines resend whole agent pages; roster changes resend their open windows. */
final class HilosDaemonAgentsPageWindowTest extends TestCase
{
    private const string VIEWER = 'agents-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonAgentsPage::onPictureForgotten();
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonAgentsWindowTestBrowser();
        Hilos::$rt = new DaemonAgentsWindowRtContext();
        Hilos::$rt->configure();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonAgentsTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonAgentsPage::onPictureForgotten();
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testOnlyAgentChangesResendOpenWindowsAndForgetAllowsTheSamePictureAgain(): void
    {
        $agent = new DaemonAgentsWindowTestAgent();
        $this->deliver($agent, $this->roster(1));
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $this->deliver($agent, $this->roster(1));
        $this->deliver($agent, $this->roster(1, memory: 16384, pid: 999));
        $this->deliver($agent, $this->roster(1, unplaced: ['missing']), cron: true);
        self::assertSame([], $this->browser()->windows);

        $this->deliver($agent, $this->roster(1, secondAgent: true));
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $changed = $this->roster(3, secondAgent: true);
        $this->deliver($agent, $changed);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $changed = $this->roster(3, secondAgent: true, placement: DaemonAgentPicture::PLACEMENT_LEADER);
        $this->deliver($agent, $changed);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onStop();
        $this->deliver($agent, $changed);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);
    }

    public function testLeaderRestartMarkAloneResendsTheOriginalNodesWindowAndCompletionResendsAgain(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonAgentsWindowTestAgent();
        $this->deliverNodes($agent, [
            $this->node('n1', true, $this->roster(1)),
            $this->node('leader', true, new DaemonProcessRoster([], [], 0)),
        ]);
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        $marked = new DaemonProcessRoster([], [], 0, [new DaemonRestartingAgent('agent:a', 'n1')]);
        $this->deliverNodes($agent, [$this->node('leader', true, $marked)], false);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);
        $this->browser()->windows = [];
        $this->deliverNodes($agent, [$this->node('leader', true, $marked)], false);
        $this->deliverNodes($agent, [$this->node('n1', true, $this->roster(1, memory: 16384))], false);
        self::assertSame([], $this->browser()->windows);

        $this->deliverNodes($agent, [$this->node('leader', true, new DaemonProcessRoster([], [], 0))], false);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);
    }

    public function testSilentNodeResendsItsWholePageWithoutAnotherWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonAgentsWindowTestAgent();
        $roster = $this->roster(1);
        $this->deliver($agent, $roster);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $agent->onStop();
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    public function testOtherNodeStateChangeDoesNotResendThisNodesPage(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $this->subscribe(self::OTHER_VIEWER, 'n2');
        $agent = new DaemonAgentsWindowTestAgent();
        $roster = $this->roster(1);
        $this->deliverNodes($agent, [$this->node('n1', true, $roster), $this->node('n2', true, $roster)]);
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        $this->deliverNodes($agent, [$this->node('n2', false, $roster)], false);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::OTHER_VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    public function testFirstAgentReportResendsThePageButLaterRosterChangesSendOnlyTheWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonAgentsWindowTestAgent();
        $this->deliverNodes($agent, [$this->node('n1', true, null)]);
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        $this->deliver($agent, $this->roster(1));
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliver($agent, $this->roster(3));
        self::assertSame([], $this->browser()->pages);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER]], $this->browser()->windows);
    }

    public function testClosedConnectionIsSkippedForWholePageResend(): void
    {
        $this->subscribe(self::VIEWER, 'n1', false);
        $agent = new DaemonAgentsWindowTestAgent();

        $this->deliverNodes($agent, [$this->node('n1', false, null)]);

        self::assertSame([], $this->browser()->pages);
    }

    public function testWorkersAndAgentsPagesKeepTheirOwnNodeLineFingerprints(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $this->subscribe(self::OTHER_VIEWER, 'n1', page: HilosPageConstants::HILOS_DAEMON_WORKERS);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('n1', true, $this->roster(1)),
        ]));
        AbstractHilosDaemonWorkersPage::onPictureChanged();
        AbstractHilosDaemonAgentsPage::onPictureChanged();
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('n1', false, $this->roster(1)),
        ]));
        AbstractHilosDaemonWorkersPage::onPictureChanged();
        AbstractHilosDaemonAgentsPage::onPictureChanged();
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_WORKERS, self::OTHER_VIEWER],
            [HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER],
        ], $this->browser()->pages);

        $this->browser()->pages = [];
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        AbstractHilosDaemonAgentsPage::onPictureChanged();
        self::assertSame([], $this->browser()->pages);

        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            $this->node('n1', true, $this->roster(1)),
        ]));
        AbstractHilosDaemonAgentsPage::onPictureChanged();
        AbstractHilosDaemonWorkersPage::onPictureChanged();
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_AGENTS, self::VIEWER],
            [HilosPageConstants::HILOS_DAEMON_WORKERS, self::OTHER_VIEWER],
        ], $this->browser()->pages);

        $this->browser()->pages = [];
        AbstractHilosDaemonAgentsPage::onPictureForgotten();
        AbstractHilosDaemonWorkersPage::onPictureChanged();
        self::assertSame([], $this->browser()->pages);
    }

    /** @return DaemonProcessRoster Agent roster for one worker */
    private function roster(
        int $index,
        bool $secondAgent = false,
        int $memory = 8192,
        int $pid = 123,
        array $unplaced = [],
        string $placement = DaemonAgentPicture::PLACEMENT_POLICY,
    ): DaemonProcessRoster {
        $agents = [new DaemonAgentPicture('agent:a', DaemonAgentPicture::SCOPE_CLUSTER, $placement)];
        if ($secondAgent) {
            $agents[] = new DaemonAgentPicture('agent:b', DaemonAgentPicture::SCOPE_CLUSTER, $placement);
        }

        return new DaemonProcessRoster([
            new DaemonWorkerPicture($index, WorkerConstants::TYPE_REGULAR, $pid, $memory, $agents),
        ], $unplaced, 0);
    }

    /** Sends a whole node portion through the page agent's picture handler. */
    private function deliver(DaemonAgentsWindowTestAgent $agent, DaemonProcessRoster $roster, bool $cron = false): void
    {
        $cronPicture = $cron ? new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120),
        ]) : null;
        $this->deliverNodes($agent, [$this->node('n1', true, $roster, $cronPicture)]);
    }

    /**
     * @param DaemonAgentsWindowTestAgent $agent Page agent receiving the portion
     * @param list<ClusterDaemonNodeView> $nodes Node views in the portion
     * @param bool $snapshot Whether the portion replaces the mirror
     */
    private function deliverNodes(DaemonAgentsWindowTestAgent $agent, array $nodes, bool $snapshot = true): void
    {
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $agent->onSignalAgent(new AgentSignalData($portion), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
    }

    /**
     * @param string $nodeId Node id
     * @param bool $online Whether the node is online
     * @param ?DaemonProcessRoster $roster Last process roster
     * @param ?DaemonCronPicture $cron Optional cron section
     * @return ClusterDaemonNodeView Reported node
     */
    private function node(
        string $nodeId,
        bool $online,
        ?DaemonProcessRoster $roster,
        ?DaemonCronPicture $cron = null,
    ): ClusterDaemonNodeView {
        $picture = new NodeDaemonPicture($nodeId, NodeRole::Master, 10, $roster, $cron);

        return new ClusterDaemonNodeView($nodeId, $online, new ClusterDaemonNodeSlot($nodeId, $picture, 10));
    }

    /**
     * @param string $acceptKey Viewer connection
     * @param string $nodeId Node named by the page
     * @param bool $connected Whether its connection row is still live
     * @param string $page Page being watched
     */
    private function subscribe(
        string $acceptKey,
        string $nodeId,
        bool $connected = true,
        string $page = HilosPageConstants::HILOS_DAEMON_AGENTS,
    ): void {
        Hilos::$sr?->subscribeToPage($page,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $page,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]));
        if ($connected) {
            Hilos::$rt?->connectionsSource()?->add(DaemonAgentsWindowConnection::create($acceptKey, null));
        }
    }

    /** @return DaemonAgentsWindowTestBrowser Mounted browser spy */
    private function browser(): DaemonAgentsWindowTestBrowser
    {
        self::assertInstanceOf(DaemonAgentsWindowTestBrowser::class, Hilos::$browser);
        return Hilos::$browser;
    }
}

final class DaemonAgentsWindowTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonAgentsWindowTestBrowser extends IdentityTestBrowser
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

final class DaemonAgentsWindowConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized connection */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Project patch */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** @extends HilosConnections<DaemonAgentsWindowConnection> */
final class DaemonAgentsWindowConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonAgentsWindowConnection::class;
}

final class DaemonAgentsWindowRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonAgentsWindowConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonAgentsWindowConnections::init();
    }
}
