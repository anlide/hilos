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
     * How many instances of the agent exist: an {@see AgentScope} case, defaulting to
     * {@see AgentScope::CLUSTER}. {@see AgentScope::NODE} is mutually exclusive with
     * {@see self::INDEXED}: a sharded pool needs an index, an every-node replica has none.
     */
    public const string SCOPE = 'scope';

    /**
     * Who picks the node a cluster-wide agent runs on: an {@see AgentPlacement} case, defaulting
     * to {@see AgentPlacement::LEADER}. Meaningless — and refused by topology validation — next
     * to {@see AgentScope::NODE}, where no node is picked.
     */
    public const string PLACEMENT = 'placement';
}
