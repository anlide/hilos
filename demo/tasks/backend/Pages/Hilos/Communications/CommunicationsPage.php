<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Communications;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for the tasks demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
