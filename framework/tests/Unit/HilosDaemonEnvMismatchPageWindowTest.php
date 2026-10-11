<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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
use Hilos\Environment\EnvSource;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A picture portion makes the page agent resend only changed comparison windows. */
final class HilosDaemonEnvMismatchPageWindowTest extends TestCase
{
    private const string DIVERGED_VIEWER = 'diverged-viewer';
    private const string SOURCES_VIEWER = 'sources-viewer';
    private const string NO_WINDOW_VIEWER = 'no-window-viewer';

    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonEnvMismatchWindowTestBrowser();
        foreach ([self::DIVERGED_VIEWER, self::SOURCES_VIEWER, self::NO_WINDOW_VIEWER] as $viewer) {
            ClusterDaemonPictureMirror::addViewer($viewer);
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
        parent::tearDown();
    }

    public function testComparisonChangesResendEachOpenWindowAndStopForgetsTheFingerprint(): void
    {
        $agent = new DaemonEnvMismatchWindowTestAgent();
        $original = $this->nodes('aaaa', 'bbbb', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $original);
        $this->assertBothWindows();

        $this->clearWindows();
        $this->deliver($agent, $original);
        self::assertSame([], $this->browser()->windows);

        $changedLabel = $this->nodes('aaaa', 'cccc', EnvSource::PROCESS, EnvSource::PROCESS);
        $this->deliver($agent, $changedLabel);
        $this->assertBothWindows();

        $this->clearWindows();
        $this->deliver($agent, [$changedLabel[0], $this->node('b', false, null)]);
        $this->assertBothWindows();

        $this->clearWindows();
        $movedBucket = $this->nodes('aaaa', 'aaaa', EnvSource::PROCESS, EnvSource::ENV_FILE);
        $this->deliver($agent, $movedBucket);
        $this->assertBothWindows();

        $this->clearWindows();
        $newCollectorLife = $this->nodes('dddd', 'dddd', EnvSource::PROCESS, EnvSource::ENV_FILE);
        $this->deliver($agent, $newCollectorLife);
        $this->assertBothWindows();

        $this->clearWindows();
        $agent->onStop();
        $this->deliver($agent, $newCollectorLife);
        $this->assertBothWindows();
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
        self::assertSame([], $this->browser()->windows);
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
    private function assertBothWindows(): void
    {
        self::assertSame([
            [HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::DIVERGED_VIEWER, HilosDaemonEnvDivergedTable::TABLE],
            [HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH, self::SOURCES_VIEWER, HilosDaemonEnvSourcesDifferTable::TABLE],
        ], $this->browser()->windows);
    }

    /** Clears the browser spy for the next portion. */
    private function clearWindows(): void
    {
        $this->browser()->windows = [];
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

/** Browser spy recording the table descriptor that the page agent resends. */
final class DaemonEnvMismatchWindowTestBrowser extends IdentityTestBrowser
{
    /** @var list<array{string, string, string}> Page, viewer and table key */
    public array $windows = [];

    public function __construct()
    {
        parent::__construct(userId: 1, admin: true);
    }

    /**
     * @param string $page Page served
     * @param string $acceptKey Viewer receiving the window
     * @param TableViewportSubscription $viewport Requested window descriptor
     * @return bool True after recording the delivery
     */
    public function sendTableWindow(string $page, string $acceptKey, TableViewportSubscription $viewport): bool
    {
        $this->windows[] = [$page, $acceptKey, $viewport->tableKey];

        return true;
    }
}
