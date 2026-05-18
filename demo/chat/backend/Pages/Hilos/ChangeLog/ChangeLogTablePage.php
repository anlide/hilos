<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\ChangeLog;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;

/**
 * ChangeLogTablePage - Hilos change log single-table detail for chat demo.
 */
final class ChangeLogTablePage extends AbstractHilosChangeLogTablePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
