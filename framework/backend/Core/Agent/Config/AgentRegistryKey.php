<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

use Hilos\Core\Agent\AgentRegistry;

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

    /**
     * How long an instance agent may sit unaddressed before it is stopped, in whole seconds;
     * {@see AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC} is the framework's own number to point at.
     *
     * Declaring the window is what declares the kind of life, and there is no second on-demand
     * flag beside it: an entry without this key keeps today's behaviour, where an agent nobody has
     * spoken to for hours lives on. Only an instance agent may carry it ({@see self::INDEXED}),
     * because a node replica or a set-wide library comes up from the bootstrap and nothing would
     * address it back into existence; topology validation refuses the other entries.
     */
    public const string IDLE_TIMEOUT = 'idle_timeout';
}
