<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\ChangeLog;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;

/**
 * ChangeLogNoisyPage - Hilos change log noisy for the concert-tickets demo.
 */
final class ChangeLogNoisyPage extends AbstractHilosChangeLogNoisyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
