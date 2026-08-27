<?php

declare(strict_types=1);

namespace Demo\Cluster;

use Demo\Cluster\Agents\ClaimerAgent;
use Demo\Cluster\Agents\WorkerAgent;
use Demo\Cluster\Core\Agent\Daemon\ClaimerAgentDaemon;
use Demo\Cluster\Core\Agent\Daemon\WorkerAgentDaemon;
use Demo\Cluster\Database\ClusterDbContext;
use Demo\Cluster\Environment\ClusterEnvCatalog;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Hilos - Main app facade for the cluster demo.
 *
 * A deliberately minimal, headless project: no pages, no WebSocket, no browser
 * context — just the placeable no-op fleet the multi-node cluster harness (HIL-185)
 * observes, and the claimer it stages a two-owner split with. Its only runtime state
 * is the fleet's status collection alongside the framework-owned protected mode
 * singleton, mounted per node so the daemon truth source has a local writer seam.
 * The whole CLUSTER_* configuration is inherited from the framework env catalog, so
 * the facade only names the env catalog, the agent registry, the database context,
 * and this minimal runtime context.
 *
 * @property-read ClusterDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read ClusterRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = ClusterEnvCatalog::class;

    public const array PAGES = [];

    public const array AGENTS = [
        WorkerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => WorkerAgent::class,
            AgentRegistryKey::DAEMON => WorkerAgentDaemon::class,
            // The leader places a fleet of these, so every instance carries its own index.
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ClaimerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ClaimerAgent::class,
            AgentRegistryKey::DAEMON => ClaimerAgentDaemon::class,
            // Indexed for the same reason the fleet is, and for one more: the framework's
            // policy-placement sweep skips indexed agents, and the demo's own supervisor places
            // only the fleet. So nothing brings a claimer up until a scenario addresses one,
            // which is what keeps the deliberate split out of every other run.
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
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

    /**
     * Creates the cluster demo runtime context.
     *
     * @return ?ClusterRtContext Cluster demo runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ClusterRtContext();
    }
}
