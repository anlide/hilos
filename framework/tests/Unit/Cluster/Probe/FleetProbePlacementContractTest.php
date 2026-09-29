<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\FleetProbeAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use PHPUnit\Framework\TestCase;

/**
 * Pins the placement contract the multi-node harness depends on: a member of the probe fleet
 * shares the node's regular workers, is capability-gated, and is declared cluster-scoped but
 * placed by policy — so the leader places it on a data-plane node rather than hosting it itself.
 */
final class FleetProbePlacementContractTest extends TestCase
{
    /** @var string Fleet member index the daemon under test stands for */
    private const string FLEET_INDEX = '3';

    private FleetProbeAgentDaemon $daemon;

    protected function setUp(): void
    {
        $this->daemon = new FleetProbeAgentDaemon(self::FLEET_INDEX);
    }

    public function testAgentTypeMatchesConstant(): void
    {
        $this->assertSame(HilosAgentType::HILOS_PROBE_FLEET, $this->daemon->getType());
        // A fleet member, so it carries the index the leader placed it under.
        $this->assertSame(self::FLEET_INDEX, $this->daemon->getIndex());
    }

    public function testAgentSharesTheNodeRegularWorkers(): void
    {
        // The monopolistic pool never grows a worker for an indexed instance (HIL-998), and a
        // fleet member is one — on failover a node must take all of the fleet.
        $this->assertFalse($this->daemon->requiresMonopolisticProcess());
    }

    public function testAgentIsPlacedByPolicyNotHostedByTheLeader(): void
    {
        // The declaration is the whole point: one instance per fleet index cluster-wide, on
        // the node the policy picked, which is what lets the leader place it remotely on a
        // data-plane node instead of hosting it itself.
        $entry = ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
    }

    public function testAgentIsGatedToTheWorkerCapability(): void
    {
        $this->assertSame([ClusterProbe::CAPABILITY_WORKER], $this->daemon->requiredCapabilities());
    }
}
