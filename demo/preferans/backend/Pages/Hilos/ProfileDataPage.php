<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages\Hilos;

use Demo\Preferans\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the preferans agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PREFERANS;
}
