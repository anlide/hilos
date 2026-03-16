<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosI18nPage - Abstract base for Hilos i18n page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18nPage).
 */
abstract class AbstractHilosI18nPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N,
            $acceptKey,
            new SignalData(),
        );
    }
}
