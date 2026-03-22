<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosSilDashboardPage - Abstract base for Hilos System Intelligence Layer dashboard.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilDashboardPage).
 */
abstract class AbstractHilosSilDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL,
            $acceptKey,
            new SignalData(),
        );
    }
}
