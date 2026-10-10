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
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A changed worker roster resends only open worker windows, once per distinct roster. */
final class HilosDaemonWorkersPageWindowTest extends TestCase
{
    private const string VIEWER = 'workers-viewer';
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
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonWorkersWindowTestBrowser();
        Hilos::$rt = new DaemonWorkersWindowRtContext();
        Hilos::$rt->configure();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonWorkersTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
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

    public function testOnlyWorkersChangesResendOpenWindowsAndForgetAllowsTheSamePictureAgain(): void
    {
        $agent = new DaemonWorkersWindowTestAgent();
        $roster = $this->roster(8192);
        $this->deliver($agent, $roster);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $this->deliver($agent, $roster);
        $this->deliver($agent, new DaemonProcessRoster($roster->workers, ['unplaced'], 1));
        $this->deliver($agent, $roster, new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120),
        ]));
        self::assertSame([], $this->browser()->windows);

        $changed = $this->roster(16384);
        $this->deliver($agent, $changed);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $changedAgents = $this->roster(16384, secondAgent: true);
        $this->deliver($agent, $changedAgents);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onStop();
        $this->deliver($agent, $changedAgents);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->windows);
    }

    public function testSilentNodeResendsItsWholePageWithoutAnotherWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonWorkersWindowTestAgent();
        $roster = $this->roster(8192);
        $this->deliver($agent, $roster);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $agent->onStop();
        $this->deliverNodes($agent, [$this->node('n1', false, $roster)]);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    public function testOtherNodeStateChangeDoesNotResendThisNodesPage(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $this->subscribe(self::OTHER_VIEWER, 'n2');
        $agent = new DaemonWorkersWindowTestAgent();
        $roster = $this->roster(8192);
        $this->deliverNodes($agent, [$this->node('n1', true, $roster), $this->node('n2', true, $roster)]);
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        $this->deliverNodes($agent, [$this->node('n2', false, $roster)], false);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::OTHER_VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);
    }

    public function testFirstWorkerReportResendsThePageButLaterRosterChangesSendOnlyTheWindow(): void
    {
        $this->subscribe(self::VIEWER, 'n1');
        $agent = new DaemonWorkersWindowTestAgent();
        $this->deliverNodes($agent, [$this->node('n1', true, null)]);
        $this->browser()->pages = [];
        $this->browser()->windows = [];

        $this->deliver($agent, $this->roster(8192));
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->pages);
        self::assertSame([], $this->browser()->windows);

        $this->browser()->pages = [];
        $this->deliver($agent, $this->roster(16384));
        self::assertSame([], $this->browser()->pages);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_WORKERS, self::VIEWER]], $this->browser()->windows);
    }

    public function testClosedConnectionIsSkippedForWholePageResend(): void
    {
        $this->subscribe(self::VIEWER, 'n1', false);
        $agent = new DaemonWorkersWindowTestAgent();

        $this->deliverNodes($agent, [$this->node('n1', false, null)]);

        self::assertSame([], $this->browser()->pages);
    }

    /** @return DaemonProcessRoster One worker whose RSS can change */
    private function roster(int $memoryBytes, bool $secondAgent = false): DaemonProcessRoster
    {
        $agents = [new DaemonAgentPicture('agent:a', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY)];
        if ($secondAgent) {
            $agents[] = new DaemonAgentPicture('agent:b', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY);
        }

        return new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 1234, $memoryBytes, $agents),
        ], [], 0);
    }

    /** Sends a whole node portion through the page agent's picture handler. */
    private function deliver(
        DaemonWorkersWindowTestAgent $agent,
        DaemonProcessRoster $roster,
        ?DaemonCronPicture $cron = null,
    ): void {
        $this->deliverNodes($agent, [$this->node('n1', true, $roster, $cron)]);
    }

    /**
     * @param DaemonWorkersWindowTestAgent $agent Page agent receiving the portion
     * @param list<ClusterDaemonNodeView> $nodes Node views in the portion
     * @param bool $snapshot Whether the portion replaces the mirror
     */
    private function deliverNodes(DaemonWorkersWindowTestAgent $agent, array $nodes, bool $snapshot = true): void
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
     */
    private function subscribe(string $acceptKey, string $nodeId, bool $connected = true): void
    {
        Hilos::$sr?->subscribeToPage(HilosPageConstants::HILOS_DAEMON_WORKERS,
            new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_DAEMON_WORKERS,
                [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => $nodeId]));
        if ($connected) {
            Hilos::$rt?->connectionsSource()?->add(DaemonWorkersWindowConnection::create($acceptKey, null));
        }
    }

    /** @return DaemonWorkersWindowTestBrowser Mounted browser spy */
    private function browser(): DaemonWorkersWindowTestBrowser
    {
        self::assertInstanceOf(DaemonWorkersWindowTestBrowser::class, Hilos::$browser);
        return Hilos::$browser;
    }
}

final class DaemonWorkersWindowTestAgent extends AbstractHilosDaemonAgent
{
}

final class DaemonWorkersWindowTestBrowser extends IdentityTestBrowser
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

final class DaemonWorkersWindowConnection extends HilosConnection
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

/** @extends HilosConnections<DaemonWorkersWindowConnection> */
final class DaemonWorkersWindowConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonWorkersWindowConnection::class;
}

final class DaemonWorkersWindowRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonWorkersWindowConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonWorkersWindowConnections::init();
    }
}
