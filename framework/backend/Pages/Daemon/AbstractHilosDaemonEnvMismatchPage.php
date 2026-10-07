<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;

/** Cluster environment comparison page; its content belongs to a later leaf. */
abstract class AbstractHilosDaemonEnvMismatchPage extends AbstractHilosDaemonSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_ENV_MISMATCH;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_ENV_MISMATCH,
    ];

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @throws PageResourceNotFoundException When cluster mode is disabled
     * @throws EnvException When the cluster mode flag cannot be read
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        if (Hilos::$cluster?->isEnabled() !== true) {
            throw new PageResourceNotFoundException('Environment mismatch is available only in a cluster');
        }
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When the page answer fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }
}
