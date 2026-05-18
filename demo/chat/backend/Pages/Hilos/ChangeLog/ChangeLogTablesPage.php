<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\ChangeLog;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;

/**
 * ChangeLogTablesPage - Hilos change log tracked tables list for chat demo.
 */
final class ChangeLogTablesPage extends AbstractHilosChangeLogTablesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
