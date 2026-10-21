<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Communications;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the polls demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
