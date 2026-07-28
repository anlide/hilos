<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosCommunicationsPage - Hilos communications channels hub (HIL-200).
 *
 * The channels hub: one row per registered channel with the enablement toggle. It owns
 * no actions of its own — the toggle rides the shared set action owned by
 * {@see AbstractHilosCommunicationsChannelPage} (a WebSocket action name is globally
 * unique to one page) — but it is still a signed-in-only surface, so the framework
 * guards its subscription with a flagless AUTHENTICATED guard.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Communications\CommunicationsPage).
 */
abstract class AbstractHilosCommunicationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS,
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
            ],
        ],
    ];
}
