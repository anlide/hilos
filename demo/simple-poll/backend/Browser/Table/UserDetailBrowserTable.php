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
    public const string TABLE = PollBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            PollBrowserSource::DB_USERS,
            PollBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => PollBrowserSource::DB_USERS,
                BrowserFieldKey::ROW_KEY => User::id,
                BrowserFieldKey::WHERE => [
                    User::id => PollBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => PollBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => Connection::userId,
                BrowserFieldKey::WHERE => [
                    Connection::userId => PollBrowserRef::TABLE_HILOS_USER_ID,
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
