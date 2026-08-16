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
use Hilos\Database\Object\Item\PasskeyCredential;

/**
 * Browser list source for the current user's linked login identities.
 *
 * Scoped to the subscribing user: the self-connection (matched by accept key) is
 * the anchor, and the framework-owned identities are joined in by owner user id,
 * so a client only ever sees its own identities. Read-only — the `secret` hash is
 * not a field of the identity object, so it can never enter this projection.
 *
 * A passkey identity is two rows: the anchor here and the credential sidecar that
 * carries what a person can read — the device it was enrolled on and when. The
 * second items block joins that sidecar in by the same owner id (HIL-418) so the
 * profile can name a key rather than print its credential id; the view keeps the
 * key material out, exactly as it does for the identity's secret.
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
            ChatBrowserSource::DB_PASSKEY_CREDENTIALS,
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
                BrowserListFieldKey::ITEM_KEY => Identity::userId,
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
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_PASSKEY_CREDENTIALS,
                BrowserListFieldKey::ITEM_KEY => PasskeyCredential::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [
                    PasskeyCredential::userId => Connection::userId,
                ],
                BrowserListFieldKey::FIELDS => [
                    PasskeyCredential::id,
                    PasskeyCredential::userId,
                    PasskeyCredential::identityId,
                    PasskeyCredential::label,
                    PasskeyCredential::createdAt,
                ],
            ],
        ],
    ];
}
