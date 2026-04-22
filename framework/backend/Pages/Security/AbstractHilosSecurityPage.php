<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosSecurityPage - Security center overview.
 */
abstract class AbstractHilosSecurityPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY,
            $acceptKey,
            new SignalData(),
        );
    }
}
