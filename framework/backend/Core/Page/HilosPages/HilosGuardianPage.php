<?php

declare(strict_types=1);

namespace Hilos\Core\Page\HilosPages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalData;

/**
 * HilosGuardianPage - Hilos guardian robots page (project validation robots).
 */
class HilosGuardianPage extends AbstractHilosPage
{
    /**
     * {@inheritDoc}
     */
    public function getPageName(): string
    {
        return HilosPageConstants::HILOS_GUARDIAN;
    }

    /**
     * {@inheritDoc}
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
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
