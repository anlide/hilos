<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FleetProbeReportingTest extends TestCase
{
    public function testMemberWorksBeforeTheSnapshotAndReportsTheFleetOnceItIsReady(): void
    {
        $rt = Hilos::$rt;
        $agentId = ExecutionContext::currentAgentId();
        $member = new FleetProbeAgent('1');
        $other = new FleetProbeAgent('2');
        $consumer = SourceConsumer::agent($member->getId());
        $key = HilosProbeFleetStatus::RT_COLLECTION;
        Hilos::$rt = new class extends RtContext {
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureRuntime([]);
        SourceInterestRegistry::register(SourceChange::KIND_RT, $key, $consumer);
        RtTruthSourceRegistry::register($key, TruthSourceKeys::listed('1'), $member->getId());
        RtTruthSourceRegistry::register($key, TruthSourceKeys::listed('2'), $other->getId());
        try {
            ExecutionContext::setCurrentAgentId($other->getId());
            $other->onStart();
            ExecutionContext::setCurrentAgentId($member->getId());
            SourceInterestRegistry::readsWhatIsDelivered();

            $member->onStart();
            $member->onTick();
            self::assertNull(Hilos::$rt->hilosProbeFleetStatuses['1']);
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, $key));

            SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
            $member->onTick();
            self::assertSame(2, Hilos::$rt->hilosProbeFleetStatuses['1']?->jobsDone);
            self::assertSame(1, Hilos::$rt->hilosProbeFleetStatuses['1']?->rowsSeen);

            new ReflectionProperty(FleetProbeAgent::class, 'reportDueAt')->setValue($member, 0.0);
            $member->onTick();
            self::assertSame(3, Hilos::$rt->hilosProbeFleetStatuses['1']?->jobsDone);
            self::assertSame(2, Hilos::$rt->hilosProbeFleetStatuses['1']?->rowsSeen);
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer($consumer);
            RtTruthSourceRegistry::unregisterAgent($member->getId());
            RtTruthSourceRegistry::unregisterAgent($other->getId());
            ExecutionContext::setCurrentAgentId($agentId);
            Hilos::$rt = $rt;
        }
    }
}
