<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OAuth agent placement contract (HIL-281).
 *
 * Pins the daemon as a cluster-leader-pinned monopolistic singleton under the
 * framework OAuth agent type — the network-drive orchestration itself is exercised
 * offline at e2e through the stub provider (mirrors the backup agent's e2e coverage).
 */
final class OAuthAgentPlacementTest extends TestCase
{
    public function testAgentAndDaemonShareTheFrameworkOAuthType(): void
    {
        $this->assertSame(HilosAgentType::HILOS_OAUTH, AbstractOAuthAgent::AGENT_TYPE);
        $this->assertSame(HilosAgentType::HILOS_OAUTH, AbstractOAuthAgentDaemon::AGENT_TYPE);
    }

    public function testDaemonIsAMonopolisticUnindexedSingleton(): void
    {
        $daemon = new class extends AbstractOAuthAgentDaemon {};

        $this->assertTrue($daemon->requiresMonopolisticProcess());
        $this->assertNull($daemon->getIndex());
        $this->assertSame(HilosAgentType::HILOS_OAUTH, $daemon->getType());
    }

    public function testAnUndeclaredRegistryEntryLeavesItPinnedToTheLeader(): void
    {
        // The agent is leader-pinned by declaring nothing: a project registers it with worker
        // and daemon alone, and both axes fall back to today's cluster singleton.
        $entry = [];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($entry));
    }
}
