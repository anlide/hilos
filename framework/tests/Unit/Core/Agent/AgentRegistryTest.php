<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Agent;

use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-agent registry-entry readers (HIL-380).
 *
 * Locks the {@see AgentRegistry::startsOnEveryNode()} predicate: only the exact boolean true opts
 * an entry into the every-node start pass; a missing key, a false, a non-boolean, or a non-array
 * entry all read as false.
 */
final class AgentRegistryTest extends TestCase
{
    public function testStartsOnEveryNodeTrueWhenFlagSet(): void
    {
        $this->assertTrue(AgentRegistry::startsOnEveryNode([AgentRegistryKey::PER_NODE => true]));
    }

    public function testStartsOnEveryNodeFalseWhenFlagAbsentOrFalse(): void
    {
        $this->assertFalse(AgentRegistry::startsOnEveryNode([]));
        $this->assertFalse(AgentRegistry::startsOnEveryNode([AgentRegistryKey::PER_NODE => false]));
    }

    public function testStartsOnEveryNodeFalseForNonBooleanOrNonArrayEntry(): void
    {
        $this->assertFalse(AgentRegistry::startsOnEveryNode([AgentRegistryKey::PER_NODE => 'yes']));
        $this->assertFalse(AgentRegistry::startsOnEveryNode([AgentRegistryKey::PER_NODE => 1]));
        $this->assertFalse(AgentRegistry::startsOnEveryNode(null));
        $this->assertFalse(AgentRegistry::startsOnEveryNode('per_node'));
    }
}
