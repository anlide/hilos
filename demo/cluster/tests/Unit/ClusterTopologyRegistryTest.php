<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Agents\WorkerAgent;
use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Hilos;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the cluster demo's project topology registry and its headless,
 * transport-only surface.
 */
final class ClusterTopologyRegistryTest extends TestCase
{
    public function testProjectIsHeadlessWithNoPagesOrGroups(): void
    {
        // The demo carries no browser surface at all: no pages, groups, or tables.
        $this->assertSame([], Hilos::PAGES);
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], Hilos::TABLES);
        $this->assertSame([], Hilos::getPageRoutes());
    }

    public function testRoutingSurfaceIsEmpty(): void
    {
        // Nothing routes: no page/agent actions, no server-driven signals, no commands.
        $this->assertSame([], Hilos::getPageActionRoutes());
        $this->assertSame([], Hilos::getActionAgentRoutes());
        $this->assertSame([], Hilos::getPageSignalAgentRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], Hilos::getCommandAgentRoutes());
        $this->assertSame([], Hilos::getGroupRoutes());
    }

    public function testAgentRegistryHasOnlyThePlaceableWorker(): void
    {
        $this->assertSame([AgentType::WORKER], array_keys(Hilos::AGENTS));

        $entry = Hilos::AGENTS[AgentType::WORKER];
        $this->assertSame(WorkerAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(WorkerAgentDaemon::class, AgentRegistry::daemonClass($entry));
        // The leader places a fleet, so the registry must hand each instance its index.
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertTrue(is_subclass_of(WorkerAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(AgentType::WORKER, WorkerAgent::AGENT_TYPE);
    }

    public function testNoAgentIsStartedOnTheBootstrapSignal(): void
    {
        // The only agent is leader-placed over the peer channel, never booted from the
        // system bootstrap list, so that list stays empty.
        $method = new ReflectionMethod(ClusterSignalRouter::class, 'getDefaultSystemBootstrapAgentTypes');
        $bootstrapAgents = $method->invoke(new ClusterSignalRouter());

        $this->assertSame([], $bootstrapAgents);
    }
}
