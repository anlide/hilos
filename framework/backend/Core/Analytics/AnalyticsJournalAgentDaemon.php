<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the journal agent of a node (HIL-1154).
 *
 * Registered per node, so it runs on every node - the journal is the node's own. A plain stub
 * otherwise: it neither forwards messages to a user nor carries an index.
 */
final class AnalyticsJournalAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS_JOURNAL;

    /**
     * The journal agent writes files, which is blocking work, and a node has exactly one owner of
     * its journal - so it gets a worker to itself.
     *
     * @return bool True because the journal agent is monopolistic
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }

    /**
     * Every other worker of the node hands the journal its last batch on the way out, so the
     * journal's worker leaves last (HIL-1154): the node stops it in a second wave, once the others
     * are gone and their frames were dispatched.
     *
     * @return bool True: the journal's worker stops after the others
     */
    public function stopsAfterOtherWorkers(): bool
    {
        return true;
    }
}
