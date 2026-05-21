<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\TopologyAgentFactory;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for topology-driven agent factory creation.
 */
final class TopologyAgentFactoryTest extends TestCase
{
    public function testCreateWorkerFromTopologyRegistry(): void
    {
        $agent = TopologyAgentFactory::createWorker(
            TopologyValidHilos::class,
            TopologyValidAgent::AGENT_TYPE,
            null,
        );

        $this->assertInstanceOf(TopologyValidAgent::class, $agent);
    }

    public function testCreateDaemonFromTopologyRegistry(): void
    {
        $daemon = TopologyAgentFactory::createDaemon(
            TopologyValidHilos::class,
            TopologyValidAgent::AGENT_TYPE,
            null,
        );

        $this->assertInstanceOf(TopologyValidAgentDaemon::class, $daemon);
    }

    public function testCreateIndexedDaemonRequiresAgentIndex(): void
    {
        $this->expectException(AgentIndexRequiredException::class);

        TopologyAgentFactory::createDaemon(
            TopologyIndexedAgentFactoryHilos::class,
            TopologyIndexedFactoryAgent::AGENT_TYPE,
            null,
        );
    }

    public function testCreateIndexedDaemonWithAgentIndex(): void
    {
        $daemon = TopologyAgentFactory::createDaemon(
            TopologyIndexedAgentFactoryHilos::class,
            TopologyIndexedFactoryAgent::AGENT_TYPE,
            '42',
        );

        $this->assertSame('42', $daemon->getIndex());
    }
}

final class TopologyIndexedAgentFactoryHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedFactoryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedFactoryAgent::class,
            AgentRegistryKey::DAEMON => TopologyIndexedFactoryAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}
