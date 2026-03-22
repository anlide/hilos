<?php

declare(strict_types=1);

namespace Hilos\Pages\Roles;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosRolesPage - Abstract base for Hilos roles list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Roles\RolesPage).
 */
abstract class AbstractHilosRolesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ROLES;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ROLES,
            $acceptKey,
            new SignalData(),
        );
    }
}
