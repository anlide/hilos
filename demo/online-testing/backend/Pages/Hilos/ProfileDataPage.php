<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the online-testing agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ONLINE_TESTING;
}
