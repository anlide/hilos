<?php

declare(strict_types=1);

namespace Demo\Preferans\Tables\HilosUser;

use Demo\Preferans\Browser\PreferansBrowserSource;
use Demo\Preferans\Runtime\State\Item\Connection as ConnectionState;
use Demo\Preferans\Runtime\View\Context\PreferansRtContext;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;

/**
 * This demo's activation of the framework Hilos users table.
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
            PreferansBrowserSource::RT_CONNECTIONS,
        ],
        BrowserTableConfigKey::ROWS => [
            self::USERS_ROW,
            [
                BrowserTableFieldKey::SOURCE => PreferansBrowserSource::RT_CONNECTIONS,
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
     * Binds this demo's RT connections collection as this table's presence source.
     *
     * @return string Connections collection key
     */
    protected function presenceSourceKey(): string
    {
        return PreferansRtContext::connections;
    }
}
