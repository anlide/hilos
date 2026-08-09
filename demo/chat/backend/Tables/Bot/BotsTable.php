<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Item\Bot as DbBot;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;

/**
 * Table definition for bot administration rows and actions.
 *
 * @property-read BotsTableActions $actions Table-level bot creation actions
 */
final class BotsTable extends TableDefinition implements ViewportTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserTableFieldKey::ROW_KEY => ObjectBot::id,
                BrowserTableFieldKey::FIELDS => [
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
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_BOT_AGENT_STATUSES,
                BrowserTableFieldKey::ROW_KEY => StateBotAgentStatus::botId,
                BrowserTableFieldKey::FIELDS => [
                    StateBotAgentStatus::botId,
                    StateBotAgentStatus::status,
                    StateBotAgentStatus::updatedAt,
                ],
            ],
        ],
    ];

    /**
     * Builds a bot row mutation from a bot or bot-runtime source change.
     *
     * @param SourceChange $change Bot source change to project into the bots table
     * @return ?TableRowMutationDTO Bot row mutation, or null when the change does not affect this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return match ($change->sourceKey) {
            ChatDbContext::bots => $this->mutationForDbBot($change),
            ChatRtContext::botAgentStatuses => $this->mutationForBotAgentStatus($change),
            default => null,
        };
    }

    /**
     * Serializes one bot row into its internal browser-row envelope.
     *
     * The runtime agent status rides the inline `botAgentStatuses` slot and the
     * bot profile fields the entity-bearing `bots` slot — the same shape the
     * declarative fan-out delivers, so a windowed or delta row resolves through
     * the frontend identically (and an edit fans out through the bot entity).
     *
     * @param AbstractTableRow $row Bot row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $fields = $row->toArray();
        $status = $fields[BotTableRow::status] ?? null;
        unset($fields[BotTableRow::status]);

        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                ChatDbContext::bots => $fields,
                ChatRtContext::botAgentStatuses => [
                    StateBotAgentStatus::status => $status,
                ],
            ],
        ];
    }

    /**
     * Builds a row mutation for a persisted bot create, update, or delete.
     *
     * @param SourceChange $change DB bot source change
     * @return ?TableRowMutationDTO Bot row mutation, or null for an invalid source id
     */
    private function mutationForDbBot(SourceChange $change): ?TableRowMutationDTO
    {
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
     * Bot agent status changes keep the DB row visible and refresh the runtime fragment.
     *
     * @param SourceChange $change Runtime bot status source change
     * @return ?TableRowMutationDTO Bot row update, or null when no bot can be resolved
     */
    private function mutationForBotAgentStatus(SourceChange $change): ?TableRowMutationDTO
    {
        $botId = (int) ($change->row[StateBotAgentStatus::botId] ?? $change->sourceId);
        if ($botId <= 0) {
            return null;
        }

        $dbBot = Hilos::$db->bots[$botId] ?? null;
        if ($dbBot === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $botId,
            $this->rowFromBot($dbBot),
        );
    }

    /**
     * Loads one window of bot rows, filtered in memory.
     *
     * Bots carry a runtime agent status (presence) that is not a DB column, so
     * the window — search, sort (including by presence), and paging — is applied
     * in memory over the runtime-enriched rows rather than pushed to the database.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Bot table window
     * @throws DatabaseException When bot query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->bots->queryPageItems(new TableQueryDTO());

        return InMemoryTableFilter::apply(
            rows: array_map(
                fn(DbBot $bot): array => $this->rowFromBot($bot)->toArray(),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            query: $query,
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
            status: $bot->agentStatus?->status,
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
