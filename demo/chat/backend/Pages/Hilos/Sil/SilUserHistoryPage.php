<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Sil;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Sil\AbstractHilosSilUserHistoryPage;

/**
 * SilUserHistoryPage - SIL user history for chat demo.
 */
final class SilUserHistoryPage extends AbstractHilosSilUserHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
