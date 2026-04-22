<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Translate;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosI18nTranslateGroupItemPage - Abstract base for Hilos i18n translate group item page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupItemPage).
 */
abstract class AbstractHilosI18nTranslateGroupItemPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM,
            $acceptKey,
            new SignalData(),
        );
    }
}
