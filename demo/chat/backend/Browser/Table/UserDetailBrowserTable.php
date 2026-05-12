<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\Connection;
use Demo\Chat\Runtime\View\DTO\UserConnectionSummary;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser table config for a single Hilos user detail page.
 */
final class UserDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserFieldKey::WHERE => [
                    User::id => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::WHERE => [
                    Connection::userId => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    Connection::acceptKey,
                    Connection::userId,
                ],
                BrowserFieldKey::COMPUTED => [
                    UserConnectionSummary::presence,
                    UserConnectionSummary::onlineSessionCount,
                ],
            ],
        ],
    ];
}
