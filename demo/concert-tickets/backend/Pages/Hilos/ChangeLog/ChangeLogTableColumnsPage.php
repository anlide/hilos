<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\ChangeLog;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableColumnsPage;

/**
 * ChangeLogTableColumnsPage - Hilos change log table columns for the concert-tickets demo.
 */
final class ChangeLogTableColumnsPage extends AbstractHilosChangeLogTableColumnsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
