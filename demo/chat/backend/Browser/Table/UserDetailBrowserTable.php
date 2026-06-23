<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\Connection;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser table config for a single Hilos user detail page.
 */
final class UserDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserTableFieldKey::ROW_KEY => User::id,
                BrowserTableFieldKey::WHERE => [
                    User::id => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => Connection::userId,
                BrowserTableFieldKey::WHERE => [
                    Connection::userId => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
        ],
    ];
}
