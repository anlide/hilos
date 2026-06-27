<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser list source for users visible on the main chat page.
 */
final class MainUsersBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_USERS;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserListFieldKey::ITEM_KEY => User::id,
                BrowserListFieldKey::FIELDS => [
                    User::id,
                    User::name,
                    User::lastActivity,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserListFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
                BrowserListFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::connectedAt,
                ],
            ],
        ],
    ];
}
