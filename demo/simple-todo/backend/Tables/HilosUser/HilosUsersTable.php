<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tables\HilosUser;

use Demo\SimpleTodo\Browser\TodoBrowserSource;
use Demo\SimpleTodo\Database\Object\Item\User as ObjectUser;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Database\View\Item\User as DbUser;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Runtime\State\Item\Connection as ConnectionState;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Demo\SimpleTodo\Tables\HilosUser\Actions\HilosUserItemActions;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
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
 * Simple-todo activation of the framework Hilos users table.
 *
 * Binds the demo DB users and RT connections to the framework presence-merge
 * engine and projects them into the todo user row (the framework
 * admin/block/presence fields plus the todo profile fields).
 */
final class HilosUsersTable extends AbstractHilosUsersTable
{
    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            TodoBrowserSource::DB_USERS,
            TodoBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => TodoBrowserSource::DB_USERS,
                BrowserFieldKey::ROW_KEY => ObjectUser::id,
                BrowserFieldKey::FIELDS => [
                    ObjectUser::id => HilosUserTableRow::id,
                    ObjectUser::admin => HilosUserTableRow::admin,
                    ObjectUser::block => HilosUserTableRow::block,
                    ObjectUser::name => HilosUserTableRow::name,
                    ObjectUser::lastActivity => HilosUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => TodoBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => ConnectionState::userId,
                BrowserFieldKey::FIELDS => [
                    ConnectionState::userId,
                ],
                BrowserFieldKey::COMPUTED => [
                    HilosUserTableRow::presence,
                    HilosUserTableRow::onlineSessionCount,
                ],
            ],
        ],
    ];

    /**
     * Binds the demo DB users collection as this table's user source.
     */
    protected function usersSourceKey(): string
    {
        return TodoDbContext::users;
    }

    /**
     * Binds the demo RT connections collection as this table's presence source.
     */
    protected function presenceSourceKey(): string
    {
        return TodoRtContext::connections;
    }

    /**
     * The demo RT connections collection, which implements the presence source.
     */
    protected function presenceSource(): HilosPresenceSource
    {
        return Hilos::$rt->connections;
    }

    /**
     * Builds the current row for a user id from the demo DB users collection.
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
     * On create the row carries the user id; an update may carry a narrow diff
     * without it, so fall back to the live RT row.
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
     * Queries demo users for the Hilos users table.
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
