<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosSettingsPage - Abstract base for Hilos settings page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\SettingsPage).
 */
abstract class AbstractHilosSettingsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SETTINGS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS,
            $acceptKey,
            new SignalData(),
        );
    }
}
