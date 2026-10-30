<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Moderation;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Moderation\AbstractHilosModerationPage;

/** Moderation root page served by the project's index agent. */
final class ModerationPage extends AbstractHilosModerationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
