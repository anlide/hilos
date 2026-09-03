<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the node-local log carrier agent (HIL-870).
 *
 * Registered per node beside {@see LogStoreAgentDaemon}, and for the same reason: the staging and
 * archive directories it moves batches between are this node's own, and no other node can reach
 * them. A plain stub otherwise: it neither forwards messages to a user nor carries an index.
 */
final class LogCarrierAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_CARRIER;

    /**
     * Copying a batch to another device takes as long as the batch is large, and this agent is
     * allowed to spend a whole tick on it — which is only safe while no other work shares the
     * worker.
     *
     * @return bool True because the log carrier agent is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
