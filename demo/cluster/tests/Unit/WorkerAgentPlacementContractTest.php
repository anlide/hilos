<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Hilos;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use PHPUnit\Framework\TestCase;

/**
 * Pins the placement contract the multi-node harness depends on: the WORKER agent shares the
 * node's regular workers, is capability-gated, and is declared cluster-scoped but placed by
 * policy — so the leader places it on a data-plane node rather than hosting it itself.
 */
final class WorkerAgentPlacementContractTest extends TestCase
{
    /** @var string Fleet member index the daemon under test stands for */
    private const string FLEET_INDEX = '3';

    private WorkerAgentDaemon $daemon;

    protected function setUp(): void
    {
        $this->daemon = new WorkerAgentDaemon(self::FLEET_INDEX);
    }

    public function testAgentTypeMatchesConstant(): void
    {
        $this->assertSame(AgentType::WORKER, $this->daemon->getType());
        // A fleet member, so it carries the index the leader placed it under.
        $this->assertSame(self::FLEET_INDEX, $this->daemon->getIndex());
    }

    public function testAgentSharesTheNodeRegularWorkers(): void
    {
        // Monopolistic workers are pre-forked, which would cap how much of the fleet a
        // node can take — and on failover it must take all of it.
        $this->assertFalse($this->daemon->requiresMonopolisticProcess());
    }

    public function testAgentIsPlacedByPolicyNotHostedByTheLeader(): void
    {
        // The declaration is the whole point: one instance per fleet index cluster-wide, on
        // the node the policy picked, which is what lets the leader place it remotely on a
        // data-plane node instead of hosting it itself.
        $entry = Hilos::AGENTS[AgentType::WORKER];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
    }

    public function testAgentIsGatedToTheWorkerCapability(): void
    {
        $this->assertSame([ClusterCapability::WORKER], $this->daemon->requiredCapabilities());
    }
}
