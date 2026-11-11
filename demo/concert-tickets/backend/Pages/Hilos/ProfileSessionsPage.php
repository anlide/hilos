<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Demo\ConcertTickets\Database\ConcertTicketsDbContext;
use Hilos\Pages\AbstractHilosProfileSessionsPage;

/**
 * Concert Tickets binding of the framework current-user sessions page.
 */
final class ProfileSessionsPage extends AbstractHilosProfileSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
    public const array READS_DB = [...parent::READS_DB, ConcertTicketsDbContext::sessions];
}
