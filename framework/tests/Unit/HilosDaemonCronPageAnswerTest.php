<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use PHPUnit\Framework\TestCase;

/** The cron page answers the node line and schedule status in its own page data. */
final class HilosDaemonCronPageAnswerTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    /** Installs isolated environment and picture sources. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
    }

    /** Restores the facade sources and clears the cluster switch. */
    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        parent::tearDown();
    }

    /**
     * The node line and missing-report verdict are both visible in view mode.
     *
     * @throws HilosException When the page answer cannot be built
     */
    public function testEmptyMirrorHasNoNodeWordOrCronReport(): void
    {
        $page = new DaemonCronAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame([
            AbstractHilosDaemonCronPage::NODE => ['clustered' => false, 'state' => null, 'silentSince' => null, 'role' => null],
            AbstractHilosDaemonCronPage::CRON_REPORTED => false,
        ], $page->payload($this->address())->data);
        self::assertEquals([
            AbstractHilosDaemonCronPage::NODE => WireField::notPersonal(),
            AbstractHilosDaemonCronPage::CRON_REPORTED => WireField::notPersonal(),
        ], $page->fields());
    }

    /**
     * An empty schedule is a report, while a null section is not.
     *
     * @throws HilosException When the schedule fixture or page answer is invalid
     */
    public function testAReceivedSlotDistinguishesMissingFromReportedSchedule(): void
    {
        $page = new DaemonCronAnswerTestPage(new DaemonCollectorAgent());
        $this->publish(null);
        self::assertFalse($page->payload($this->address())->data[AbstractHilosDaemonCronPage::CRON_REPORTED]);

        $this->publish(new DaemonCronPicture(null, []));
        self::assertTrue($page->payload($this->address())->data[AbstractHilosDaemonCronPage::CRON_REPORTED]);
    }

    /**
     * A clustered slave is described by the picture's data state.
     *
     * @throws HilosException When the schedule fixture or page answer is invalid
     */
    public function testClusteredAnswerUsesThePicturesStateWord(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->publish(new DaemonCronPicture(null, []), NodeRole::Slave);
        $page = new DaemonCronAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame(['clustered' => true, 'state' => 'data', 'silentSince' => null, 'role' => 'slave'],
            $page->payload($this->address())->data[AbstractHilosDaemonCronPage::NODE]);
    }

    /** @return PageRouteParams Address of the test node */
    private function address(): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'n1']);
    }

    /**
     * @param ?DaemonCronPicture $cron Last schedule, or not yet reported
     * @param NodeRole $role Node role
     */
    private function publish(?DaemonCronPicture $cron, NodeRole $role = NodeRole::Master): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
                'n1', new NodeDaemonPicture('n1', $role, 10, cron: $cron), 10,
            )),
        ]));
    }
}

final class DaemonCronAnswerTestPage extends AbstractHilosDaemonCronPage
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
