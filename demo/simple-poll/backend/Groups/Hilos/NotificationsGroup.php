<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Groups\Hilos;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Groups\AbstractHilosNotificationsGroup;

/**
 * Activates the framework notification group for the demo.
 *
 * The address, the admission and the join snapshot are framework-owned
 * ({@see AbstractHilosNotificationsGroup}); the demo binds only the owner, and it is the
 * agent that owns the notifications page - one owner for the whole feature.
 */
final class NotificationsGroup extends AbstractHilosNotificationsGroup
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
