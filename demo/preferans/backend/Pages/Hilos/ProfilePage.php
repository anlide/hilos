<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages\Hilos;

use Demo\Preferans\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/** Preferans binding of the framework profile root. */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PREFERANS;
}
