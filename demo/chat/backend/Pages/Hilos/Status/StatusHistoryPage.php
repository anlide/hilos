<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Status;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;

/** Completed Status history served by the project's index agent. */
final class StatusHistoryPage extends AbstractHilosStatusHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
