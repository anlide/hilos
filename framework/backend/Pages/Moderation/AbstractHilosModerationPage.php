<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * Abstract base for Hilos moderation root page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Moderation\ModerationPage).
 */
abstract class AbstractHilosModerationPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MODERATION;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION,
    ];
}
