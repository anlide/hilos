<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;

/** Binds the personal legal section to the concert-tickets subscription owner. */
final class ProfileAgreementsPage extends AbstractHilosProfileAgreementsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
}
