<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Table\DTO\TableQueryDTO;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
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
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A picture portion makes the page agent publish changed page data and typed comparison mutations. */
final class HilosDaemonEnvMismatchPageWindowTest extends TestCase
{
    private const string DIVERGED_VIEWER = 'diverged-viewer';
    private const string SOURCES_VIEWER = 'sources-viewer';
    private const string NO_WINDOW_VIEWER = 'no-window-viewer';

    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private ?RtContext $previousRt = null;
    private ?ClusterContext $previousCluster = null;
    private ?EnvAccessor $previousEnv = null;
    private ?string $previousClusterEnabled = null;
    private ?string $previousClusterNodeId = null;
    private ?string $previousClusterNodeRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED') !== false ? (string)getenv('CLUSTER_ENABLED') : null;
        $this->previousClusterNodeId = getenv('CLUSTER_NODE_ID') !== false ? (string)getenv('CLUSTER_NODE_ID') : null;
        $this->previousClusterNodeRole = getenv('CLUSTER_NODE_ROLE') !== false ? (string)getenv('CLUSTER_NODE_ROLE') : null;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonEnvMismatchWindowTestBrowser();
        Hilos::$rt = new DaemonEnvMismatchWindowRtContext();
        Hilos::$rt->configure();
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=a');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        foreach ([self::DIVERGED_VIEWER, self::SOURCES_VIEWER, self::NO_WINDOW_VIEWER] as $viewer) {
            ClusterDaemonPictureMirror::addViewer($viewer);
        }
        foreach ([self::DIVERGED_VIEWER, self::SOURCES_VIEWER] as $acceptKey) {
            Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH,
                new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH));
        }
        Hilos::$sr->setTableViewport(self::DIVERGED_VIEWER, new TableViewportSubscription(HilosDaemonEnvDivergedTable::TABLE));
        Hilos::$sr->setTableViewport(self::SOURCES_VIEWER, new TableViewportSubscription(HilosDaemonEnvSourcesDifferTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $viewer) {
            ClusterDaemonPictureMirror::removeViewer($viewer);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        if ($this->previousClusterEnabled !== null) {
            putenv("CLUSTER_ENABLED={$this->previousClusterEnabled}");
        } else {
            putenv('CLUSTER_ENABLED');
        }
        if ($this->previousClusterNodeId !== null) {
            putenv("CLUSTER_NODE_ID={$this->previousClusterNodeId}");
        } else {
            putenv('CLUSTER_NODE_ID');
        }
        if ($this->previousClusterNodeRole !== null) {
            putenv("CLUSTER_NODE_ROLE={$this->previousClusterNodeRole}");
        } else {
            putenv('CLUSTER_NODE_ROLE');
        }
        parent::tearDown();
    }

    public function testComparisonChangesResendEachOpenWindowAndStopForgetsTheFingerprint(): void
    {
        $agent = new DaemonEnvMismatchWindowTestAgent();
        $original = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $original);
        $this->assertDivergedMutations();

        $this->clearWindows();
        $this->deliver($agent, $original);
        self::assertSame([], $this->browser()->mutations);

        $changedLabel = $this->nodes('aaaa', 'cccc', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $changedLabel);
        $this->assertDivergedMutations();

        $this->clearWindows();
        $this->deliver($agent, [$changedLabel[0], $this->node('b', false, null)]);
        $this->assertDivergedMutations();

        $this->clearWindows();
        $movedBucket = $this->nodes('aaaa', 'aaaa', EnvSource::PROCESS, EnvSource::ENV_FILE);
        $this->deliver($agent, $movedBucket);
        $this->assertSourcesMutations();

        $this->clearWindows();
        $newCollectorLife = $this->nodes('dddd', 'dddd', EnvSource::PROCESS, EnvSource::ENV_FILE);
        $this->deliver($agent, $newCollectorLife);
        $this->assertSourcesMutations();

        $this->clearWindows();
        $agent->onStop();
        $this->deliver($agent, $newCollectorLife);
        $this->assertSourcesMutations();
    }

    public function testProcessCronAndSessionChangesDoNotResendComparisonWindows(): void
    {
        $agent = new DaemonEnvMismatchWindowTestAgent();
        $nodes = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $nodes);
        $this->clearWindows();

        $picture = $nodes[0]->slot?->picture;
        self::assertInstanceOf(NodeDaemonPicture::class, $picture);
        $updated = new NodeDaemonPicture(
            'a',
            NodeRole::Master,
            2,
            processes: new DaemonProcessRoster([], [], 0),
            cron: new DaemonCronPicture(null, []),
            environment: $picture->environment,
            standing: new DaemonNodeStanding(1, 1, false, null),
        );
        $this->deliver($agent, [new ClusterDaemonNodeView('a', true, new ClusterDaemonNodeSlot('a', $updated, 2))], false);
        self::assertSame([], $this->browser()->mutations);
    }

    public function testSilentNodeWithEmptyBucketsPublishesPageDataWithoutWindows(): void
    {
        $subscriber = 'page-subscriber';
        $this->subscribe($subscriber);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $twoOnline = [
            $this->node('a', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, false, 'same')]),
            $this->node('b', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, false, 'same')]),
        ];
        $this->deliver($agent, $twoOnline);
        $this->clearWindows();
        $this->browser()->pageData = [];

        $oneSilent = [
            $twoOnline[0],
            $this->node('b', false, null),
        ];
        $this->deliver($agent, $oneSilent);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, $subscriber]], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);
    }

    public function testKeyMovedToPerNodePublishesPageData(): void
    {
        $subscriber = 'page-subscriber';
        $this->subscribe($subscriber);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $initial = [
            $this->node('a', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, false, 'same')]),
            $this->node('b', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, false, 'same')]),
        ];
        $this->deliver($agent, $initial);
        $this->clearWindows();
        $this->browser()->pageData = [];

        $perNodeNodes = [
            $this->node('a', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, true, 'same')]),
            $this->node('b', true, [new NodeEnvironmentFingerprint('KEY', 'string', EnvSource::PROCESS, true, 'same')]),
        ];
        $this->deliver($agent, $perNodeNodes);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, $subscriber]], $this->browser()->pageData);
    }

    public function testCellDigestChangeResendsOnlyOpenWindows(): void
    {
        $subscriber = 'page-subscriber';
        $this->subscribe($subscriber);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $original = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $original);
        $this->assertDivergedMutations();
        $this->clearWindows();
        $this->browser()->pageData = [];

        $changedLabel = $this->nodes('aaaa', 'cccc', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $changedLabel);

        self::assertSame([], $this->browser()->pageData);
        $this->assertDivergedMutations();
    }

    public function testDataPublicationDoesNotSuppressItsOwnTableMutations(): void
    {
        $this->subscribe(self::DIVERGED_VIEWER);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $nodes = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $nodes);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::DIVERGED_VIEWER]], $this->browser()->pageData);
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::DIVERGED_VIEWER, HilosDaemonEnvDivergedTable::TABLE],
        ], $this->browser()->mutations);
    }

    public function testClosedConnectionIsSkipped(): void
    {
        $closedSubscriber = 'closed-subscriber';
        $this->subscribe($closedSubscriber, connected: false);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $nodes = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $nodes);

        self::assertSame([], $this->browser()->pageData);
    }

    public function testRepeatedPictureSendsNothing(): void
    {
        $subscriber = 'page-subscriber';
        $this->subscribe($subscriber);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $nodes = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $nodes);
        $this->clearWindows();
        $this->browser()->pageData = [];

        $this->deliver($agent, $nodes);

        self::assertSame([], $this->browser()->pageData);
        self::assertSame([], $this->browser()->mutations);
    }

    public function testAfterPictureForgottenPublishesPageDataAgain(): void
    {
        $subscriber = 'page-subscriber';
        $this->subscribe($subscriber);
        $agent = new DaemonEnvMismatchWindowTestAgent();

        $nodes = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $nodes);
        $this->clearWindows();
        $this->browser()->pageData = [];

        $agent->onStop();
        $this->deliver($agent, $nodes);

        self::assertSame([[HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, $subscriber]], $this->browser()->pageData);
    }

    /**
     * @param string $acceptKey Viewer connection
     * @param bool $connected Whether its connection row is still live
     */
    private function subscribe(string $acceptKey, bool $connected = true): void
    {
        Hilos::$sr?->subscribeToPage(
            HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH,
            new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, []),
        );
        if ($connected) {
            Hilos::$rt?->connectionsSource()?->add(DaemonEnvMismatchWindowConnection::create($acceptKey, null));
        }
    }

    /** @return list<ClusterDaemonNodeView> Two answering nodes with one compared key */
    private function nodes(string $first, string $second, EnvSource $firstSource, EnvSource $secondSource): array
    {
        return [
            $this->node('a', true, [new NodeEnvironmentFingerprint('KEY', 'string', $firstSource, false, $first)]),
            $this->node('b', true, [new NodeEnvironmentFingerprint('KEY', 'string', $secondSource, false, $second)]),
        ];
    }

    /**
     * @param string $id Node id
     * @param bool $online Whether the node answers
     * @param ?list<NodeEnvironmentFingerprint> $fingerprints Null before an environment report
     * @return ClusterDaemonNodeView Node view
     */
    private function node(string $id, bool $online, ?array $fingerprints): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($id, $online, new ClusterDaemonNodeSlot($id, new NodeDaemonPicture(
            $id,
            NodeRole::Master,
            1,
            environment: $fingerprints === null ? null : new NodeEnvironmentSummary(count($fingerprints), 0, 0, 0, 0, $fingerprints),
        ), 1));
    }

    /**
     * @param DaemonEnvMismatchWindowTestAgent $agent Page agent
     * @param list<ClusterDaemonNodeView> $nodes Whole snapshot or changed node views
     * @param bool $snapshot Whether this replaces the mirror
     */
    private function deliver(DaemonEnvMismatchWindowTestAgent $agent, array $nodes, bool $snapshot = true): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(new DaemonClusterPicturePortionSignalData($snapshot, $nodes)),
            'collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );
    }

    /** Asserts one window for each interested viewer, and none for the third. */
    private function assertDivergedMutations(): void
    {
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::DIVERGED_VIEWER, HilosDaemonEnvDivergedTable::TABLE],
        ], $this->browser()->mutations);
    }

    private function assertSourcesMutations(): void
    {
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::SOURCES_VIEWER, HilosDaemonEnvSourcesDifferTable::TABLE],
        ], $this->browser()->mutations);
    }

    private function clearWindows(): void
    {
        $this->browser()->mutations = [];
    }

    /** @return DaemonEnvMismatchWindowTestBrowser Mounted browser spy */
    private function browser(): DaemonEnvMismatchWindowTestBrowser
    {
        self::assertInstanceOf(DaemonEnvMismatchWindowTestBrowser::class, Hilos::$browser);

        return Hilos::$browser;
    }
}

