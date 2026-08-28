<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Agents\ClaimerAgent;
use Demo\Cluster\Agents\DbProbeAgent;
use Demo\Cluster\Agents\WorkerAgent;
use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Demo\Cluster\Core\Agent\Daemon\ClaimerAgentDaemon;
use Demo\Cluster\Core\Agent\Daemon\DbProbeAgentDaemon;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Hilos;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Constants\CliCommands;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
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
        $this->assertSame([], Hilos::getPageAgentIndexRoutes());
    }

    public function testRoutingSurfaceIsEmptyApartFromTheProtectedModeDrive(): void
    {
        // Nothing routes: no page/agent actions, no server-driven signals.
        $this->assertSame([], Hilos::getPageActionRoutes());
        $this->assertSame([], Hilos::getActionAgentRoutes());
        $this->assertSame([], Hilos::getPageSignalAgentRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], Hilos::getGroupRoutes());

        // The exceptions are commands, and both sets are here for the same reason: this demo
        // is headless, so an agent is the only thing that can carry work a scenario drives. The
        // worker agent drives the clustered protected-mode entry path - the leader's quiesce
        // round and a follower's fail-closed refusal - and the probe carries the database pair,
        // which the master must not answer because a database read blocks.
        $this->assertSame([
            CliCommands::PROTECTED_MODE_TEST_ENTER => AgentType::WORKER,
            CliCommands::PROTECTED_MODE_TEST_LEAVE => AgentType::WORKER,
            CliCommands::PROTECTED_MODE_TEST_OPEN => AgentType::WORKER,
            CliCommands::CLUSTER_TEST_DB_WRITE => AgentType::DB_PROBE,
            CliCommands::CLUSTER_TEST_DB_READ => AgentType::DB_PROBE,
        ], Hilos::getCommandAgentRoutes());
    }

    public function testAgentRegistryHasThePlaceableWorkerAndTheClaimer(): void
    {
        $this->assertSame([AgentType::WORKER, AgentType::CLAIMER, AgentType::DB_PROBE], array_keys(Hilos::AGENTS));

        $entry = Hilos::AGENTS[AgentType::WORKER];
        $this->assertSame(WorkerAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(WorkerAgentDaemon::class, AgentRegistry::daemonClass($entry));
        // The leader places a fleet, so the registry must hand each instance its index.
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        // Both placement axes, so the declaration the harness depends on cannot change in
        // silence: one instance per fleet index cluster-wide, on the node the policy picked.
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertTrue(is_subclass_of(WorkerAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(AgentType::WORKER, WorkerAgent::AGENT_TYPE);
    }

    public function testTheClaimerIsDeclaredSoThatNothingStartsItByItself(): void
    {
        $entry = Hilos::AGENTS[AgentType::CLAIMER];
        $this->assertSame(ClaimerAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(ClaimerAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(ClaimerAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(AgentType::CLAIMER, ClaimerAgent::AGENT_TYPE);

        // The load-bearing pair, and the reason this agent can live in the registry at all: an
        // agent that stages a two-owner split must reach the mesh only when a scenario asks for
        // it. INDEXED keeps it out of the framework's policy-placement sweep, which places the
        // unindexed ones by itself, and POLICY keeps it off the leader's own node - it has to
        // land on the data plane, where the fleet writes, or it would clash with nobody.
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));

        // Gated to the data plane like a fleet member, because that is where the rows it means
        // to claim are already written; a claimer the policy could only put on a coordination
        // node would clash with nobody and the scenario would pass on nothing.
        $daemon = new ClaimerAgentDaemon('0');
        $this->assertSame([ClusterCapability::WORKER], $daemon->requiredCapabilities());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testTheProbeIsANodeReplicaAndNothingElse(): void
    {
        $entry = Hilos::AGENTS[AgentType::DB_PROBE];
        $this->assertSame(DbProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(DbProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(DbProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(AgentType::DB_PROBE, DbProbeAgent::AGENT_TYPE);

        // The declaration scenario 11 stands on: one replica on every node, so the node that
        // writes and the node that reads are two particular nodes rather than wherever a
        // placement landed. Neither of the two axes a placed agent carries may stand beside it,
        // and this pins that as much as the scope - a node replica has no index to hand out and
        // no node to pick, and topology validation refuses either next to it.
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertArrayNotHasKey(AgentRegistryKey::PLACEMENT, $entry);

        // Placed on every node including the coordination ones, so it gates on no capability -
        // unlike the fleet, which only a WORKER node may host.
        $daemon = new DbProbeAgentDaemon();
        $this->assertSame([], $daemon->requiredCapabilities());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testNoAgentIsStartedOnTheBootstrapSignal(): void
    {
        // Nothing this demo registers is booted from the signal: the fleet and the claimer are
        // leader-placed over the peer channel, and the probe comes up with its node's own
        // workers, so the bootstrap list stays empty.
        $method = new ReflectionMethod(ClusterSignalRouter::class, 'getDefaultSystemBootstrapAgentTypes');
        $bootstrapAgents = $method->invoke(new ClusterSignalRouter());

        $this->assertSame([], $bootstrapAgents);
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
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
