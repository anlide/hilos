<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosAnalyticsPage - Abstract base for Hilos analytics page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\AnalyticsPage).
 */
abstract class AbstractHilosAnalyticsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS,
            $acceptKey,
            new SignalData(),
        );
    }
}
