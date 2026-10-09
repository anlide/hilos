<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosDaemonAgent;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonCronRuleReport;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterCronSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\Environment\EnvSource;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/** All three Daemon frames travel through their real array serialization. */
final class DaemonPictureFanOutIntegrationTest extends TestCase
{
    private float $startedAt;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = null;
        ClusterDaemonPictureMirror::forgetPicture();
        $this->startedAt = microtime(true);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        ClusterDaemonPictureMirror::forgetPicture();
        foreach (ClusterDaemonPictureMirror::viewerKeys() as $key) {
            ClusterDaemonPictureMirror::removeViewer($key);
        }
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
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

    public function testEnvironmentCountsCrossCollectorAndMirrorWithoutValues(): void
    {
        $collector = $this->collector();
        $summary = new NodeEnvironmentSummary(7, 1, 2, 3, 4, []);
        $wire = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10, environment: $summary));
        $collector->onSignalAgent(
            new AgentSignalData(data: DaemonNodePictureSignalData::fromArray($wire->toArray())),
            'agent/hilos_daemon_node',
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
        );
        $pages = new DaemonPictureFanOutProbeAgent();
        ClusterDaemonPictureMirror::addViewer('ak');
        $pages->tickAt($this->at(0.0));
        $this->carryClaim($collector);
        $portion = $this->carryPortion($pages);

        $this->assertEquals($summary, $portion->nodes[0]->slot?->picture->environment);
        $this->assertEquals($summary, ClusterDaemonPictureMirror::picture()?->node('n1')?->slot?->picture->environment);
        $this->assertSame(
            ['catalogKeys', 'missingRequired', 'fromExample', 'drifted', 'orphans', 'fingerprints'],
            array_keys($wire->toArray()[DaemonNodePictureSignalData::environment]),
        );
    }

    public function testThreeNodeEnvironmentLabelsReachMirrorAndProduceTheSameVerdict(): void
    {
        Hilos::$rt = new DaemonPictureFanOutRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        foreach (['n1', 'n2', 'n3'] as $nodeId) {
            Hilos::$rt->hilosClusterNodes->actions->publish($nodeId, 'master', [], null, true, microtime(true));
        }
        $this->drain();

        $collector = $this->collector();
        $inputDigest = NodeEnvironmentFingerprint::of(
            'SECRET',
            EnvCatalogConstants::TYPE_STRING,
            new EnvResolution(EnvSource::PROCESS, 'secret-value'),
            false,
        )->digest;
        foreach (['n1' => $inputDigest, 'n2' => 'fedcba9876543210', 'n3' => $inputDigest] as $nodeId => $digest) {
            $summary = new NodeEnvironmentSummary(1, 0, 0, 0, 0, [
                new NodeEnvironmentFingerprint('SECRET', 'string', EnvSource::PROCESS, false, $digest),
            ]);
            $wire = new DaemonNodePictureSignalData(new NodeDaemonPicture($nodeId, NodeRole::Master, 10, environment: $summary));
            self::assertStringNotContainsString('secret-value', (string)json_encode($wire->toArray()));
            $collector->onSignalAgent(
                new AgentSignalData(data: DaemonNodePictureSignalData::fromArray($wire->toArray())),
                'agent/hilos_daemon_node',
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
            );
        }

        $pages = new DaemonPictureFanOutProbeAgent();
        ClusterDaemonPictureMirror::addViewer('ak');
        $pages->tickAt($this->at(0.0));
        $this->carryClaim($collector);
        $portion = $this->carryPortion($pages);
        $mirror = ClusterDaemonPictureMirror::picture();
        $firstLabel = $mirror?->node('n1')?->slot?->picture->environment?->fingerprints[0]->digest;
        self::assertNotSame($inputDigest, $firstLabel);
        self::assertSame($firstLabel, $mirror?->node('n3')?->slot?->picture->environment?->fingerprints[0]->digest);
        self::assertNotSame($firstLabel, $mirror?->node('n2')?->slot?->picture->environment?->fingerprints[0]->digest);
        self::assertSame(['SECRET'], array_map(
            static fn ($row): string => $row->key, $mirror?->environmentComparison()->diverged ?? [],
        ));
        self::assertStringNotContainsString($inputDigest, (string)json_encode($portion->toArray()));
        self::assertStringNotContainsString('secret-value', (string)json_encode($portion->toArray()));
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

    public function testMasterRosterReachesNodeCollectorAndMirrorAsOneWholeSlot(): void
    {
        $node = new DaemonNodeAgent();
        $node->onStart();
        $this->drain(); // The first base report is lost; the new whole report repairs it.
        $roster = new DaemonProcessRoster([
            new DaemonWorkerPicture(1, 'regular', 101, 4096, [new DaemonAgentPicture('a', 'node', 'node')]),
        ], [], 1);
        $master = new DaemonMasterProcessRosterSignalData('standalone', $roster);
        $node->onSignalAgent(
            new AgentSignalData(data: DaemonMasterProcessRosterSignalData::fromArray($master->toArray())),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
        );
        $cron = new DaemonMasterCronSignalData('standalone', null, [
            new DaemonCronRuleReport('daily', '0 3 * * *', 123),
        ]);
        $node->onSignalAgent(
            new AgentSignalData(data: DaemonMasterCronSignalData::fromArray($cron->toArray())),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_CRON,
        );
        $node->reportIfDue($this->at(6.0));
        $reports = $this->drain();
        self::assertCount(1, $reports);
        $nodeFrame = $reports[0]->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $nodeFrame);

        $collector = $this->collector();
        $collector->onSignalAgent(
            new AgentSignalData(data: DaemonNodePictureSignalData::fromArray($nodeFrame->toArray())),
            'agent/hilos_daemon_node',
            HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT,
        );
        $pages = new DaemonPictureFanOutProbeAgent();
        ClusterDaemonPictureMirror::addViewer('ak');
        $pages->tickAt($this->at(6.1));
        $this->carryClaim($collector);
        $this->carryPortion($pages);

        $mirrorRoster = ClusterDaemonPictureMirror::picture()?->node('standalone')?->slot?->picture->processes;
        self::assertEquals($roster, $mirrorRoster);
        self::assertSame(1, $mirrorRoster?->workerRestarts24h);
        self::assertSame(
            'daily',
            ClusterDaemonPictureMirror::picture()?->node('standalone')?->slot?->picture->cron?->rules[0]->name,
        );
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

/** Runtime context carrying the cluster roster for picture fan-out tests. */
final class DaemonPictureFanOutRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
