<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
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
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A changed cron portion resends only open cron windows, once per distinct picture. */
final class HilosDaemonCronPageWindowTest extends TestCase
{
    private const string VIEWER = 'cron-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonCronPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonCronWindowTestBrowser();
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonCronTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonCronPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = null;
        Hilos::$browser = null;
        parent::tearDown();
    }

    public function testOnlyCronChangesResendOpenWindowsAndForgetAllowsTheSamePictureAgain(): void
    {
        $agent = new DaemonCronWindowTestAgent();
        $cron = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120)]);
        $agent->onSignalAgent(new AgentSignalData($this->portion($cron)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onSignalAgent(new AgentSignalData($this->portion($cron)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        $agent->onSignalAgent(
            new AgentSignalData($this->portion($cron, processes: true)),
            'collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );
        self::assertSame([], $this->browser()->windows);

        $changed = new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', 120, 180)]);
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->windows);

        $this->browser()->windows = [];
        $agent->onStop();
        $agent->onSignalAgent(new AgentSignalData($this->portion($changed)), 'collector', HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION);
        self::assertSame([[HilosPageConstants::HILOS_DAEMON_CRON, self::VIEWER]], $this->browser()->windows);
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
