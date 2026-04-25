<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Database\DatabaseException;

/**
 * BotsTable - Table definition with create/update/delete actions.
 *
 * @property-read BotsTableActions $actions
 */
final class BotsTable extends TableDefinition
{
    /**
     * Queries bots for the bots table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableResultDTO Bot table rows
     * @throws DatabaseException If bot query execution fails
     */
    protected function query(TableQueryDTO $query): TableResultDTO
    {
        return $this->queryDbCollection(Hilos::$db->bots, $query);
    }

    /**
     * Configures table-level actions (BotsTableActions for create) and item-level actions (BotItemActions for update/delete).
     */
    protected function init(): void
    {
        $this->setRowClass(BotTableRow::class);
        $this->setActionsClass(BotsTableActions::class);
        $this->setItemActionsClass(BotItemActions::class);
    }
}
