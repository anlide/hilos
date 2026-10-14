<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\ChangeLog;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;

/**
 * ChangeLogTablePage - Hilos change log single-table detail for the online-testing demo.
 */
final class ChangeLogTablePage extends AbstractHilosChangeLogTablePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
