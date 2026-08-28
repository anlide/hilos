<?php

declare(strict_types=1);

namespace Hilos\Notification\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractNotificationsLibraryAgentDaemon - daemon proxy for the notifications library (HIL-771).
 *
 * Places {@see AbstractNotificationsLibraryAgent} as a monopolistic singleton, which is the
 * point of the move: the notification tables have one writer or they have none, and two
 * processes claiming them would be the state the library was built to end. Where that process
 * runs is the placement policy's to say ({@see AgentPlacement::POLICY} in a project's registry
 * entry) rather than the leader's, for the reason the sessions library is placed too: an emit
 * arrives from every worker in the cluster and the leader has enough to do.
 *
 * A project subclasses it alongside a concrete {@see AbstractNotificationsLibraryAgent} and
 * registers both under {@see HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY}.
 */
abstract class AbstractNotificationsLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY;

    /**
     * The library is the single owner of the notification set.
     *
     * @return bool True because the notification tables may be written in one process only
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
