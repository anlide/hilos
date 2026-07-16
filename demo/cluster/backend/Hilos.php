<?php

declare(strict_types=1);

namespace Demo\Cluster;

use Demo\Cluster\Agents\WorkerAgent;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Database\ClusterDbContext;
use Demo\Cluster\Environment\ClusterEnvCatalog;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;

/**
 * Hilos - Main app facade for the cluster demo.
 *
 * A deliberately minimal, headless project: no pages, no WebSocket, no runtime or
 * browser context — just the one placeable no-op agent the multi-node cluster
 * harness (HIL-185) observes. The whole CLUSTER_* configuration is inherited from
 * the framework env catalog, so the facade only names the env catalog, the agent
 * registry, and the database context.
 *
 * @property-read ClusterDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 */
final class Hilos extends \Hilos\Hilos
{
    protected const string ENV_CATALOG = ClusterEnvCatalog::class;

    public const array PAGES = [];

    public const array AGENTS = [
        WorkerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => WorkerAgent::class,
            AgentRegistryKey::DAEMON => WorkerAgentDaemon::class,
        ],
    ];

    /**
     * Creates the cluster demo database context.
     *
     * @return ClusterDbContext Cluster demo database context
     */
    protected static function createDb(): DbContext
    {
        return new ClusterDbContext();
    }
}
