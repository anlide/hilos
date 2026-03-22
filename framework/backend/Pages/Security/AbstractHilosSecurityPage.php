<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosSecurityPage - Security center overview.
 */
abstract class AbstractHilosSecurityPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY,
            $acceptKey,
            new SignalData(),
        );
    }
}
