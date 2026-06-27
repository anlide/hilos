<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Browser\Table;

use Demo\SimplePoll\Browser\PollBrowserRef;
use Demo\SimplePoll\Browser\PollBrowserSource;
use Demo\SimplePoll\Browser\PollBrowserTable;
use Demo\SimplePoll\Database\Object\Item\User;
use Demo\SimplePoll\Runtime\State\Item\Connection;
use Demo\SimplePoll\Tables\HilosUser\HilosUserTableRow;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
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
    public const string TABLE = PollBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            PollBrowserSource::DB_USERS,
            PollBrowserSource::RT_CONNECTIONS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => PollBrowserSource::DB_USERS,
                BrowserTableFieldKey::ROW_KEY => User::id,
                BrowserTableFieldKey::WHERE => [
                    User::id => PollBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => PollBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => Connection::userId,
                BrowserTableFieldKey::WHERE => [
                    Connection::userId => PollBrowserRef::TABLE_HILOS_USER_ID,
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
