<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\HilosUser\Actions\HilosUserItemActions;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Table definition for the Hilos users page.
 */
final class HilosUsersTable extends TableDefinition
{
    /**
     * Uses the chat users collection as the source for Hilos user rows.
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new EntityTableDataSource(Hilos::$db->users);
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
