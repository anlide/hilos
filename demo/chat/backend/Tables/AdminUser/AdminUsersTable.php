<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\Actions\AdminUserItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\DatabaseException;

/**
 * Table definition for the admin users grid.
 */
final class AdminUsersTable extends TableDefinition
{
    /**
     * Builds an admin users row mutation from a user source event.
     *
     * @param TableSourceEventDTO $event User source event to project into the admin users table
     * @return ?TableRowMutationDTO Admin users row mutation, or null when the event does not affect this table
     * @throws DatabaseException If source user lookup fails
     */
    public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
    {
        if ($event->sourceKey !== DbChatContext::users) {
            return null;
        }

        $userId = (int) $event->sourceRowKey;
        if ($userId <= 0) {
            return null;
        }

        if ($event->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $userId);
        }

        $dbUser = Hilos::$db->users[$userId] ?? null;
        if ($dbUser === null) {
            return null;
        }

        return $this->mutation(
            $event->mutationType,
            $userId,
            AdminUserTableRow::fromDbUser($dbUser),
        );
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
        $snapshot = $this->queryDbCollection(Hilos::$db->users, $query);

        return new TableSnapshotDTO(
            rows: array_map(
                fn(AbstractTableRow|array $row): AdminUserTableRow => $this->makeAdminUserTableRow($row),
                $snapshot->rows,
            ),
            totalCount: $snapshot->totalCount,
            offset: $snapshot->offset,
            limit: $snapshot->limit,
        );
    }

    /**
     * Converts a DB user row payload into the runtime-enriched admin users table row.
     *
     * @param AbstractTableRow|array<string, mixed> $row Source row returned by the DB collection table query
     */
    private function makeAdminUserTableRow(AbstractTableRow|array $row): AdminUserTableRow
    {
        $rowPayload = $row instanceof AbstractTableRow ? $row->toArray() : $row;
        $userId = (int) ($rowPayload[AdminUserTableRow::id] ?? 0);
        $dbUser = $userId > 0 ? Hilos::$db->users[$userId] ?? null : null;

        return $dbUser === null ? AdminUserTableRow::fromArray($rowPayload) : AdminUserTableRow::fromDbUser($dbUser);
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
