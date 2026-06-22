<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the daemon-side agent-started tracking that the WebSocket readiness gate reads.
 */
final class AgentManagerDaemonReadinessTest extends TestCase
{
    public function testTracksAgentStartedAcrossItsLifecycle(): void
    {
        $manager = new ReadinessTestAgentManagerDaemon();
        $manager->addAgent('chat', $this->createMock(AgentDaemonInterface::class), 1, true);

        $this->assertFalse($manager->isAgentStarted('chat'));

        $manager->handleAgentStarted(new WorkerAgentStartedDTO('chat', 'chat'));
        $this->assertTrue($manager->isAgentStarted('chat'));

        $manager->removeAgent('chat');
        $this->assertFalse($manager->isAgentStarted('chat'));
    }
}

/**
 * Concrete manager whose agents are registered directly via addAgent(); createAgentDaemon is unused.
 */
final class ReadinessTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('createAgentDaemon is unused: tests register agents via addAgent()');
    }
}
