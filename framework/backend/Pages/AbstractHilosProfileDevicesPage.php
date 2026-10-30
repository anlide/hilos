<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;

/** Framework identity and access contract for the current user's push devices page. */
abstract class AbstractHilosProfileDevicesPage extends AbstractHilosProfileSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_DEVICES;
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_DEVICES,
    ];
}
