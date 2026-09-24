<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User as DbUser;
use Demo\Chat\Hilos;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\HilosException;
use Hilos\Tables\Users\AbstractHilosMergeCandidatesTable;
use Hilos\Tables\Users\AbstractHilosUserTableRow;

/**
 * Chat activation of the framework account-merge candidate table.
 *
 * The project supplies its user collection and excludes tombstones. Identity metadata,
 * password presence, search, survivor filtering, and live mutations remain framework-owned.
 */
final class HilosMergeCandidatesTable extends AbstractHilosMergeCandidatesTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::DB_IDENTITIES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserTableFieldKey::ROW_KEY => ObjectUser::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectUser::id => HilosUserTableRow::id,
                    ObjectUser::admin => HilosUserTableRow::admin,
                    ObjectUser::block => HilosUserTableRow::block,
                    ObjectUser::name => HilosUserTableRow::name,
                    ObjectUser::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_IDENTITIES,
                BrowserTableFieldKey::ROW_KEY => ObjectIdentity::userId,
                BrowserTableFieldKey::MANY => true,
                BrowserTableFieldKey::FIELDS => [
                    ObjectIdentity::userId,
                ],
            ],
        ],
    ];

    /** @return string Chat DB user source key */
    protected function usersSourceKey(): string
    {
        return ChatDbContext::users;
    }

    /**
     * @return iterable<int> Current chat user ids, including tombstones rejected by the row seam
     * @throws DatabaseException When the user query fails
     */
    protected function userIds(): iterable
    {
        $result = Hilos::$db->users->queryPageItems(new TableQueryDTO());

        return array_map(
            static fn(DbUser $user): int => (int) $user->id,
            $result[TableConstants::RESULT_KEY_ROWS],
        );
    }

    /**
     * @param int $userId User id to project
     * @return ?AbstractHilosUserTableRow Candidate row, or null for a missing or merged account
     * @throws HilosException When the user cannot be read
     */
    protected function candidateRowForUserId(int $userId): ?AbstractHilosUserTableRow
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null || $user->mergedInto !== null) {
            return null;
        }

        return new HilosUserTableRow(
            id: (int) $user->id,
            admin: $user->admin,
            block: $user->block,
            name: $user->name,
            lastActivity: $user->lastActivity,
        );
    }
}
