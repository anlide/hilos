<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosUserPage - Abstract base for Hilos single user page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Users\UserPage).
 * Default subscribe sends an empty payload; apps with a shared `users` table typically override
 * {@see self::onSubscribe()} to include `tables.users` (full snapshot or filtered) for the client.
 */
abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USER;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USER,
            $acceptKey,
            new SignalData(),
        );
    }
}
