<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Sil;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Sil\AbstractHilosSilRequestsPage;

/**
 * SilRequestsPage - SIL requests list for chat demo.
 */
final class SilRequestsPage extends AbstractHilosSilRequestsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
