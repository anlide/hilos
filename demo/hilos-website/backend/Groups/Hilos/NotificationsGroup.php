<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Groups\Hilos;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Groups\AbstractHilosNotificationsGroup;

/**
 * Activates the framework notification group for the site.
 *
 * The address, the admission and the join snapshot are framework-owned
 * ({@see AbstractHilosNotificationsGroup}); the site binds only the owner - the agent that owns the
 * notification rows, so the join is answered where the snapshot is read from.
 */
final class NotificationsGroup extends AbstractHilosNotificationsGroup
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_NOTIFICATIONS_LIBRARY;
}
