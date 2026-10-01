<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the cluster's analytics writer (HIL-1154).
 *
 * A plain stub: it neither forwards messages to a user nor carries an index. Where the agent runs
 * is declared in the registry - one instance cluster-wide, on the node the placement policy picks.
 */
final class AnalyticsWriterAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS_WRITER;

    /**
     * The writer loads a whole file in one transaction inside the handler of its last portion,
     * which is blocking database work - so it gets a worker to itself.
     *
     * @return bool True because the writer is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
