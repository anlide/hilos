<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use PHPUnit\Framework\TestCase;

/**
 * Pins the placement contract the multi-node harness depends on: the WORKER agent
 * is a monopolistic, capability-gated, per-node (non-singleton) agent, so the leader
 * places it on a data-plane node rather than running it as a leader-singleton.
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

    public function testAgentIsPerNodeNotLeaderSingleton(): void
    {
        // False is the whole point: it must be startable on a node that is not the
        // leader, because the leader places it remotely on a data-plane node.
        $this->assertFalse($this->daemon->requiresClusterLeadership());
    }

    public function testAgentIsGatedToTheWorkerCapability(): void
    {
        $this->assertSame([ClusterCapability::WORKER], $this->daemon->requiredCapabilities());
    }
}
