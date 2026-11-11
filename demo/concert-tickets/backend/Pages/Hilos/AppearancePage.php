<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Appearance\AbstractHilosAppearancePage;

/** Binds the framework Appearance page to the concert-tickets index agent. */
final class AppearancePage extends AbstractHilosAppearancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
