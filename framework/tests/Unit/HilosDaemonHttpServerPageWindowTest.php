<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
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
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\TestCase;

/** A changed HTTP portion resends only open HTTP windows, once per distinct picture. */
final class HilosDaemonHttpServerPageWindowTest extends TestCase
{
    private const string VIEWER = 'http-viewer';
    private const string OTHER_VIEWER = 'other-viewer';

    protected function setUp(): void
    {
        parent::setUp();
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonHttpServerPage::onPictureForgotten();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new DaemonHttpServerWindowTestBrowser();
        ClusterDaemonPictureMirror::addViewer(self::VIEWER);
        ClusterDaemonPictureMirror::addViewer(self::OTHER_VIEWER);
        Hilos::$sr->setTableViewport(self::VIEWER, new TableViewportSubscription(HilosDaemonHttpRoutesTable::TABLE));
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        AbstractHilosDaemonHttpServerPage::onPictureForgotten();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = null;
        Hilos::$browser = null;
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
