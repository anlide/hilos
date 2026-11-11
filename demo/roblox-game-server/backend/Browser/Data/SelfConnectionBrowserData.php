<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Browser\Data;

use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserRef;
use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserSource;
use Demo\RobloxGameServer\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\User;

/** Current connection identity and the person's committed live name. */
final class SelfConnectionBrowserData
{
    public const string DATA = 'selfConnection';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => RobloxGameServerBrowserRef::ACCEPT_KEY,
        ],
    ];

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            RobloxGameServerBrowserSource::RT_CONNECTIONS,
            RobloxGameServerBrowserSource::DB_USERS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => RobloxGameServerBrowserSource::RT_CONNECTIONS,
                BrowserDataFieldKey::ROW_KEY => Connection::userId,
                BrowserDataFieldKey::WHERE => [Connection::acceptKey => RobloxGameServerBrowserRef::TABLE_ACCEPT_KEY],
                BrowserDataFieldKey::FIELDS => [
                    Connection::acceptKey,
                    Connection::userId,
                    Connection::connectedAt,
                    Connection::sessionId,
                ],
                BrowserDataFieldKey::TRIGGERS => [Connection::userId, Connection::connectedAt, Connection::sessionId],
            ],
            [
                BrowserDataFieldKey::SOURCE => RobloxGameServerBrowserSource::DB_USERS,
                BrowserDataFieldKey::ROW_KEY => User::id,
                BrowserDataFieldKey::VIA => [User::id => Connection::userId],
                BrowserDataFieldKey::FIELDS => [User::id, User::name],
                BrowserDataFieldKey::TRIGGERS => [User::name],
            ],
        ],
    ];
}
