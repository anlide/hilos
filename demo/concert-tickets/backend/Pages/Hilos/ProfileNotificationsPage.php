<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Agents\ConcertTicketsAgent;
use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Concert Tickets binding of the framework current-user notification channels page.
 *
 * @property ConcertTicketsAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
}
