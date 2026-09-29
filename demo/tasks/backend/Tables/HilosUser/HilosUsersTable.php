<?php

declare(strict_types=1);

namespace Demo\Tasks\Tables\HilosUser;

use Demo\Tasks\Browser\TasksBrowserSource;
use Demo\Tasks\Runtime\State\Item\Connection as ConnectionState;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;

/**
 * Tasks activation of the framework Hilos users table.
 *
 * The project names its RT connections collection - the key is its own - and declares the
 * browser sources over it; reading the people, building their rows, and merging presence are
 * the framework's.
 */
final class HilosUsersTable extends AbstractHilosUsersTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::USERS_SOURCE,
            TasksBrowserSource::RT_CONNECTIONS,
        ],
        BrowserTableConfigKey::ROWS => [
            self::USERS_ROW,
            [
                BrowserTableFieldKey::SOURCE => TasksBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => ConnectionState::userId,
                BrowserTableFieldKey::FIELDS => [
                    ConnectionState::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserTableRow::presence,
                    HilosUserTableRow::onlineSessionCount,
                ],
            ],
        ],
    ];

    /**
     * Binds the tasks RT connections collection as this table's presence source.
     */
    protected function presenceSourceKey(): string
    {
        return TasksRtContext::connections;
    }
}
