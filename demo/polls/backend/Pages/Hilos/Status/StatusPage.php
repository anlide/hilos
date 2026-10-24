<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Status;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Status\AbstractHilosStatusPage;

/** Status overview served by the project's index agent. */
final class StatusPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
