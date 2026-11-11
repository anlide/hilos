<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the roblox-game-server demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
