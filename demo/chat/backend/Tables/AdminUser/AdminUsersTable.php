<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User as DbUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as ConnectionState;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\Actions\AdminUserItemActions;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * Table definition for the admin users grid.
 */
final class AdminUsersTable extends TableDefinition implements ViewportTable
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
                    ObjectUser::id => AdminUserTableRow::id,
                    ObjectUser::name => AdminUserTableRow::name,
                    ObjectUser::lastActivity => AdminUserTableRow::lastActivity,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserTableFieldKey::ROW_KEY => ConnectionState::userId,
                BrowserTableFieldKey::FIELDS => [
                    ConnectionState::userId,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    AdminUserTableRow::presence,
                    AdminUserTableRow::onlineSessionCount,
                ],
            ],
        ],
    ];

    /**
     * Builds an admin users row mutation from one user-affecting source change.
     *
     * Reacts to two sources:
     * - {@see ChatDbContext::users} — DB user create/update/delete.
     * - {@see ChatRtContext::connections} — connection lifecycle that flips the
     *   user's online session count and presence summary projected into the row.
     *
     * @param SourceChange $change DB or RT source change to project into the admin users table
     * @return ?TableRowMutationDTO Admin users row mutation, or null when the change does not affect this table
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return match ($change->sourceKey) {
            ChatDbContext::users => $this->mutationForDbUser($change),
            ChatRtContext::connections => $this->mutationForConnection($change),
            default => null,
        };
    }

    /**
     * Serializes one admin users row into its internal browser-row envelope.
     *
     * The runtime presence fields ride the inline `connections` slot and the user
     * identity/profile fields the entity-bearing `users` slot — the same shape the
     * declarative fan-out delivers, so a windowed or delta row resolves through the
     * frontend identically (and a rename fans out through the user entity).
     *
     * @param AbstractTableRow $row Admin users row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $fields = $row->toArray();
        $connections = [
            AdminUserTableRow::presence => $fields[AdminUserTableRow::presence] ?? null,
            AdminUserTableRow::onlineSessionCount => $fields[AdminUserTableRow::onlineSessionCount] ?? 0,
        ];
        unset(
            $fields[AdminUserTableRow::presence],
            $fields[AdminUserTableRow::onlineSessionCount],
        );

        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
            BrowserPageSignalData::sources => [
                ChatDbContext::users => $fields,
                ChatRtContext::connections => $connections,
            ],
        ];
    }

    /**
     * Builds a row mutation for a DB user create, update, or delete.
     *
     * @param SourceChange $change DB user source change
     * @return ?TableRowMutationDTO Admin users row mutation, or null for an invalid source id
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    private function mutationForDbUser(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = (int) $change->sourceId;
        if ($userId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $userId);
        }

        $dbUser = Hilos::$db->users[$userId] ?? null;
        if ($dbUser === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $userId,
            $this->rowFromUser($dbUser),
        );
    }

    /**
     * Connection lifecycle never removes a user row; it always projects to an
     * Update with refreshed onlineSessionCount/presence aggregates.
     *
     * @param SourceChange $change Runtime connection source change
     * @return ?TableRowMutationDTO Admin users row update, or null when no user can be resolved
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    private function mutationForConnection(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = $this->resolveUserIdForConnection($change);
        if ($userId <= 0) {
            return null;
        }

        $dbUser = Hilos::$db->users[$userId] ?? null;
        if ($dbUser === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $userId,
            $this->rowFromUser($dbUser),
        );
    }

    /**
     * Resolves the user id affected by a connection source change.
     *
     * On Create the row carries the full state. On Update the row may carry a
     * narrow diff without userId, so we fall back to the live RT row. On Delete
     * the RT row is already gone, so we rely on the previous-row payload that
     * the source emits when available.
     *
     * @param SourceChange $change Runtime connection source change
     * @return int Affected user id, or 0 when it cannot be resolved
     */
    private function resolveUserIdForConnection(SourceChange $change): int
    {
        $userId = (int) ($change->row[ConnectionState::userId] ?? 0);
        if ($userId > 0) {
            return $userId;
        }
        $liveConnection = Hilos::$rt->connections[$change->sourceId] ?? null;
        return $liveConnection?->userId ?? 0;
    }

    /**
     * Queries chat users for the admin users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Admin users table snapshot
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
     * Builds the admin users table row from DB fields plus runtime connection state.
     *
     * @param DbUser $user User DB item to project into the admin table
     * @return AdminUserTableRow Runtime-enriched admin users table row
     * @throws RtActionsStateCollectionNullException When runtime connection state is unavailable
     */
    public function rowFromUser(DbUser $user): AdminUserTableRow
    {
        $summary = Hilos::$rt->connections->summaryForUser((int) $user->id);

        return new AdminUserTableRow(
            id: (int) $user->id,
            name: $user->name,
            lastActivity: $user->lastActivity,
            onlineSessionCount: $summary->onlineSessionCount,
            presence: $summary->presence,
        );
    }

    /**
     * Configures the row shape and item actions used by the admin users table.
     */
    protected function init(): void
    {
        $this->setRowClass(AdminUserTableRow::class);
        $this->setItemActionsClass(AdminUserItemActions::class);
    }
}
