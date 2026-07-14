<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cluster-leadership flag on agent daemons (HIL-340).
 */
final class AgentDaemonClusterLeadershipFlagTest extends TestCase
{
    public function testDefaultIsLeaderOnly(): void
    {
        $daemon = new DefaultLeadershipTestAgentDaemon();

        $this->assertTrue(
            $daemon->requiresClusterLeadership(),
            'AbstractAgentDaemon must default to leader-only so an unmarked agent under-runs rather than double-runs.',
        );
    }

    public function testPerNodeAgentOptsOut(): void
    {
        $daemon = new PerNodeTestAgentDaemon();

        $this->assertFalse($daemon->requiresClusterLeadership());
    }
}

/**
 * Agent daemon that keeps the base leader-only default.
 */
final class DefaultLeadershipTestAgentDaemon extends AbstractAgentDaemon
{
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Per-node agent daemon that opts out of the leader-only gate.
 */
final class PerNodeTestAgentDaemon extends AbstractAgentDaemon
{
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function requiresClusterLeadership(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}
