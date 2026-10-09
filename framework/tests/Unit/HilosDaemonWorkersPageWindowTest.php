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
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A changed worker roster resends only open worker windows, once per distinct roster. */
final class HilosDaemonWorkersPageWindowTest extends TestCase
{
    private const string VIEWER = 'workers-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonWorkersWindowTestBrowser();
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
        Hilos::$sr = null;
        Hilos::$browser = null;
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
        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, $roster, $cron);
        $portion = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 10)),
        ]);
        $agent->onSignalAgent(new AgentSignalData($portion), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
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
