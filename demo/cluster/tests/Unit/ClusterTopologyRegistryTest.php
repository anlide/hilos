<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Agents\WorkerAgent;
use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Hilos;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
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

    public function testRoutingSurfaceIsEmptyApartFromTheProtectedModeDrive(): void
    {
        // Nothing routes: no page/agent actions, no server-driven signals.
        $this->assertSame([], Hilos::getPageActionRoutes());
        $this->assertSame([], Hilos::getActionAgentRoutes());
        $this->assertSame([], Hilos::getPageSignalAgentRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], Hilos::getGroupRoutes());

        // The one exception, and the reason it is here: this demo is headless and has no Hilos
        // index, so the worker agent is the only carrier that can drive the clustered entry
        // path - the leader's quiesce round and a follower's fail-closed refusal.
        $this->assertSame([
            CliCommands::PROTECTED_MODE_TEST_ENTER => AgentType::WORKER,
            CliCommands::PROTECTED_MODE_TEST_LEAVE => AgentType::WORKER,
            CliCommands::PROTECTED_MODE_TEST_OPEN => AgentType::WORKER,
        ], Hilos::getCommandAgentRoutes());
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

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The deferred half of the same check. It stays here on a project that declares no
        // feature precisely because that is a state worth guarding: the demo carries a historical
        // hilos_settings migration, and the day someone declares SETTINGS over it, this test is
        // what asks for the rest.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            ClusterRtContext::class,
        );

        $this->addToAssertionCount(1);
    }
}
