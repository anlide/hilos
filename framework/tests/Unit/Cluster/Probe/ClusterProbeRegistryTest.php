<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\BallastProbeAgent;
use Hilos\Cluster\Probe\BallastProbeAgentDaemon;
use Hilos\Cluster\Probe\ClaimerProbeAgent;
use Hilos\Cluster\Probe\ClaimerProbeAgentDaemon;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\DbProbeAgent;
use Hilos\Cluster\Probe\DbProbeAgentDaemon;
use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Cluster\Probe\FleetProbeAgentDaemon;
use Hilos\Cluster\Probe\RtSetProbeAgent;
use Hilos\Cluster\Probe\RtSetProbeAgentDaemon;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\HilosProbeNote;
use PHPUnit\Framework\TestCase;

/**
 * The records a project copies when it lists a cluster probe, and what each of them declares.
 *
 * A project writes nothing of these rows but the key: the value is {@see ClusterProbe::AGENTS},
 * so the flags every cluster stand stands on are pinned here, once, rather than in each demo.
 */
final class ClusterProbeRegistryTest extends TestCase
{
    public function testTheRegistryHoldsTheFiveProbesUnderTheirFrameworkTypes(): void
    {
        $this->assertSame(
            [
                HilosAgentType::HILOS_PROBE_FLEET,
                HilosAgentType::HILOS_PROBE_CLAIMER,
                HilosAgentType::HILOS_PROBE_BALLAST,
                HilosAgentType::HILOS_PROBE_DB,
                HilosAgentType::HILOS_PROBE_RT_SET,
            ],
            array_keys(ClusterProbe::AGENTS),
        );
        $this->assertSame('hilos_probe_fleet', HilosAgentType::HILOS_PROBE_FLEET, 'The harness addresses the fleet by this id');
        $this->assertSame('hilos_probe_claimer', HilosAgentType::HILOS_PROBE_CLAIMER);
        $this->assertSame('hilos_probe_ballast', HilosAgentType::HILOS_PROBE_BALLAST);
    }

