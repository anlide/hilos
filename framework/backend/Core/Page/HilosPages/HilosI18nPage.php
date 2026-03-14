<?php

declare(strict_types=1);

namespace Hilos\Core\Page\HilosPages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalData;

/**
 * HilosI18nPage - Hilos internationalization page (languages, countries, locales, translations).
 */
class HilosI18nPage extends AbstractHilosPage
{
    /**
     * {@inheritDoc}
     */
    public function getPageName(): string
    {
        return HilosPageConstants::HILOS_I18N;
    }

    /**
     * {@inheritDoc}
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N,
            $acceptKey,
            new SignalData(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function onUnsubscribe(string $acceptKey): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
    }
}
