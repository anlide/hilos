<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\HilosUser\Actions\HilosUserItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Database\DatabaseException;

/**
 * Table definition for the Hilos users page.
 */
final class HilosUsersTable extends TableDefinition
{
    /**
     * Queries chat users for the Hilos users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableResultDTO Hilos users table rows
     * @throws DatabaseException If user query execution fails
     */
    protected function query(TableQueryDTO $query): TableResultDTO
    {
        return $this->queryDbCollection(Hilos::$db->users, $query);
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
