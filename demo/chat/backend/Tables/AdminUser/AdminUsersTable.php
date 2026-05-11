<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User as DbUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as ConnectionState;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\AdminUser\Actions\AdminUserItemActions;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;

/**
 * Table definition for the admin users grid.
 */
final class AdminUsersTable extends TableDefinition
{
    /**
     * Builds an admin users row mutation from one user-affecting source change.
     *
     * Reacts to two sources:
     * - {@see DbChatContext::users} — DB user create/update/delete.
     * - {@see RtChatContext::connections} — connection lifecycle that flips the
     *   user's online session count and presence summary projected into the row.
     *
     * @param SourceChange $change DB or RT source change to project into the admin users table
     * @return ?TableRowMutationDTO Admin users row mutation, or null when the change does not affect this table
     * @throws DatabaseException If source user lookup fails
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return match ($change->sourceKey) {
            DbChatContext::users => $this->mutationForDbUser($change),
            RtChatContext::connections => $this->mutationForConnection($change),
            default => null,
        };
    }

    /**
     * @throws DatabaseException If source user lookup fails
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
     * @throws DatabaseException If source user lookup fails
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
     * Pulls the userId off a connection source change.
     *
     * On Create the row carries the full state. On Update the row may carry a
     * narrow diff without userId, so we fall back to the live RT row. On Delete
     * the RT row is already gone, so we rely on the previous-row payload that
     * the source emits when available.
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
     * @throws DatabaseException If user query execution fails
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
