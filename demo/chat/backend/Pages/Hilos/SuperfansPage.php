<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Pages\AbstractHilosSuperfansPage;

/**
 * SuperfansPage - Superfans page implementation for demo.
 */
final class SuperfansPage extends AbstractHilosSuperfansPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_SUPERFANS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SUPERFANS,
    ];
}
