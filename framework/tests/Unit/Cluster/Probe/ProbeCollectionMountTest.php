<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack;
use Hilos\Runtime\View\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\View\Collection\HilosProbeNotes;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * The probe collections are mounted in every project, and read by nobody but a probe (HIL-1211).
 *
 * The framework mounts them so no project has to, but does not declare itself their reader: a
 * mount that did would put every node of a cluster on the address list of every fleet write,
 * masters included, though they run no probe - the replication scenario of the cluster stand
 * asserts that only the nodes hosting the fleet read its collection. A probe's claim is its own
 * reader interest, so the probes read what they write without the framework's help.
 */
final class ProbeCollectionMountTest extends TestCase
{
    protected function tearDown(): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosSessionRotation::RT_COLLECTION));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosSessionToastStack::RT_COLLECTION));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosClusterNode::RT_COLLECTION));

        parent::tearDown();
    }

    public function testBothCollectionsAreMountedAndGuardedInEveryProject(): void
    {
        $rt = $this->mountedContext();

        $this->assertInstanceOf(HilosProbeFleetStatuses::class, $rt->getRtCollection(HilosProbeFleetStatus::RT_COLLECTION));
        $this->assertInstanceOf(HilosProbeNotes::class, $rt->getRtCollection(HilosProbeNote::RT_COLLECTION));
        $rt->assertFeatureRuntimeIntact();
    }

    public function testTheMountDeclaresNoReaderOfEitherCollection(): void
    {
        $this->mountedContext();

        // Asked of the consumers, which is what a process reports to its master and what puts a
        // node on the address list of a collection's writes.
        $this->assertFalse(
            SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, HilosProbeFleetStatus::RT_COLLECTION),
            'A node that runs no fleet member must stay off the address list of every fleet write',
        );
        $this->assertFalse(SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, HilosProbeNote::RT_COLLECTION));
        // The contrast that makes the two lines above mean something: the cluster roster is
        // mounted by the same call and read everywhere.
        $this->assertTrue(SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, HilosClusterNode::RT_COLLECTION));
    }

    /**
     * @return RtContext Context of a project with nothing of its own, after the framework's mount
     */
    private function mountedContext(): RtContext
    {
        $rt = new ProbeMountTestRtContext();
        $rt->mountFeatureRuntime([]);
        $rt->configure();

        return $rt;
    }
}

/**
 * Runtime context of a project that registers nothing of its own.
 */
final class ProbeMountTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
