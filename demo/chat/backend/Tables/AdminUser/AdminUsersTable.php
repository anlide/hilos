<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\Actions\AdminUserItemActions;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Table definition for the admin users grid.
 */
final class AdminUsersTable extends TableDefinition
{
    /**
     * Uses the chat users collection as the source for admin table rows.
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new EntityTableDataSource(Hilos::$db->users);
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
