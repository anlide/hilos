<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Communications;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;

/** Channel configuration subscription served by the chat demo's index agent. */
final class CommunicationsChannelConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
