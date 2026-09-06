<?php

declare(strict_types=1);

namespace Demo\Tasks\Groups\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Groups\AbstractHilosNotificationsGroup;

/**
 * Activates the framework notification group for the demo.
 *
 * The address, the admission and the join snapshot are framework-owned
 * ({@see AbstractHilosNotificationsGroup}); the demo binds only the owner, and since HIL-860
 * it is the agent that owns the notification rows - the join is answered where the snapshot
 * is read from, rather than in the admin index agent that serves the dashboard.
 */
final class NotificationsGroup extends AbstractHilosNotificationsGroup
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_NOTIFICATIONS_LIBRARY;
}
