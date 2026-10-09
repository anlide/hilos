<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Demo\Polls\Database\PollsDbContext;
use Hilos\Pages\AbstractHilosProfileSessionsPage;

/**
 * Polls binding of the framework current-user sessions page.
 */
final class ProfileSessionsPage extends AbstractHilosProfileSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
    public const array READS_DB = [PollsDbContext::sessions];
}
