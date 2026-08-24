<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Agent;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the placement matrix a registry entry resolves to (HIL-340, HIL-667).
 *
 * The successor of the leadership flag that used to live on the agent daemon: the question
 * "where may this agent run" is now answered once, by the entry, and both halves of the answer
 * come from the same place. Pins the three live cells of the matrix in
 * docs/agents/architecture/entity-libraries.md, including the one that matters most — an entry
 * that declares neither axis is still today's leader-hosted cluster singleton.
 */
final class AgentPlacementMatrixTest extends TestCase
{
    public function testAnUndeclaredEntryIsTodaysLeaderHostedSingleton(): void
    {
        $entry = [AgentRegistryKey::WORKER => 'W', AgentRegistryKey::DAEMON => 'D'];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($entry));
    }

    public function testAnIndexedPoolIsStillLeaderHostedUntilItSaysOtherwise(): void
    {
        $entry = [AgentRegistryKey::INDEXED => true];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($entry));
    }

    public function testAPolicyPlacedSingletonKeepsTheClusterScope(): void
    {
        $entry = [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY];

        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertFalse(AgentRegistry::startsOnEveryNode($entry));
    }

    public function testAnEveryNodeReplicaIsScopeAloneAndPicksNoNode(): void
    {
        $entry = [AgentRegistryKey::SCOPE => AgentScope::NODE];

        $this->assertTrue(AgentRegistry::startsOnEveryNode($entry));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($entry));
    }
}