    public function testTheFleetIsAnIndexedPoolPlacedByPolicy(): void
    {
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET];
        $this->assertSame(FleetProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(FleetProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        // The leader places a fleet, so the registry must hand each instance its index.
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        // Both placement axes, so the declaration the harness depends on cannot change in
        // silence: one instance per fleet index cluster-wide, on the node the policy picked.
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertTrue(is_subclass_of(FleetProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(HilosAgentType::HILOS_PROBE_FLEET, FleetProbeAgent::AGENT_TYPE);

        // Its own row by its index, and nothing else of the collection.
        $this->assertSame(
            [HilosProbeFleetStatus::RT_COLLECTION => TruthSourceOperation::BY_KIND],
            FleetProbeAgent::OWNS_RT_ROWS,
        );
        $this->assertSame(['3'], (new FleetProbeAgent('3'))->ownedRtRowKeys(HilosProbeFleetStatus::RT_COLLECTION));
    }

    public function testTheFleetCarriesNoCommandOfItsOwn(): void
    {
        // The protected-mode trio stays with the index agent: a project that lists the fleet
        // beside its own index agent would declare each of those commands twice, and one command
        // declared by two agents refuses the start.
        $this->assertSame([], FleetProbeAgent::AGENT_COMMANDS);
    }

    public function testTheClaimerIsDeclaredSoThatNothingStartsItByItself(): void
    {
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_CLAIMER];
        $this->assertSame(ClaimerProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(ClaimerProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(ClaimerProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(HilosAgentType::HILOS_PROBE_CLAIMER, ClaimerProbeAgent::AGENT_TYPE);

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
        $daemon = new ClaimerProbeAgentDaemon('0');
        $this->assertSame([ClusterProbe::CAPABILITY_WORKER], $daemon->requiredCapabilities());
        $this->assertFalse($daemon->requiresMonopolisticProcess());

        // The whole collection the fleet owns by rows, which is the overlap the guard names.
        $this->assertSame(
            [HilosProbeFleetStatus::RT_COLLECTION => TruthSourceOperation::BY_KIND],
            ClaimerProbeAgent::OWNS_RT,
        );
    }

    public function testTheBallastCostsRamAndRequiresNoTag(): void
    {
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_BALLAST];
        $this->assertSame(BallastProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(BallastProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(BallastProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(HilosAgentType::HILOS_PROBE_BALLAST, BallastProbeAgent::AGENT_TYPE);

        // Indexed and policy-placed like the claimer: only a scenario brings one up, and the
        // leader chooses its node.
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));

        // No tag, so only the rule "no declared capacity, no placed work" keeps it off the
        // masters - the rule the capacity scenario checks (HIL-448). The cost is what it is for.
        $daemon = new BallastProbeAgentDaemon('0');
        $this->assertSame([], $daemon->requiredCapabilities());
        $this->assertSame(
            [ClusterProbe::RESOURCE_RAM => BallastProbeAgentDaemon::RAM_COST],
            $daemon->placementProfile()->costs,
        );
        $this->assertSame(['ram' => 2.0], $daemon->placementProfile()->costs, 'The scenario mirrors ram=2');
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testTheDbProbeIsANodeReplicaAndNothingElse(): void
    {
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB];
        $this->assertSame(DbProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(DbProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(DbProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(HilosAgentType::HILOS_PROBE_DB, DbProbeAgent::AGENT_TYPE);

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

        $this->assertSame(
            [CliCommands::CLUSTER_TEST_DB_WRITE, CliCommands::CLUSTER_TEST_DB_READ],
            DbProbeAgent::AGENT_COMMANDS,
        );
    }

    public function testTheDbProbeClaimOnTheSettingsIsIncomplete(): void
    {
        // Adding and updating, never the whole: beside the settings library, which owns the
        // collection whole, an incomplete claim is a lawful co-owner and a complete one would be
        // a second full owner the startup refuses.
        $claims = OwnershipDeclaration::dbCollectionsOf(DbProbeAgent::class);

        $this->assertArrayHasKey(HilosDbContext::settings, $claims);
        $this->assertFalse($claims[HilosDbContext::settings]->isComplete());
        $this->assertSame(
            [HilosDbContext::settings => [TruthSourceOperation::Add, TruthSourceOperation::Update]],
            DbProbeAgent::OWNS_DB,
        );
    }

    public function testTheSetProbeIsANodeReplicaThatClaimsItsNodesSet(): void
    {
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_RT_SET];
        $this->assertSame(RtSetProbeAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(RtSetProbeAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(is_subclass_of(RtSetProbeAgentDaemon::class, AbstractAgentDaemon::class));
        $this->assertSame(HilosAgentType::HILOS_PROBE_RT_SET, RtSetProbeAgent::AGENT_TYPE);

        // A replica on every node, as the database probe is: scenario 20 names the node that
        // writes its set and the node refused it, and both have to be particular nodes.
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertArrayNotHasKey(AgentRegistryKey::PLACEMENT, $entry);
        $daemon = new RtSetProbeAgentDaemon();
        $this->assertSame([], $daemon->requiredCapabilities());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
        $this->assertSame([CliCommands::CLUSTER_TEST_RT_WRITE], RtSetProbeAgent::AGENT_COMMANDS);

        // The claim the scenario stands on: the probe notes, by the set of this node, with every
        // operation - and the set is cut by the node a note belongs to.
        $this->assertSame([HilosProbeNote::RT_COLLECTION => TruthSourceOperation::BY_KIND], RtSetProbeAgent::OWNS_RT_SET);
        $this->assertSame(HilosProbeNote::nodeId, HilosProbeNote::SET_VIA);
        // Off a cluster there is no node and so no set; the start gate keeps a probe from ever
        // getting that far, and the key stays empty all the same.
        $this->assertSame('', (new RtSetProbeAgent())->ownedRtSetKey(HilosProbeNote::RT_COLLECTION));
    }

    public function testTheRuntimeKeysAreTheOnesTheHarnessReads(): void
    {
        $this->assertSame('hilosProbeFleetStatuses', HilosProbeFleetStatus::RT_COLLECTION);
        $this->assertSame('hilosProbeNotes', HilosProbeNote::RT_COLLECTION);
    }

    public function testEveryProbeIsRecognizedByItsWorkerClass(): void
    {
        foreach (ClusterProbe::AGENTS as $agentType => $entry) {
            $this->assertTrue(ClusterProbe::isProbe(AgentRegistry::workerClass($entry)), "{$agentType} is a probe");
        }

        $this->assertFalse(ClusterProbe::isProbe(self::class));
        $this->assertFalse(ClusterProbe::isProbe(null));
    }
}
