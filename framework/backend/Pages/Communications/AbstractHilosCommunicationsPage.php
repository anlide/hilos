<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosCommunicationsPage - Hilos communications channels hub (HIL-200).
 *
 * The channels hub: one row per registered channel with the enablement toggle. It owns
 * no actions of its own — the toggle rides the shared set action owned by
 * {@see AbstractHilosCommunicationsChannelPage} (a WebSocket action name is globally
 * unique to one page) — and its subscription is closed by the ADMIN access level
 * inherited from AbstractHilosPage, which replaced the former flagless
 * AUTHENTICATED guard with the stricter inherited default.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Communications\CommunicationsPage).
 */
abstract class AbstractHilosCommunicationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS,
    ];
}
