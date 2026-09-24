<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileSecurityPage;

/**
 * Chat demo binding of the framework's profile security page - the second factor (HIL-494).
 *
 * The framework owns the page and reads the section; this binds the agent that serves it.
 */
final class ProfileSecurityPage extends AbstractHilosProfileSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}
