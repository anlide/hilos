<?php

declare(strict_types=1);

namespace Demo\Polls\Browser\List;

use Demo\Polls\Browser\PollsBrowserList;
use Demo\Polls\Browser\PollsBrowserRef;
use Demo\Polls\Browser\PollsBrowserSource;
use Demo\Polls\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\Session;

/** Browser list of the signed-in user's durable sessions and their live tabs. */
final class ProfileSessionsBrowserList
{
    public const string LIST = PollsBrowserList::PROFILE_SESSIONS;
    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            PollsBrowserSource::RT_CONNECTIONS,
            PollsBrowserSource::DB_SESSIONS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => PollsBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::WHERE => [Connection::acceptKey => PollsBrowserRef::TABLE_ACCEPT_KEY],
                BrowserListFieldKey::FIELDS => [Connection::userId],
                BrowserListFieldKey::TRIGGERS => [Connection::userId],
            ],
            [
                BrowserListFieldKey::SOURCE => PollsBrowserSource::DB_SESSIONS,
                BrowserListFieldKey::ITEM_KEY => Session::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [Session::userId => Connection::userId],
                BrowserListFieldKey::FIELDS => [
                    Session::id,
                    Session::userId,
                    Session::deviceName,
                    Session::createdAt,
                    Session::lastSeenAt,
                    Session::expiresAt,
                    Session::impersonatorUserId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => PollsBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [Connection::userId => Connection::userId],
                BrowserListFieldKey::FIELDS => [Connection::acceptKey, Connection::sessionId, Connection::connectedAt],
                BrowserListFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::sessionId,
                    Connection::connectedAt,
                ],
            ],
        ],
    ];
}
