<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/** Concert Tickets binding of the framework profile root. */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
}
