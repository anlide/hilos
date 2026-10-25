<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Communications;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;

/** Channel configuration subscription served by the tasks demo's index agent. */
final class CommunicationsChannelConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
