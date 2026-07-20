<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\Identity;

/**
 * Browser list source for the current user's linked login identities.
 *
 * Scoped to the subscribing user: the self-connection (matched by accept key) is
 * the anchor, and the framework-owned identities are joined in by owner user id,
 * so a client only ever sees its own identities. Read-only — the `secret` hash is
 * not a field of the identity object, so it can never enter this projection.
 */
final class ProfileIdentitiesBrowserList
{
    public const string LIST = ChatBrowserList::PROFILE_IDENTITIES;

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::DB_IDENTITIES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::WHERE => [
                    Connection::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserListFieldKey::FIELDS => [
                    Connection::userId,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_IDENTITIES,
                BrowserListFieldKey::ITEM_KEY => Identity::id,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [
                    Identity::userId => Connection::userId,
                ],
                BrowserListFieldKey::FIELDS => [
                    Identity::id,
                    Identity::userId,
                    Identity::type,
                    Identity::identifier,
                    Identity::provider,
                    Identity::verified,
                ],
            ],
        ],
    ];
}
