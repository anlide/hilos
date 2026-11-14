<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Moderation;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Moderation\AbstractHilosModerationKindRulesPage;

/** Moderation kind rules page served by the project's index agent. */
final class ModerationKindRulesPage extends AbstractHilosModerationKindRulesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
