<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Agent;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-agent registry-entry readers (HIL-380, HIL-667).
 *
 * Locks the two placement axes and the predicate over them: only an enum case declares an axis,
 * and anything else — a missing key, a string, a boolean, a non-array entry — reads as the
 * fail-safe default, so a malformed declaration under-runs an agent instead of double-running it.
 */
final class AgentRegistryTest extends TestCase
{
    public function testScopeDefaultsToClusterWide(): void
    {
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope([]));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope(null));
    }

    public function testScopeReadsTheDeclaredCase(): void
    {
        $this->assertSame(
            AgentScope::NODE,
            AgentRegistry::scope([AgentRegistryKey::SCOPE => AgentScope::NODE]),
        );
    }

    public function testScopeFallsBackToClusterForANonCaseValue(): void
    {
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope([AgentRegistryKey::SCOPE => 'node']));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope([AgentRegistryKey::SCOPE => true]));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope('scope'));
    }

    public function testPlacementDefaultsToLeader(): void
    {
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement([]));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement(null));
    }

    public function testPlacementReadsTheDeclaredCase(): void
    {
        $this->assertSame(
            AgentPlacement::POLICY,
            AgentRegistry::placement([AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY]),
        );
    }

    public function testPlacementFallsBackToLeaderForANonCaseValue(): void
    {
        $this->assertSame(
            AgentPlacement::LEADER,
            AgentRegistry::placement([AgentRegistryKey::PLACEMENT => 'policy']),
        );
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement('placement'));
    }

    public function testStartsOnEveryNodeIsTheNodeScope(): void
    {
        $this->assertTrue(AgentRegistry::startsOnEveryNode([AgentRegistryKey::SCOPE => AgentScope::NODE]));
    }

    public function testStartsOnEveryNodeFalseForClusterScopeOrNoDeclaration(): void
    {
        $this->assertFalse(AgentRegistry::startsOnEveryNode([]));
        $this->assertFalse(AgentRegistry::startsOnEveryNode([AgentRegistryKey::SCOPE => AgentScope::CLUSTER]));
        $this->assertFalse(AgentRegistry::startsOnEveryNode([AgentRegistryKey::SCOPE => true]));
        $this->assertFalse(AgentRegistry::startsOnEveryNode(null));
    }
}
