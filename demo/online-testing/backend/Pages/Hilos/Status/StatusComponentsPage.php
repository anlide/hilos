<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Status;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;

/** Status component window served by the project's index agent. */
final class StatusComponentsPage extends AbstractHilosStatusComponentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
