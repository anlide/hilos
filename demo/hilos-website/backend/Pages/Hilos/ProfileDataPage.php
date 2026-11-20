<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the hilos-website agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WEBSITE;
}
