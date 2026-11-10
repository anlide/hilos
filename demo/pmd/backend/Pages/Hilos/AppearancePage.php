<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;

/** Binds the framework Appearance page to the tracker index agent. */
final class AppearancePage extends AbstractHilosAppearancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
