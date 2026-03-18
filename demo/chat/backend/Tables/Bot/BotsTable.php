<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * BotsTable - Table definition with create/update/delete actions.
 *
 * @property-read BotsTableActions $actions
 */
final class BotsTable extends TableDefinition
{
    /**
     * Provides entity data source for the bots collection.
     *
     * @return TableDataSourceInterface Entity table data source for bots
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new EntityTableDataSource(Hilos::$db->bots);
    }

    /**
     * Configures table-level actions (BotsTableActions for create) and item-level actions (BotItemActions for update/delete).
     */
    protected function init(): void
    {
        $this->setActionsClass(BotsTableActions::class);
        $this->setItemActionsClass(BotItemActions::class);
    }
}
