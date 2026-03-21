<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosI18nGroupsListPage - Abstract base for Hilos i18n groups list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\GroupsListPage).
 */
abstract class AbstractHilosI18nGroupsListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_GROUPS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_GROUPS,
            $acceptKey,
            new SignalData(),
        );
    }
}
