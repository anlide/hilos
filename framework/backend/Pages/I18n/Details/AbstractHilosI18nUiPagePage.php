<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosI18nUiPagePage - Abstract base for Hilos i18n UI page detail page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\UiPageDetailPage).
 */
abstract class AbstractHilosI18nUiPagePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_UI_PAGE;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGE,
            $acceptKey,
            new SignalData(),
        );
    }
}
