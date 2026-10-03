<?php

declare(strict_types=1);

namespace Hilos\Pages\Profile;

use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Runtime\State\Item\HilosConnection;

/**
 * Browser list source for the current user's linked login identities.
 *
 * The project registers this framework list in BROWSER_LISTS and binds BINDING
 * in PAGE_LISTS. Framework pages that read it declare it in REQUIRED_LISTS.
 * The project's connection collection must be mounted under CONNECTIONS; the
 * startup source check refuses another key.
 *
 * Scoped to the subscribing user: the self-connection (matched by accept key) is
 * the anchor, and the framework-owned identities are joined in by owner user id.
 * Read-only — the `secret` hash is not a field of the identity object and cannot
 * enter this projection. A passkey identity also has a credential sidecar with
 * its readable label and enrollment time, never its key material.
 */
final class HilosProfileIdentitiesBrowserList
{
    public const string LIST = 'profileIdentities';
    public const string CONNECTIONS = 'connections';

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => self::CONNECTIONS,
    ];

    public const array DB_IDENTITIES = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::identities,
    ];

    public const array DB_PASSKEY_CREDENTIALS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::passkeyCredentials,
    ];

    public const array ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY,
    ];

    public const array TABLE_ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
    ];

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => self::ACCEPT_KEY,
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
            self::RT_CONNECTIONS,
            self::DB_IDENTITIES,
            self::DB_PASSKEY_CREDENTIALS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => self::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => HilosConnection::userId,
                BrowserListFieldKey::WHERE => [
                    HilosConnection::acceptKey => self::TABLE_ACCEPT_KEY,
                ],
                BrowserListFieldKey::FIELDS => [HilosConnection::userId],
            ],
            [
                BrowserListFieldKey::SOURCE => self::DB_IDENTITIES,
                BrowserListFieldKey::ITEM_KEY => Identity::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [Identity::userId => HilosConnection::userId],
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
                BrowserListFieldKey::SOURCE => self::DB_PASSKEY_CREDENTIALS,
                BrowserListFieldKey::ITEM_KEY => PasskeyCredential::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [PasskeyCredential::userId => HilosConnection::userId],
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
