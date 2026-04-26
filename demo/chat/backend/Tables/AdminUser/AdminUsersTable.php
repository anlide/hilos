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
            $this->makeRow($dbUser->toArray(toFrontend: true)),
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
        return $this->queryDbCollection(Hilos::$db->users, $query);
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
