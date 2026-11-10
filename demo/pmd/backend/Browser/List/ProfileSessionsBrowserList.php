<?php

declare(strict_types=1);

namespace Demo\Pmd\Browser\List;

use Demo\Pmd\Browser\PmdBrowserRef;
use Demo\Pmd\Browser\PmdBrowserSource;
use Demo\Pmd\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\Session;

/** Browser list of the signed-in user's durable sessions and their live tabs. */
final class ProfileSessionsBrowserList
{
    public const string LIST = 'profileSessions';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => PmdBrowserRef::ACCEPT_KEY,
        ],
    ];

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            PmdBrowserSource::RT_CONNECTIONS,
            PmdBrowserSource::DB_SESSIONS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => PmdBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::WHERE => [Connection::acceptKey => PmdBrowserRef::TABLE_ACCEPT_KEY],
                BrowserListFieldKey::FIELDS => [Connection::userId],
                BrowserListFieldKey::TRIGGERS => [Connection::userId],
            ],
            [
                BrowserListFieldKey::SOURCE => PmdBrowserSource::DB_SESSIONS,
                BrowserListFieldKey::ITEM_KEY => Session::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [Session::userId => Connection::userId],
                BrowserListFieldKey::FIELDS => [
                    Session::id,
                    Session::userId,
                    Session::deviceName,
                    Session::browserTimeZone,
                    Session::createdAt,
                    Session::lastSeenAt,
                    Session::expiresAt,
                    Session::impersonatorUserId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => PmdBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [Connection::userId => Connection::userId],
                BrowserListFieldKey::FIELDS => [Connection::acceptKey, Connection::sessionId, Connection::connectedAt],
                BrowserListFieldKey::TRIGGERS => [Connection::userId, Connection::sessionId, Connection::connectedAt],
            ],
        ],
    ];
}
