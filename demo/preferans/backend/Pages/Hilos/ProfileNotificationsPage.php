<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages\Hilos;

use Demo\Preferans\Agents\PreferansAgent;
use Demo\Preferans\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Preferans binding of the framework current-user notification channels page.
 *
 * @property PreferansAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PREFERANS;
}
