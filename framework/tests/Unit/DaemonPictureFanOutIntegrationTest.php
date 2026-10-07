<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/** All three Daemon frames travel through their real array serialization. */
final class DaemonPictureFanOutIntegrationTest extends TestCase
{
    private float $startedAt;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        ClusterDaemonPictureMirror::forgetPicture();
        $this->startedAt = microtime(true);
    }

    protected function tearDown(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        parent::tearDown();
    }

    public function testTwoNodesReachTheMirrorAndOneChangedNodeTravelsAsAPortion(): void
    {
        $collector = $this->collector();
        $this->report($collector, 'n2', NodeRole::Slave);
        $this->report($collector, 'n1', NodeRole::Master);
        $pages = new DaemonPictureFanOutProbeAgent();
        ClusterDaemonPictureMirror::addViewer('ak');
        $pages->tickAt($this->at(0.0));
        self::assertSame(1, $this->carryClaim($collector));
        $snapshot = $this->carryPortion($pages);
        self::assertTrue($snapshot->snapshot);
        self::assertSame(['n1', 'n2'], array_map(static fn ($node): string => $node->nodeId, $snapshot->nodes));
        self::assertSame(NodeRole::Slave, ClusterDaemonPictureMirror::picture()?->node('n2')?->slot?->picture->role);

        $this->report($collector, 'n1', NodeRole::Slave);
        $collector->fanOutIfDue($this->at(0.6));
        $portion = $this->carryPortion($pages);
        self::assertFalse($portion->snapshot);
        self::assertSame(['n1'], array_map(static fn ($node): string => $node->nodeId, $portion->nodes));
        self::assertSame(NodeRole::Slave, ClusterDaemonPictureMirror::picture()?->node('n1')?->slot?->picture->role);
        self::assertCount(2, ClusterDaemonPictureMirror::picture()?->nodes());
    }

    public function testAFirstClaimOrSnapshotLossKeepsTheOneSecondRetry(): void
    {
        foreach ([false, true] as $deliverClaim) {
            $this->drain();
            ClusterDaemonPictureMirror::forgetPicture();
            $collector = $this->collector();
            $this->report($collector, 'n1', NodeRole::Master);
            $pages = new DaemonPictureFanOutProbeAgent();
            ClusterDaemonPictureMirror::addViewer('ak');
            $pages->tickAt($this->at(0.0));
            if ($deliverClaim) {
                $this->carryClaim($collector);
            }
            $this->drain(); // Lose the claim, or the snapshot it generated.
            $pages->tickAt($this->at(0.9));
            self::assertSame([], $this->drain());

            if ($deliverClaim) {
                $this->report($collector, 'n1', NodeRole::Slave);
                $collector->fanOutIfDue($this->at(0.6));
                $early = $this->carryPortion($pages);
                self::assertFalse($early->snapshot);
                self::assertTrue(ClusterDaemonPictureMirror::known());
                self::assertFalse(ClusterDaemonPictureMirror::hasFullSnapshot());
            }

            $pages->tickAt($this->at(1.1));
            self::assertSame(1, $this->carryClaim($collector));
            self::assertTrue($this->carryPortion($pages)->snapshot);
            self::assertTrue(ClusterDaemonPictureMirror::hasFullSnapshot());
            ClusterDaemonPictureMirror::removeViewer('ak');
        }
    }

    public function testZeroStopsPortionsAndRestartIsRecoveredByKeepalive(): void
    {
        $collector = $this->collector();
        $pages = new DaemonPictureFanOutProbeAgent();
        ClusterDaemonPictureMirror::addViewer('ak');
        $pages->tickAt($this->at(0.0));
        $this->carryClaim($collector);
        $this->carryPortion($pages);

        $restarted = $this->collector();
        $this->report($restarted, 'n1', NodeRole::Master);
        $restarted->fanOutIfDue($this->at(1.0));
        self::assertSame([], $this->drain());
        $pages->tickAt($this->at(30.0));
        $this->carryClaim($restarted);
        self::assertTrue($this->carryPortion($pages)->snapshot);

        ClusterDaemonPictureMirror::removeViewer('ak');
        $pages->tickAt($this->at(30.1));
        $this->carryClaim($restarted);
        self::assertSame([], $this->drain());
        $this->report($restarted, 'n2', NodeRole::Slave);
        $restarted->fanOutIfDue($this->at(31.0));
        self::assertSame([], $this->drain());
    }

    private function collector(): DaemonCollectorAgent
    {
        $agent = new DaemonCollectorAgent();
        $agent->onStart();

        return $agent;
    }

    private function report(DaemonCollectorAgent $collector, string $nodeId, NodeRole $role): void
    {
        $wire = new DaemonNodePictureSignalData(new NodeDaemonPicture($nodeId, $role, 10));
        $collector->onSignalAgent(
            new AgentSignalData(data: DaemonNodePictureSignalData::fromArray($wire->toArray())),
            'agent/hilos_daemon_node',
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
        );
    }

    private function carryClaim(DaemonCollectorAgent $collector): int
    {
        $signals = $this->drain();
        foreach ($signals as $signal) {
            self::assertSame(HilosSignalConstants::DAEMON_PICTURE_WATCH, $signal->signalName->getName());
            $wire = $signal->data?->data;
            self::assertInstanceOf(DaemonPictureWatchSignalData::class, $wire);
            $collector->onSignalAgent(
                new AgentSignalData(data: DaemonPictureWatchSignalData::fromArray($wire->toArray())),
                'agent/hilos_daemon',
                HilosSignalConstants::DAEMON_PICTURE_WATCH,
            );
        }

        return count($signals);
    }

    private function carryPortion(DaemonPictureFanOutProbeAgent $pages): DaemonClusterPicturePortionSignalData
    {
        $signals = $this->drain();
        self::assertCount(1, $signals);
        self::assertSame(HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION, $signals[0]->signalName->getName());
        $wire = $signals[0]->data?->data;
        self::assertInstanceOf(DaemonClusterPicturePortionSignalData::class, $wire);
        $frame = DaemonClusterPicturePortionSignalData::fromArray($wire->toArray());
        $pages->onSignalAgent(
            new AgentSignalData(data: $frame),
            'agent/hilos_daemon_collector',
            HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION,
        );

        return $frame;
    }

    /** @return list<SignalDTO> Queued frames */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    private function at(float $seconds): float
    {
        return $this->startedAt + $seconds;
    }
}

/** Public clock seam for the Daemon page agent's claim interval. */
final class DaemonPictureFanOutProbeAgent extends AbstractHilosDaemonAgent
{
    public function tickAt(float $now): void
    {
        $this->watchIfDue($now);
    }
}
