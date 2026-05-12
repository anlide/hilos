<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosGuardianPage - Abstract base for Hilos guardian page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\GuardianPage).
 *
 * @property AbstractHilosGuardianAgent $agent
 */
abstract class AbstractHilosGuardianPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_GUARDIAN;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
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
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
            $acceptKey,
            new SignalData([
                'guardianAgentStatuses' => $this->agent->getGuardianRunStatuses(),
            ]),
        );
    }
}
