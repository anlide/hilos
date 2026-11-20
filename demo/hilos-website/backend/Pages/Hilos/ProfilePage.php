<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/** HilosWebsite binding of the framework profile root. */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WEBSITE;
}