final class DaemonEnvMismatchWindowTestAgent extends AbstractHilosDaemonAgent
{
}

/** Browser spy recording the table descriptor and whole page answers that the page agent resends. */
final class DaemonEnvMismatchWindowTestBrowser extends IdentityTestBrowser
{
    /** @var list<array{string, string, string}> Page, viewer and table key */
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
        $table = ( $tableKey === HilosDaemonEnvDivergedTable::TABLE ? new HilosDaemonEnvDivergedTable() : new HilosDaemonEnvSourcesDifferTable());
        $mutations = $build($table, new TableQueryDTO(filter: $viewport->filter));
        if ($mutations !== []) {
            $this->mutations[] = [$page, $acceptKey, $tableKey];
        }
    }
}

final class DaemonEnvMismatchWindowConnection extends HilosConnection
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

/** @extends HilosConnections<DaemonEnvMismatchWindowConnection> */
final class DaemonEnvMismatchWindowConnections extends HilosConnections
{
    public const string STATE_CLASS = DaemonEnvMismatchWindowConnection::class;
}

final class DaemonEnvMismatchWindowRtContext extends RtContext
{
    public const string CONNECTIONS = 'daemonEnvMismatchWindowConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::CONNECTIONS] = DaemonEnvMismatchWindowConnections::init();
    }
}
