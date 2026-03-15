<?php

declare(strict_types=1);

namespace Hilos\Core\Page\HilosPages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * HilosSettingsPage - Hilos settings list page.
 */
class HilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
            $acceptKey,
            new SignalData(),
        );
    }
}
