<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * ModerationAgentDaemon - daemon proxy for the cluster's one moderator (HIL-1625).
 *
 * Places {@see ModerationAgent} as a cluster-wide monopolistic singleton: one moderator serves
 * every check, one at a time, and the wait on a model is the long operation a worker of its own
 * exists for. Where that process runs is the placement policy's to say
 * ({@see AgentPlacement::POLICY} in the project's registry entry), and the scope stays unwritten
 * there because CLUSTER is its default.
 */
final class ModerationAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MODERATOR;

    /**
     * The moderator waits on a model and must not share that worker's ticks with anyone.
     *
     * @return bool True because the moderator runs in a worker of its own
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
