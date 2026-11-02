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
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Object\Item\User;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * The sessions of one person and the tabs open in each: the list the person's card reads (HIL-1662).
 *
 * The project registers this framework list in BROWSER_LISTS and binds BINDING in PAGE_LISTS of the
 * card's Overview and of its Sessions tab ({@see AbstractHilosUserSessionsPage}, which declares it in
 * REQUIRED_LISTS). The person is the page's own `userId`, never the subscriber: an administrator reads
 * somebody else's sessions here.
 *
 * One item per session row signed into the person: a row signed out since, or signed into by somebody
 * else, leaves the list, and a session with no tab open stays in it, because the sign-in lives on.
 * Each item carries the tabs open in that session from the project's runtime connections, mounted under
 * {@see self::CONNECTIONS}, a key the framework holds - another key is refused by the source check of the
 * start ({@see TopologyValidator::validateReferences()}). A tab is matched by its session and by its
 * person both, and a tab moving to another session or person moves between items as it changes.
 *
 * A session in which an administrator acts as the person carries that administrator's id and name, joined
 * by the session's own impersonatorUserId ({@see BrowserSourceConfig::anchorFieldJoin()}): renaming the
 * administrator reaches the items pointing at them without a reload, and a session nobody acts in carries
 * no such fragment.
 *
 * Nothing secret travels: neither the session token nor the address the session keeps is a field here,
 * and the tabs carry no token either. Moments are the database's UTC strings sent as epoch
 * milliseconds. A viewer of the admin view mode is shown the session fields by their columns' verdicts
 * and none of the tab fields: nothing here opens them. Nor is a viewer sent the administrator acting in
 * a session: the row joined by the impersonator field follows that field's verdict, and with the field
 * hidden it goes to a viewer at no session, with an administrator or without.
 */
final class HilosUserSessionsBrowserList
{
    public const string LIST = 'userSessions';

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
                    Session::userId => self::TABLE_USER_ID,
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
                BrowserListFieldKey::SQL_MOMENTS => [Session::createdAt, Session::lastSeenAt, Session::expiresAt],
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
                BrowserListFieldKey::VIA => [User::id => Session::impersonatorUserId],
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
