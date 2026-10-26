<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Communications;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;

/** Email letter frame subscription served by the tasks demo's index agent. */
final class CommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
