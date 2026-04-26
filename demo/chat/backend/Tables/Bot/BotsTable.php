<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\DatabaseException;

/**
 * BotsTable - Table definition with create/update/delete actions.
 *
 * @property-read BotsTableActions $actions
 */
final class BotsTable extends TableDefinition
{
    /**
     * Builds a bot row mutation from a bot source event.
     *
     * @param TableSourceEventDTO $event Bot source event to project into the bots table
     * @return ?TableRowMutationDTO Bot row mutation, or null when the event does not affect this table
     * @throws DatabaseException If source bot lookup fails
     */
    public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
    {
        if ($event->sourceKey !== DbChatContext::bots) {
            return null;
        }

        $botId = (int) $event->sourceRowKey;
        if ($botId <= 0) {
            return null;
        }

        if ($event->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $botId);
        }

        $dbBot = Hilos::$db->bots[$botId] ?? null;
        if ($dbBot === null) {
            return null;
        }

        return $this->mutation(
            $event->mutationType,
            $botId,
            $this->makeRow($dbBot->toArray(toFrontend: true)),
        );
    }

    /**
     * Queries bots for the bots table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Bot table snapshot
     * @throws DatabaseException If bot query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
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
