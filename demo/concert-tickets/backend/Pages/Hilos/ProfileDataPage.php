<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the concert-tickets agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
}
