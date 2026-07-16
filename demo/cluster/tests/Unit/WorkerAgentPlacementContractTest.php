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
    private WorkerAgentDaemon $daemon;

    protected function setUp(): void
    {
        $this->daemon = new WorkerAgentDaemon();
    }

    public function testAgentTypeMatchesConstant(): void
    {
        $this->assertSame(AgentType::WORKER, $this->daemon->getType());
        $this->assertNull($this->daemon->getIndex());
    }

    public function testAgentIsMonopolistic(): void
    {
        $this->assertTrue($this->daemon->requiresMonopolisticProcess());
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
