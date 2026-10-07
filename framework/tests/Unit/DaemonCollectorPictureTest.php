<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Constants\HilosSignalConstants;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/** A node's report replaces its slot whole and no frame leaves without a watcher. */
final class DaemonCollectorPictureTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        parent::tearDown();
    }

    public function testWholeReplacementAndNoUnwatchedPortions(): void
    {
        $agent = new DaemonCollectorAgent();
        $agent->onStart();
        $agent->applyNodePicture(new NodeDaemonPicture('n2', NodeRole::Master, 200));
        $agent->applyNodePicture(new NodeDaemonPicture('n1', NodeRole::Master, 300));
        $agent->applyNodePicture(new NodeDaemonPicture('n2', NodeRole::Slave, 1));
        $agent->fanOutIfDue(microtime(true) + 1.0);

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertSame(['n1', 'n2'], array_map(static fn ($view): string => $view->nodeId, $agent->nodeViews()));
        self::assertSame(NodeRole::Slave, $agent->clusterPicture()->node('n2')?->slot?->picture->role);
        self::assertSame(1, $agent->clusterPicture()->node('n2')?->slot?->picture->sampledAt);
        self::assertFalse($agent->nodeViews()[1]->online, 'A saved reporter absent from the roster is offline');
    }

    public function testRosterMemberWithoutAFrameAndOfflineReporterWithLastFrame(): void
    {
        Hilos::$rt = new DaemonCollectorPictureRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$rt->hilosClusterNodes->actions->publish('n1', 'master', [], null, true, microtime(true));
        Hilos::$rt->hilosClusterNodes->actions->publish('n2', 'slave', [], null, false, microtime(true));
        $this->drainRuntimeFrames();

        $agent = new DaemonCollectorAgent();
        $agent->onStart();
        $agent->applyNodePicture(new NodeDaemonPicture('n2', NodeRole::Slave, 10));
        $views = $agent->nodeViews();
        self::assertSame(['n1', 'n2'], array_map(static fn ($view): string => $view->nodeId, $views));
        self::assertTrue($views[0]->online);
        self::assertNull($views[0]->slot);
        self::assertFalse($views[1]->online);
        self::assertNotNull($views[1]->slot);

        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonPictureWatchSignalData(1)),
            'agent/hilos_daemon',
            HilosSignalConstants::DAEMON_PICTURE_WATCH,
        );
        Hilos::$sr?->getNextQueuedSignal();
        Hilos::$rt->hilosClusterNodes->actions->publish('n2', 'slave', [], null, true, microtime(true));
        $this->drainRuntimeFrames();
        $agent->fanOutIfDue(microtime(true) + 1.0);
        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION, $signal?->signalName->getName());
        self::assertTrue($signal?->data?->data->nodes[0]->online);
        self::assertSame('n2', $signal?->data?->data->nodes[0]->nodeId);
    }

    public function testAnUnrenewedWatchExpiresBeforeAChangedNodeCanBeSent(): void
    {
        $agent = new DaemonCollectorAgent();
        $agent->onStart();
        $agent->onSignalAgent(
            new AgentSignalData(data: new DaemonPictureWatchSignalData(1)),
            'agent/hilos_daemon',
            HilosSignalConstants::DAEMON_PICTURE_WATCH,
        );
        Hilos::$sr?->getNextQueuedSignal();
        $agent->applyNodePicture(new NodeDaemonPicture('n1', NodeRole::Master, 10));
        $agent->fanOutIfDue(microtime(true) + 91.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    private function drainRuntimeFrames(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
        }
    }
}

/** Runtime context carrying the framework cluster roster. */
final class DaemonCollectorPictureRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
