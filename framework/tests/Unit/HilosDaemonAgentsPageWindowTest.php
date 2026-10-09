<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
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
use Hilos\Pages\Daemon\AbstractHilosDaemonAgentsPage;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A changed agent placement resends only open agent windows, once per distinct roster. */
final class HilosDaemonAgentsPageWindowTest extends TestCase
{
    private const string VIEWER = 'agents-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonAgentsPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonAgentsWindowTestBrowser();
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonAgentsTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonAgentsPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = null;
        Hilos::$browser = null;
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
        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, $roster, $cronPicture);
        $portion = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 10)),
        ]);
        $agent->onSignalAgent(new AgentSignalData($portion), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
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
}
