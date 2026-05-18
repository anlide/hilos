<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Communications;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for chat demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
