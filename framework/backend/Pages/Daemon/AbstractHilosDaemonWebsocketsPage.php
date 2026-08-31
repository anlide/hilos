<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosDaemonWebsocketsPage - Abstract base for Hilos daemon websocket connections page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage).
 */
abstract class AbstractHilosDaemonWebsocketsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_WEBSOCKETS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_WEBSOCKETS,
    ];
}
