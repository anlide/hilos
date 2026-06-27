<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User as DbUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as ConnectionState;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\HilosUser\Actions\HilosUserItemActions;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Tables\Users\AbstractHilosUsersTable;

/**
 * Chat activation of the framework Hilos users table.
 *
 * Binds the chat DB users and RT connections to the framework presence-merge
 * engine and projects them into the chat user row (the framework
 * admin/block/presence fields plus the chat profile fields).
 */
final class HilosUsersTable extends AbstractHilosUsersTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
            ChatBrowserSource::RT_CONNECTIONS,
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
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => ConnectionState::userId,
                BrowserTableFieldKey::FIELDS => [
                    ConnectionState::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosUserTableRow::presence,
                    HilosUserTableRow::onlineSessionCount,
                ],
            ],
        ],
    ];

    /**
     * Binds the chat DB users collection as this table's user source.
     */
    protected function usersSourceKey(): string
    {
        return ChatDbContext::users;
    }

    /**
     * Binds the chat RT connections collection as this table's presence source.
     */
    protected function presenceSourceKey(): string
    {
        return ChatRtContext::connections;
    }

    /**
     * The chat RT connections collection, which implements the presence source.
     */
    protected function presenceSource(): HilosPresenceSource
    {
        return Hilos::$rt->connections;
    }

    /**
     * Builds the current row for a user id from the chat DB users collection.
     *
     * @param int $userId User id to project into a row
     * @return ?HilosUserTableRow Current row, or null when the user no longer exists
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    protected function rowForUserId(int $userId): ?HilosUserTableRow
    {
        $dbUser = Hilos::$db->users[$userId] ?? null;

        return $dbUser === null ? null : $this->rowFromUser($dbUser);
    }

    /**
     * Resolves the affected user id from a connection source change.
     *
     * On Create the row carries the full state. On Update the row may carry a
     * narrow diff without userId, so we fall back to the live RT row.
     *
     * @param SourceChange $change Runtime connection source change
     * @return int Affected user id, or 0 when it cannot be resolved
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    protected function resolveUserIdForPresence(SourceChange $change): int
    {
        $userId = (int) ($change->row[ConnectionState::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        return Hilos::$rt->connections[$change->sourceId]?->userId ?? 0;
    }

    /**
     * Builds the Hilos users row from DB fields plus runtime presence.
     *
     * @param DbUser $user User DB item to project into the Hilos users table
     * @return HilosUserTableRow Runtime-enriched Hilos users table row
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    public function rowFromUser(DbUser $user): HilosUserTableRow
    {
        $summary = $this->presenceForUser((int) $user->id);

        return new HilosUserTableRow(
            id: (int) $user->id,
            admin: $user->admin,
            block: $user->block,
            name: $user->name,
            lastActivity: $user->lastActivity,
            onlineSessionCount: $summary->onlineSessionCount,
            presence: $summary->presence,
        );
    }

    /**
     * Queries chat users for the Hilos users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Hilos users table snapshot
     * @throws DatabaseException When user query execution fails
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->users->queryPageItems(new TableQueryDTO());

        return InMemoryTableFilter::apply(
            rows: array_map(
                fn(DbUser $user): array => $this->rowFromUser($user)->toArray(),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            query: $query,
        );
    }

    /**
     * Configures the row shape and item actions used by the Hilos users table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosUserTableRow::class);
        $this->setItemActionsClass(HilosUserItemActions::class);
    }
}
