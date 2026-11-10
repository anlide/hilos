<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Object\Item\User;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * The sessions in which the person of the card acts as somebody else, beside their ordinary sessions (HIL-1876).
 *
 * The project registers the list in BROWSER_LISTS and binds BINDING in PAGE_LISTS of the card's
 * Overview and Sessions tab. Only AbstractHilosUserSessionsPage requires it at startup. The page's
 * userId is the impersonator, never the subscriber; each session's userId is the person acted as.
 *
 * Each item carries that person's id and name through {@see BrowserSourceConfig::anchorFieldJoin()}, so a
 * rename reaches the open card. Its tabs match both the session and the person acted as, using the
 * project's runtime connections mounted under CONNECTIONS. Stopping a takeover removes the item;
 * starting one adds it through the same source-change path as HilosUserSessionsBrowserList.
 *
 * Neither the session token nor its address is projected, and moments are epoch milliseconds.
 * A viewer receives fields by their columns' verdicts, with no tab field opened here. The joined
 * person follows the anchor's userId verdict and is absent when that field is hidden; the client
 * hides the whole list from a viewer of the admin view mode.
 */
final class HilosUserActingSessionsBrowserList
{
    public const string LIST = 'userActingSessions';

    /** Key of the runtime connections collection the tabs are read from. */
    public const string CONNECTIONS = 'connections';

    /** The PAGE_LISTS binding of the list: the card's own person is the list's. */
    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
            ],
        ],
    ];

    public const array DB_SESSIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::sessions,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => self::CONNECTIONS,
    ];

    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::users,
    ];

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            self::DB_SESSIONS,
            self::RT_CONNECTIONS,
            self::DB_USERS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => self::DB_SESSIONS,
                BrowserListFieldKey::ITEM_KEY => Session::id,
                BrowserListFieldKey::WHERE => [
                    Session::impersonatorUserId => self::TABLE_USER_ID,
                ],
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
                BrowserListFieldKey::SOURCE => self::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => HilosSessionConnection::sessionId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [
                    HilosSessionConnection::sessionId => Session::id,
                    HilosConnection::userId => Session::userId,
                ],
                BrowserListFieldKey::FIELDS => [
                    HilosConnection::acceptKey,
                    HilosSessionConnection::sessionId,
                    HilosConnection::connectedAt,
                ],
                BrowserListFieldKey::TRIGGERS => [
                    HilosConnection::userId,
                    HilosSessionConnection::sessionId,
                    HilosConnection::connectedAt,
                ],
            ],
            [
                BrowserListFieldKey::SOURCE => self::DB_USERS,
                BrowserListFieldKey::VIA => [User::id => Session::userId],
                BrowserListFieldKey::FIELDS => [User::id, User::name],
                BrowserListFieldKey::TRIGGERS => [User::name],
            ],
        ],
    ];

    private const array TABLE_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];
}
