<?php

declare(strict_types=1);

namespace Hilos\Pages\Operations;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosOperationsPage - Abstract base for Hilos maintenance operations page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Operations\OperationsPage).
 */
abstract class AbstractHilosOperationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_OPERATIONS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_OPERATIONS,
    ];

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_OPERATIONS,
            $acceptKey,
            new SignalData(),
        );
    }
}
