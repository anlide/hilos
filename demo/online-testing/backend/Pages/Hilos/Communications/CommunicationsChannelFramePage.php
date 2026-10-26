<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Communications;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;

/** Email letter frame subscription served by the online-testing index agent. */
final class CommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
