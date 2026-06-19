<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser list source for users visible on the main chat page.
 */
final class MainUsersBrowserList
{
    public const string LIST = ChatBrowserTable::MAIN_USERS;

    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserFieldKey::ROW_KEY => User::id,
                BrowserFieldKey::FIELDS => [
                    User::id,
                    User::name,
                    User::lastActivity,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => Connection::userId,
                BrowserFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
                BrowserFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::connectedAt,
                ],
            ],
        ],
    ];
}
