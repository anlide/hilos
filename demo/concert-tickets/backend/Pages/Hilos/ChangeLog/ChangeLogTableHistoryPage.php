<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\ChangeLog;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableHistoryPage;

/**
 * ChangeLogTableHistoryPage - Hilos change log table history for the concert-tickets demo.
 */
final class ChangeLogTableHistoryPage extends AbstractHilosChangeLogTableHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
