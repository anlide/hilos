<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosSilUserHistoryPage - Abstract base for Hilos SIL user history.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage).
 */
abstract class AbstractHilosSilUserHistoryPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL_USER_HISTORY;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (key {@see HilosPageRouteParams::HILOS_SIL_USER_HISTORY_USER_ID})
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL_USER_HISTORY,
            $acceptKey,
            new SignalData(),
        );
    }
}
