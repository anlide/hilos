<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\ChangeLog;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;

/**
 * ChangeLogDashboardPage - Hilos change log dashboard for chat demo.
 */
final class ChangeLogDashboardPage extends AbstractHilosChangeLogDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
