<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Moderation;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Moderation\AbstractHilosModerationKindRulesPage;

/** Moderation kind rules served by the project's index agent. */
final class ModerationKindRulesPage extends AbstractHilosModerationKindRulesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
