<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Communications;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for chat demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
