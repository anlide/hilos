<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

/**
 * Config keys for per-agent entries in Hilos::AGENTS.
 *
 * ```php
 * public const array AGENTS = [
 *     ChatAgent::AGENT_TYPE => [
 *         AgentRegistryKey::WORKER => ChatAgent::class,
 *         AgentRegistryKey::DAEMON => ChatAgentDaemon::class,
 *     ],
 *     BotAgent::AGENT_TYPE => [
 *         AgentRegistryKey::WORKER => BotAgent::class,
 *         AgentRegistryKey::DAEMON => BotAgentDaemon::class,
 *         AgentRegistryKey::INDEXED => true,
 *     ],
 * ];
 * ```
 */
final class AgentRegistryKey
{
    /** Worker-side agent class (extends AbstractAgent). */
    public const string WORKER = 'worker';

    /** Daemon-side agent proxy class (extends AbstractAgentDaemon). */
    public const string DAEMON = 'daemon';

    /**
     * When true, worker and daemon instances require a non-null agentIndex at creation.
     */
    public const string INDEXED = 'indexed';

    /**
     * When true, the agent is started once on every node as its workers become ready, rather than
     * only on the cluster leader. Mutually exclusive with {@see self::INDEXED}: a sharded pool needs
     * an index, an every-node singleton has none.
     */
    public const string PER_NODE = 'per_node';
}
