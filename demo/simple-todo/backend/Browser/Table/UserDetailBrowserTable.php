<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Browser\Table;

use Demo\SimpleTodo\Browser\TodoBrowserRef;
use Demo\SimpleTodo\Browser\TodoBrowserSource;
use Demo\SimpleTodo\Browser\TodoBrowserTable;
use Demo\SimpleTodo\Database\Object\Item\User;
use Demo\SimpleTodo\Runtime\State\Item\Connection;
use Demo\SimpleTodo\Tables\HilosUser\HilosUserTableRow;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser table config for a single Hilos user detail page.
 *
 * Filters the DB users and RT connections to the subscribed user id and projects
 * the same row shape as the Hilos users table.
 */
final class UserDetailBrowserTable
{
    public const string TABLE = TodoBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            TodoBrowserSource::DB_USERS,
            TodoBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => TodoBrowserSource::DB_USERS,
                BrowserFieldKey::ROW_KEY => User::id,
                BrowserFieldKey::WHERE => [
                    User::id => TodoBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => TodoBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => Connection::userId,
                BrowserFieldKey::WHERE => [
                    Connection::userId => TodoBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
        ],
    ];
}
