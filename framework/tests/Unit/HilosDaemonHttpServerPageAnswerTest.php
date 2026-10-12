<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonHttpServerPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The HTTP page answers its node line and complete HTTP summary in one own payload. */
final class HilosDaemonHttpServerPageAnswerTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?ClusterContext $previousCluster = null;
    private string|false $previousClusterEnabled = false;

    /** Installs isolated environment and picture sources. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
    }

    /** Restores the facade sources and the cluster switch. */
    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv($this->previousClusterEnabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        parent::tearDown();
    }

    /**
     * Unknown HTTP remains null, and both own fields are visible in view mode.
     *
     * @throws HilosException When the page answer cannot be built
     */
    public function testMissingMirrorNodeSlotAndHttpAreNotKnownZeroCounts(): void
    {
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());
        $missing = [
            AbstractHilosDaemonHttpServerPage::NODE => ['clustered' => false, 'state' => null, 'silentSince' => null],
            AbstractHilosDaemonHttpServerPage::HTTP => null,
        ];
        self::assertSame($missing, $page->payload($this->address())->data);
        self::assertEquals([
            AbstractHilosDaemonHttpServerPage::NODE => WireField::notPersonal(),
            AbstractHilosDaemonHttpServerPage::HTTP => WireField::notPersonal(),
        ], $page->fields());

        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
        self::assertSame($missing, $page->payload($this->address())->data);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, null),
        ]));
        self::assertSame($missing, $page->payload($this->address())->data);
        $this->publish(null);
        self::assertSame($missing, $page->payload($this->address())->data);
    }

    /**
     * A received listener with no requests carries all six summary fields.
     *
     * @throws HilosException When the HTTP fixture or page answer is invalid
     */
    public function testReportedZerosKeepTheListenerAddress(): void
    {
        $this->publish(new DaemonHttpPicture('::', 8080, 10, [
            new DaemonHttpRoutePicture('GET', '/status', null, new HttpRouteTally(0, 0, 0, null)),
        ], new HttpRouteTally(0, 0, 0, null)));
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame([
            AbstractHilosDaemonHttpServerPage::NODE => ['clustered' => false, 'state' => null, 'silentSince' => null],
            AbstractHilosDaemonHttpServerPage::HTTP => [
                'host' => '::', 'port' => 8080, 'countingHours' => 0, 'requests' => 0, 'serverErrors' => 0, 'slow' => 0,
            ],
        ], $page->payload($this->address())->data);
    }

    /**
     * The summary is independent of the table's 25-row window.
     *
     * @throws HilosException When the HTTP fixture or page answer is invalid
     */
    public function testTotalsIncludeEveryRouteAndUnroutedRequests(): void
    {
        $routes = [];
        for ($index = 0; $index < 30; $index++) {
            $routes[] = new DaemonHttpRoutePicture('GET', sprintf('/route-%02d', $index),
                null, new HttpRouteTally(2, 1, 1, 1200));
        }
        $this->publish(new DaemonHttpPicture('127.0.0.1', 8080, 10, $routes, new HttpRouteTally(7, 2, 3, 1500)));
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame([
            'host' => '127.0.0.1', 'port' => 8080, 'countingHours' => 0,
            'requests' => 67, 'serverErrors' => 32, 'slow' => 33,
        ], $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::HTTP]);
    }

    /**
     * Only full hours from the node's measurement count, bounded to one day.
     *
     * @param int $elapsed Seconds between counting start and measurement
     * @param int $expected Expected complete hours
     * @throws HilosException When the HTTP fixture or page answer is invalid
     */
    #[DataProvider('countingHours')]
    public function testCountingHoursUseThePictureTime(int $elapsed, int $expected): void
    {
        $this->publish(new DaemonHttpPicture('127.0.0.1', 8080, 100, [], new HttpRouteTally(0, 0, 0, null)),
            sampledAt: 100 + $elapsed);
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame($expected, $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::HTTP]['countingHours']);
    }

    /** @return iterable<string, array{int, int}> Measurement differences and bounded hours */
    public static function countingHours(): iterable
    {
        yield 'clock went backwards' => [-1, 0];
        yield 'counting just started' => [0, 0];
        yield 'less than one full hour' => [3599, 0];
        yield 'one full hour' => [3600, 1];
        yield 'twenty-three full hours' => [23 * 3600, 23];
        yield 'not yet twenty-four hours' => [24 * 3600 - 1, 23];
        yield 'twenty-four full hours' => [24 * 3600, 24];
        yield 'older than one day' => [48 * 3600, 24];
    }

    /**
     * Live role words come from the picture, and a standalone master has no word.
     *
     * @throws HilosException When the node fixture or page answer is invalid
     */
    public function testHeadingUsesLiveClusterStatesAndStandaloneState(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());
        $this->publish(null, role: NodeRole::Slave);
        self::assertSame(['clustered' => true, 'state' => 'data', 'silentSince' => null],
            $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::NODE]);

        foreach ([[ConsensusRole::Leader, 'leader'], [ConsensusRole::Follower, 'standby']] as [$role, $state]) {
            $this->publish(null, standing: new DaemonNodeStanding(0, 0, true,
                new DaemonConsensusPicture($role, 1, 'n1', 1, 1, 1)));
            self::assertSame(['clustered' => true, 'state' => $state, 'silentSince' => null],
                $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::NODE]);
        }

        Hilos::$cluster = null;
        $this->publish(null, standing: new DaemonNodeStanding(0, 0, false, null));
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null],
            $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::NODE]);
    }

    /**
     * A stale silent slot keeps its measured hour rather than aging at read time.
     *
     * @throws HilosException When the HTTP fixture or page answer is invalid
     */
    public function testSilentHeadingKeepsItsLastMeasuredCountingHours(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->publish(new DaemonHttpPicture('127.0.0.1', 8080, 10, [], new HttpRouteTally(0, 0, 0, null)),
            sampledAt: 3610, online: false, receivedAt: 200000);
        $page = new DaemonHttpServerAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => 200000],
            $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::NODE]);
        self::assertSame(1, $page->payload($this->address())->data[AbstractHilosDaemonHttpServerPage::HTTP]['countingHours']);
    }

    /** @return PageRouteParams Address of the test node */
    private function address(): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'n1']);
    }

    /**
     * @param ?DaemonHttpPicture $http Last HTTP report, or not yet reported
     * @param int $sampledAt Node measurement time
     * @param bool $online Whether the node is online
     * @param int $receivedAt Last slot arrival time
     * @param NodeRole $role Node role
     * @param ?DaemonNodeStanding $standing Master's last standing
     */
    private function publish(
        ?DaemonHttpPicture $http,
        int $sampledAt = 10,
        bool $online = true,
        int $receivedAt = 10,
        NodeRole $role = NodeRole::Master,
        ?DaemonNodeStanding $standing = null,
    ): void {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', $online, new ClusterDaemonNodeSlot(
                'n1', new NodeDaemonPicture('n1', $role, $sampledAt, standing: $standing, http: $http), $receivedAt,
            )),
        ]));
    }
}

final class DaemonHttpServerAnswerTestPage extends AbstractHilosDaemonHttpServerPage
{
    /**
     * @param PageRouteParams $params Node route parameters
     * @return PagePayload Own page data
     * @throws MissingPageRouteParamException When the node id is absent
     * @throws EnvException When cluster mode cannot be read for a node line
     */
    public function payload(PageRouteParams $params): PagePayload
    {
        return $this->buildPagePayload('ak', $params);
    }

    /** @return array<string, WireField> Own field verdicts */
    public function fields(): array
    {
        return $this->dataFields();
    }
}
