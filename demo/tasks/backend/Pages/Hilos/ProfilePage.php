<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Tasks demo binding of the framework's profile root (HIL-1169).
 *
 * The framework owns the page and reads the person's sections; this binds the agent that serves it.
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::TASKS;
}
