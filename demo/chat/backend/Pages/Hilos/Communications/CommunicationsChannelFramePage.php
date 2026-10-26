<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Communications;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;

/** Email letter frame subscription served by the chat demo's index agent. */
final class CommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
