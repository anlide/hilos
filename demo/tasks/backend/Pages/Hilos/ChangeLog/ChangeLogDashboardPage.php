<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\ChangeLog;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;

/**
 * ChangeLogDashboardPage - Hilos change log dashboard for the tasks demo.
 */
final class ChangeLogDashboardPage extends AbstractHilosChangeLogDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
