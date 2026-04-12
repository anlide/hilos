<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosUsersPage - Abstract base for Hilos users list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Users\UsersPage).
 * Default subscribe sends an empty payload; apps that use a `users` entity table should override
 * {@see self::onSubscribe()} and send a subscription signal whose body includes `tables.users`
 * so the frontend table store can hydrate (see demo chat AdminUsersPage / Hilos UsersPage).
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USERS,
            $acceptKey,
            new SignalData(),
        );
    }
}
