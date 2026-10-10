<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use PHPUnit\Framework\TestCase;

/** The workers page answers the node line and roster status in its own page data. */
final class HilosDaemonWorkersPageAnswerTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        parent::tearDown();
    }

    public function testEmptyMirrorHasNoNodeWordOrWorkerReport(): void
    {
        $page = new DaemonWorkersAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame([
            AbstractHilosDaemonWorkersPage::NODE => ['clustered' => false, 'state' => null, 'silentSince' => null],
            AbstractHilosDaemonWorkersPage::PROCESSES_REPORTED => false,
        ], $page->payload($this->address())->data);
        self::assertEquals([
            AbstractHilosDaemonWorkersPage::NODE => WireField::notPersonal(),
            AbstractHilosDaemonWorkersPage::PROCESSES_REPORTED => WireField::notPersonal(),
        ], $page->fields());
    }

    public function testAReceivedSlotDistinguishesMissingFromReportedRoster(): void
    {
        $page = new DaemonWorkersAnswerTestPage(new DaemonCollectorAgent());
        $this->publish(null);
        self::assertFalse($page->payload($this->address())->data[AbstractHilosDaemonWorkersPage::PROCESSES_REPORTED]);

        $this->publish(new DaemonProcessRoster([], null, 0));
        self::assertTrue($page->payload($this->address())->data[AbstractHilosDaemonWorkersPage::PROCESSES_REPORTED]);
    }

    public function testClusteredAnswerUsesThePicturesStateWord(): void
    {
        putenv('CLUSTER_ENABLED=true');
        Hilos::$cluster = new ClusterContext();
        $this->publish(new DaemonProcessRoster([], null, 0), NodeRole::Slave);
        $page = new DaemonWorkersAnswerTestPage(new DaemonCollectorAgent());

        self::assertSame(['clustered' => true, 'state' => 'data', 'silentSince' => null],
            $page->payload($this->address())->data[AbstractHilosDaemonWorkersPage::NODE]);
    }

    /** @return PageRouteParams Address of the test node */
    private function address(): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'n1']);
    }

    /**
     * @param ?DaemonProcessRoster $processes Last worker roster, or not yet reported
     * @param NodeRole $role Node role
     */
    private function publish(?DaemonProcessRoster $processes, NodeRole $role = NodeRole::Master): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
                'n1', new NodeDaemonPicture('n1', $role, 10, $processes), 10,
            )),
        ]));
    }
}

final class DaemonWorkersAnswerTestPage extends AbstractHilosDaemonWorkersPage
{
    /**
     * @param PageRouteParams $params Node route parameters
     * @return PagePayload Own page data
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
