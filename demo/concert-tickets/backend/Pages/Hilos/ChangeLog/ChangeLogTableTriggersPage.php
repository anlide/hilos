<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\ChangeLog;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;

/**
 * ChangeLogTableTriggersPage - Hilos change log table triggers for the concert-tickets demo.
 */
final class ChangeLogTableTriggersPage extends AbstractHilosChangeLogTableTriggersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
