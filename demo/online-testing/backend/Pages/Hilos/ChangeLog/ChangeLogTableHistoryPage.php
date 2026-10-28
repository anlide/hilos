<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\ChangeLog;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableHistoryPage;

/**
 * ChangeLogTableHistoryPage - Hilos change log table history for the online-testing demo.
 */
final class ChangeLogTableHistoryPage extends AbstractHilosChangeLogTableHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
