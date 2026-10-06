<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\Definition\HilosUsersFeature;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * The card of one person: the browser table the user page draws (HIL-1254).
 *
 * The card is the framework's, as the people list and the merge candidates beside it are: every
 * project keeps its people in the framework's table, its connections under the one key the
 * framework reads them by, and its identities and scheduled deletions in the framework's tables,
 * so nothing in the card is the project's to say. A project switches it on with one row of
 * BROWSER_TABLES (`TABLE => HilosUserDetailBrowserTable::class`) and binds it to its user page
 * with one row of PAGE_TABLES (`TABLE => BINDING`); a user page registered without it is refused
 * by the activation of {@see HilosUsersFeature}, which names this class as the table the
 * {@see AbstractHilosUserPage} must be bound to.
 *
 * The runtime connections are read under {@see self::CONNECTIONS}, a key the framework holds:
 * a project that mounts its connections under another key is refused by the source check of the
 * start ({@see TopologyValidator::validateReferences()}), which finds no runtime source of that
 * name. Presence, the session count, whether a password is set and the unconfirmed password
 * address are the framework's computed fields ({@see BrowserContext::computeBrowserField()}),
 * so a project writes neither the card nor the counting behind it.
 *
 * A viewer of the admin view mode is shown what is not personal. The person's fields go by their
 * columns' verdicts, so the name is hidden and the id, the flags and the last activity are not.
 * Each other row names what it opens in its NOT_PERSONAL: the connection's person, presence and the
 * session count, worked out of runtime connections; whether a password is set, worked out of the
 * identity's type, while the unconfirmed address stays hidden as the address it is (HIL-1276); and
 * the date a scheduled deletion falls due. That date is the one computed field out of a table under
 * PURGE a viewer is shown: `hilos_account_deletion` is erased on a restore so that the copy does not
 * go on to erase a masked person, not because the date names anybody, and without it the card would
 * tell a viewer no deletion is scheduled (the owner, 2026-10-01). The table's columns stay hidden.
 */
final class HilosUserDetailBrowserTable
{
    public const string TABLE = 'userDetail';

    /** Key of the runtime connections collection the card reads presence from. */
    public const string CONNECTIONS = 'connections';

    /** The PAGE_TABLES binding of the card: the user page's own id is the card's. */
    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
            ],
        ],
    ];

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            AbstractHilosUsersTable::USERS_SOURCE,
            self::ACCOUNT_DELETIONS_SOURCE,
            self::CONNECTIONS_SOURCE,
            self::IDENTITIES_SOURCE,
            self::SECOND_FACTORS_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => AbstractHilosUsersTable::USERS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectUser::id,
                BrowserTableFieldKey::WHERE => [
                    ObjectUser::id => self::TABLE_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    ObjectUser::id => HilosUserTableRow::id,
                    ObjectUser::name => HilosUserTableRow::name,
                    ObjectUser::lastActivity => HilosUserTableRow::lastActivity,
                    ObjectUser::admin => HilosUserTableRow::admin,
                    ObjectUser::block => HilosUserTableRow::block,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::CONNECTIONS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => HilosConnection::userId,
                BrowserTableFieldKey::WHERE => [
                    HilosConnection::userId => self::TABLE_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    HilosConnection::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
                BrowserTableFieldKey::NOT_PERSONAL => [
                    HilosConnection::userId,
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::IDENTITIES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectIdentity::userId,
                BrowserTableFieldKey::WHERE => [
                    ObjectIdentity::userId => self::TABLE_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    ObjectIdentity::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosMergeCandidatesTable::FIELD_HAS_PASSWORD,
                    HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS,
                ],
                BrowserTableFieldKey::NOT_PERSONAL => [
                    HilosMergeCandidatesTable::FIELD_HAS_PASSWORD,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::SECOND_FACTORS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectSecondFactor::userId,
                BrowserTableFieldKey::WHERE => [ObjectSecondFactor::userId => self::TABLE_USER_ID],
                BrowserTableFieldKey::FIELDS => [ObjectSecondFactor::userId],
                BrowserTableFieldKey::COMPUTED => [HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::ACCOUNT_DELETIONS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectAccountDeletion::userId,
                BrowserTableFieldKey::WHERE => [
                    ObjectAccountDeletion::userId => self::TABLE_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    ObjectAccountDeletion::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT,
                ],
                BrowserTableFieldKey::NOT_PERSONAL => [
                    HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT,
                ],
            ],
        ],
    ];

    private const array TABLE_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];
    private const array ACCOUNT_DELETIONS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::accountDeletions,
    ];
    private const array CONNECTIONS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => self::CONNECTIONS,
    ];
    private const array SECOND_FACTORS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::secondFactors,
    ];
    private const array IDENTITIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::identities,
    ];
}
