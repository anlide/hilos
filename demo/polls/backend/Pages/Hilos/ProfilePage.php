<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Polls demo binding of the framework's profile root (HIL-1169).
 *
 * Binds the agent serving the framework's account and shared profile payload.
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
}
