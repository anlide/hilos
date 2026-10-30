<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Moderation;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Moderation\AbstractHilosModerationKindPage;

/** Moderation kind overview served by the project's index agent. */
final class ModerationKindPage extends AbstractHilosModerationKindPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
