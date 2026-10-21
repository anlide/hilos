<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Online-testing binding of the framework current-user notification channels page.
 *
 * @property OnlineTestingAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ONLINE_TESTING;
}
