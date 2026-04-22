<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosI18nLanguagePage - Abstract base for Hilos i18n language page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage).
 */
abstract class AbstractHilosI18nLanguagePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE,
            $acceptKey,
            new SignalData(),
        );
    }
}
