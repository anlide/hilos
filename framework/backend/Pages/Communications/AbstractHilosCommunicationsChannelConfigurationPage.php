<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;

/** Channel configuration subscription; mutation ownership stays on the channel page. */
abstract class AbstractHilosCommunicationsChannelConfigurationPage extends AbstractHilosCommunicationsChannelContextPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
    ];
}
