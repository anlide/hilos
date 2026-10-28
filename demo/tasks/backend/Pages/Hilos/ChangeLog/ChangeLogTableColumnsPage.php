<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\ChangeLog;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableColumnsPage;

/**
 * ChangeLogTableColumnsPage - Hilos change log table columns for the tasks demo.
 */
final class ChangeLogTableColumnsPage extends AbstractHilosChangeLogTableColumnsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
