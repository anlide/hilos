<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\TableConstants;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonPage;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** Picture portions resend only changed placement windows to viewers who opened them. */
final class HilosDaemonPlacementPageWindowTest extends TestCase
{
    private const string VIEWER = 'placement-viewer';
    private const string OTHER_VIEWER = 'placement-other';

    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?ClusterContext $previousCluster;
    private string|false $previousClusterEnabled;
    private TableViewportSubscription $viewport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousCluster = Hilos::$cluster;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new PlacementWindowTestBrowser();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        $this->viewport = new TableViewportSubscription(HilosDaemonPlacementTable::TABLE,
            filter: [TableConstants::FILTER_KEY_SEARCH => 'x:1']);
        Hilos::$sr->setTableViewport(self::VIEWER, $this->viewport);
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$cluster = $this->previousCluster;
        $this->previousClusterEnabled === false
            ? putenv('CLUSTER_ENABLED')
            : putenv('CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        parent::tearDown();
    }

    public function testChangedInstancesMovementAndSilenceResendOnlyOpenWindow(): void
    {
        $agent = new PlacementWindowTestAgent();
        $this->deliver($agent, [$this->node('n1', true, $this->roster(['x:1'])),
            $this->node('n2', true, $this->roster([]))]);
        $this->assertOneWindow();

        $this->browser()->windows = [];
        $this->deliver($agent, [$this->node('n1', true, $this->roster(['x:1', 'x:2'])),
            $this->node('n2', true, $this->roster([]))]);
        $this->assertOneWindow();

        $this->browser()->windows = [];
        $this->deliver($agent, [$this->node('n1', true, $this->roster([])),
            $this->node('n2', true, $this->roster(['x:1', 'x:2']))]);
        $this->assertOneWindow();

        $this->browser()->windows = [];
        $this->deliver($agent, [$this->node('n1', true, $this->roster([])),
            $this->node('n2', false, $this->roster(['x:1', 'x:2']))]);
        $this->assertOneWindow();

        $this->browser()->windows = [];
        $this->deliver($agent, [$this->node('n1', true, $this->roster([])),
            $this->node('n2', false, $this->roster(['x:1', 'x:2']))]);
        self::assertSame([], $this->browser()->windows);

        $agent->onStop();
        $this->deliver($agent, [$this->node('n1', true, $this->roster([])),
            $this->node('n2', false, $this->roster(['x:1', 'x:2']))]);
        $this->assertOneWindow();
    }

    public function testUnrelatedMetricsAndCronDoNotResendButLeaderVerdictDoes(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $agent = new PlacementWindowTestAgent();
        $roster = $this->roster(['x:1'], ['x:lost']);
        $this->deliver($agent, [$this->node('n1', true, $roster, true)]);
        $this->assertOneWindow();

        $this->browser()->windows = [];
        $changedMetrics = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 456, 16384,
                $roster->workers[0]->agents),
        ], $roster->unplacedAgentIds, 7);
        $this->deliver($agent, [$this->node('n1', true, $changedMetrics, true, new DaemonCronPicture(null, []), 2)]);
        self::assertSame([], $this->browser()->windows);

        $this->deliver($agent, [$this->node('n1', true, $this->roster(['x:1'], ['x:other']), true)]);
        $this->assertOneWindow();
    }

    /** @param list<ClusterDaemonNodeView> $nodes Picture portion */
    private function deliver(PlacementWindowTestAgent $agent, array $nodes): void
    {
        $agent->onSignalAgent(new AgentSignalData(new DaemonClusterPicturePortionSignalData(true, $nodes)),
            'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
    }

    /** @param list<string> $ids Started ids; @param list<string> $unplaced Unplaced ids */
    private function roster(array $ids, array $unplaced = []): DaemonProcessRoster
    {
        sort($ids, SORT_STRING);
        $agents = array_map(static fn (string $id): DaemonAgentPicture => new DaemonAgentPicture(
            $id, DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY,
        ), $ids);
        sort($unplaced, SORT_STRING);
        return new DaemonProcessRoster([new DaemonWorkerPicture(1, WorkerConstants::TYPE_REGULAR, 123, 8192, $agents)], $unplaced, 0);
    }

    /** @return ClusterDaemonNodeView Node with process and optional cron sections */
    private function node(string $id, bool $online, DaemonProcessRoster $roster, bool $leader = false,
        ?DaemonCronPicture $cron = null, int $sessions = 0): ClusterDaemonNodeView
    {
        $standing = new DaemonNodeStanding($sessions, $sessions, true, new DaemonConsensusPicture(
            $leader ? ConsensusRole::Leader : ConsensusRole::Follower, 1, $leader ? $id : null, 1, 1, 1,
        ));
        $picture = new NodeDaemonPicture($id, NodeRole::Master, 10, $roster, $cron, standing: $standing);
        return new ClusterDaemonNodeView($id, $online, new ClusterDaemonNodeSlot($id, $picture, 10));
    }

    private function assertOneWindow(): void
    {
        self::assertSame([[HilosPageConstants::HILOS_DAEMON, self::VIEWER]], $this->browser()->windows);
        self::assertSame($this->viewport, $this->browser()->viewports[array_key_last($this->browser()->viewports)]);
    }

    private function browser(): PlacementWindowTestBrowser
    {
        self::assertInstanceOf(PlacementWindowTestBrowser::class, Hilos::$browser);
        return Hilos::$browser;
    }
}

final class PlacementWindowTestAgent extends AbstractHilosDaemonAgent
{
}

final class PlacementWindowTestBrowser extends IdentityTestBrowser
{
    /** @var list<array{string, string}> Delivered page and connection pairs */
    public array $windows = [];

    /** @var list<TableViewportSubscription> Original viewer descriptors */
    public array $viewports = [];

    public function __construct()
    {
        parent::__construct(userId: 1, admin: true);
    }

    /** @return bool True after recording the requested window */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = [$page, $acceptKey];
        $this->viewports[] = $viewport;
        return true;
    }
}
