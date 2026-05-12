<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Item\Bot as DbBot;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;

/**
 * Table definition for bot administration rows and actions.
 *
 * @property-read BotsTableActions $actions Table-level bot creation actions
 */
final class BotsTable extends TableDefinition
{
    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserFieldKey::FIELDS => [
                    ObjectBot::id => BotTableRow::id,
                    ObjectBot::name => BotTableRow::name,
                    ObjectBot::description => BotTableRow::description,
                    ObjectBot::style => BotTableRow::style,
                    ObjectBot::topics => BotTableRow::topics,
                    ObjectBot::personality => BotTableRow::personality,
                    ObjectBot::active => BotTableRow::active,
                    ObjectBot::reactionDelayMin => BotTableRow::reactionDelayMin,
                    ObjectBot::reactionDelayMax => BotTableRow::reactionDelayMax,
                    ObjectBot::reactionChance => BotTableRow::reactionChance,
                    ObjectBot::topicMatchRequired => BotTableRow::topicMatchRequired,
                    ObjectBot::cooldownAfterMessage => BotTableRow::cooldownAfterMessage,
                    ObjectBot::priority => BotTableRow::priority,
                ],
            ],
        ],
    ];

    /**
     * Builds a bot row mutation from a bot source change.
     *
     * @param SourceChange $change Bot source change to project into the bots table
     * @return ?TableRowMutationDTO Bot row mutation, or null when the change does not affect this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== ChatDbContext::bots) {
            return null;
        }

        $botId = (int) $change->sourceId;
        if ($botId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $botId);
        }

        $dbBot = Hilos::$db->bots[$botId] ?? null;
        if ($dbBot === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $botId,
            $this->rowFromBot($dbBot),
        );
    }

    /**
     * Loads one page of bot rows for the bots table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Bot table snapshot
     * @throws DatabaseException When bot query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->bots->queryPageItems($query);

        return new TableSnapshotDTO(
            rows: array_map(
                fn(DbBot $bot): BotTableRow => $this->rowFromBot($bot),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            offset: $query->offset,
            limit: $query->limit,
        );
    }

    /**
     * Builds the bots table row from the bot DB item.
     *
     * @param DbBot $bot Bot DB item to project into the bots table
     * @return BotTableRow Bots table row payload
     */
    public function rowFromBot(DbBot $bot): BotTableRow
    {
        return new BotTableRow(
            id: (int) $bot->id,
            name: $bot->name,
            description: $bot->description,
            style: $bot->style,
            topics: $bot->topics,
            personality: $bot->personality,
            active: $bot->active,
            reactionDelayMin: $bot->reactionDelayMin,
            reactionDelayMax: $bot->reactionDelayMax,
            reactionChance: $bot->reactionChance,
            topicMatchRequired: $bot->topicMatchRequired,
            cooldownAfterMessage: $bot->cooldownAfterMessage,
            priority: $bot->priority,
        );
    }

    /**
     * Configures the row shape and actions used by the bots table.
     */
    protected function init(): void
    {
        $this->setRowClass(BotTableRow::class);
        $this->setActionsClass(BotsTableActions::class);
        $this->setItemActionsClass(BotItemActions::class);
    }
}
