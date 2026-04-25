<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\Actions\AdminUserItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Database\DatabaseException;

/**
 * Table definition for the admin users grid.
 */
final class AdminUsersTable extends TableDefinition
{
    /**
     * Queries chat users for the admin users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableResultDTO Admin users table rows
     * @throws DatabaseException If user query execution fails
     */
    protected function query(TableQueryDTO $query): TableResultDTO
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
