<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileSecurityPage;

/**
 * Concert Tickets demo binding of the framework's profile security page - the second factor (HIL-494).
 *
 * The framework owns the page and reads the section; this binds the agent that serves it.
 */
final class ProfileSecurityPage extends AbstractHilosProfileSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CONCERT_TICKETS;
}
