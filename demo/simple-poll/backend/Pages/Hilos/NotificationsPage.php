<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * Activates the framework notification-center page for the simple-poll demo.
 *
 * The sync/mark-read/mark-all-read actions and their authenticated-session gate
 * are framework-owned ({@see AbstractHilosNotificationsPage}); the demo binds only
 * the action owner (the Hilos index agent).
 */
final class NotificationsPage extends AbstractHilosNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
