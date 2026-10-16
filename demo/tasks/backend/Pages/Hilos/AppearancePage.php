<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;

/** Binds the framework Appearance page to the tasks index agent. */
final class AppearancePage extends AbstractHilosAppearancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
