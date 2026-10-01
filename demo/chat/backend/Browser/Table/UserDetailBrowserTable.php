<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Hilos\Database\Object\Item\User;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Object\Item\AccountDeletion;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Tables\Users\HilosMergeCandidatesTable;

/**
 * Browser table config for a single Hilos user detail page.
 */
final class UserDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::USER_DETAIL;

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_USER_USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::DB_ACCOUNT_DELETIONS,
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::DB_IDENTITIES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserTableFieldKey::ROW_KEY => User::id,
                BrowserTableFieldKey::WHERE => [
                    User::id => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    User::id => HilosUserTableRow::id,
                    User::name => HilosUserTableRow::name,
                    User::lastActivity => HilosUserTableRow::lastActivity,
                    User::admin => HilosUserTableRow::admin,
                    User::block => HilosUserTableRow::block,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => Connection::userId,
                BrowserTableFieldKey::WHERE => [
                    Connection::userId => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_IDENTITIES,
                BrowserTableFieldKey::ROW_KEY => Identity::userId,
                BrowserTableFieldKey::WHERE => [
                    Identity::userId => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    Identity::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosMergeCandidatesTable::FIELD_HAS_PASSWORD,
                    HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_ACCOUNT_DELETIONS,
                BrowserTableFieldKey::ROW_KEY => AccountDeletion::userId,
                BrowserTableFieldKey::WHERE => [
                    AccountDeletion::userId => ChatBrowserRef::TABLE_HILOS_USER_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    AccountDeletion::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT,
                ],
            ],
        ],
    ];
}
