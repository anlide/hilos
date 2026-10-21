<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Communications;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the online-testing demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
