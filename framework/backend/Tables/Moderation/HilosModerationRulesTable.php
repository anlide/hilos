<?php

declare(strict_types=1);

namespace Hilos\Tables\Moderation;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\View\Item\ModerationRule;
use Hilos\Hilos;

/** Persisted own rules of the published kind selected by the window. */
final class HilosModerationRulesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosModerationRules';
    public const string FILTER_KIND = 'kind';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [self::RULES_SOURCE],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::RULES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectModerationRule::id,
                BrowserTableFieldKey::FIELDS => [ObjectModerationRule::id, ObjectModerationRule::kind, ObjectModerationRule::rule],
            ],
        ],
    ];

    private const string ROW_SLOT = 'rule';
    private const int WINDOW_ROWS = 25;
    private const array RULES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::moderationRules,
    ];

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /** @return ?TableSortOrderDTO First window ordered by rule id ascending */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosModerationRuleTableRow::id));
    }

    /**
     * Create and update read the current row; delete addresses the vanished row by its source id.
     *
     * @param SourceChange $change Database rule fact
     * @return ?TableRowMutationDTO Current rule or deletion, or null for an unrelated or unpublished source
     * @throws DatabaseException When the rule cannot be read
     * @throws InvalidArgumentException When the directory or loaded object is invalid
     * @throws LogicException When the collection is not configured
     * @throws InvalidFormatException When a projected rule id is not positive
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        $rowKey = $this->rowKeyForSourceEvent($change);
        if ($rowKey === null) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $rowKey);
        }

        $row = $this->findRow($rowKey);

        return $row === null ? null : $this->mutation($change->mutationType, $rowKey, $row);
    }

    /**
     * @param SourceChange $change Ordinary create, update or delete fact
     * @return ?int Persisted rule id, without reading the row or its directory entry
     */
    public function rowKeyForSourceEvent(SourceChange $change): ?int
    {
        if ($change->kind !== SourceChange::KIND_DB || $change->sourceKey !== HilosDbContext::moderationRules
            || !in_array($change->mutationType, [TableMutationType::Create, TableMutationType::Update, TableMutationType::Delete], true)) {
            return null;
        }

        return self::ruleIdOf($change->sourceId);
    }

    /**
     * @param AbstractTableRow $row Rule row from a query or source mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser envelope
     * @throws TableRowKeyMissingException When the row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Existing DB column origins for every rule field */
    public function wireFields(): array
    {
        return [
            HilosModerationRuleTableRow::id => WireField::column(HilosDbContext::moderationRules, ObjectModerationRule::id),
            HilosModerationRuleTableRow::kind => WireField::column(HilosDbContext::moderationRules, ObjectModerationRule::kind),
            HilosModerationRuleTableRow::rule => WireField::column(HilosDbContext::moderationRules, ObjectModerationRule::rule),
        ];
    }

    /**
     * @param string|int $rowKey Candidate rule id
     * @param TableQueryDTO $query Kind and search applied to the same complete rows as query
     * @return ?bool Whether the rule belongs to the selected kind and search; always known
     * @throws DatabaseException When rules cannot be read
     * @throws InvalidArgumentException When the directory or a loaded object is invalid
     * @throws LogicException When the collection is not configured
     * @throws InvalidFormatException When a projected rule id is not positive
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     * @throws TableSearchNotSupportedException When the search has no declared fields
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->rows($query), $rowKey, $this->scopeSearch($query));
    }

    /**
     * Global lookup for focus and dialogs; membership in a window is answered by containsRow().
     *
     * @param string|int|null $rowKey Positive rule id, including its canonical decimal source form
     * @return ?HilosModerationRuleTableRow Current rule, or null for an invalid id, absent row or unpublished kind
     * @throws DatabaseException When the rule cannot be read
     * @throws InvalidArgumentException When the directory or loaded object is invalid
     * @throws LogicException When the collection is not configured
     * @throws InvalidFormatException When a projected rule id is not positive
     */
    public function findRow(string|int|null $rowKey): ?HilosModerationRuleTableRow
    {
        $id = self::ruleIdOf($rowKey);
        if ($id === null || !isset(Hilos::$db->moderationRules[$id])) {
            return null;
        }
        if (!isset(Hilos::moderationKinds()[Hilos::$db->moderationRules[$id]->kind])) {
            return null;
        }

        return $this->rowFromModerationRule(Hilos::$db->moderationRules[$id]);
    }

    /** Configures the typed rule row contract without mutation APIs. */
    protected function init(): void
    {
        $this->setRowClass(HilosModerationRuleTableRow::class);
    }

    /** @return array<string, string> Single-field orders over rule id and text */
    protected function sortableFields(): array
    {
        return [
            HilosModerationRuleTableRow::id => HilosModerationRuleTableRow::id,
            HilosModerationRuleTableRow::rule => HilosModerationRuleTableRow::rule,
        ];
    }

    /** @return array<string, string> Rule text substring search */
    protected function searchableFields(): array
    {
        return [HilosModerationRuleTableRow::rule => HilosModerationRuleTableRow::rule];
    }

    /** @return array<string, TableSortOrderDTO> No composite orders */
    protected function sortOrders(): array
    {
        return [];
    }

    /**
     * @param TableQueryDTO $query Published kind, scoped search and selected window order
     * @return TableSnapshotDTO Window over the kind's own rules, without built-in rules
     * @throws DatabaseException When rules cannot be read
     * @throws InvalidArgumentException When the directory or a loaded object is invalid
     * @throws LogicException When the collection is not configured
     * @throws InvalidFormatException When a projected rule id is not positive
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     * @throws TableSearchNotSupportedException When the search has no declared fields
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->rows($query), $query);
    }

    /**
     * @param TableQueryDTO $query Window whose kind selects the complete own-rule set
     * @return list<array<string, mixed>> Current rows in id order, empty without a published string kind
     * @throws DatabaseException When rules cannot be read
     * @throws InvalidArgumentException When the directory or a loaded object is invalid
     * @throws LogicException When the collection is not configured
     * @throws InvalidFormatException When a projected rule id is not positive
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     */
    private function rows(TableQueryDTO $query): array
    {
        $kind = $query->filter[self::FILTER_KIND] ?? null;
        if (!is_string($kind) || !isset(Hilos::moderationKinds()[$kind])) {
            return [];
        }

        $rows = [];
        foreach (Hilos::$db->moderationRules->whereColumnIs(ObjectModerationRule::kind, $kind) as $rule) {
            $rows[] = $this->rowFromModerationRule($rule)->toArray();
        }
        usort($rows, static fn (array $a, array $b): int => $a[HilosModerationRuleTableRow::id] <=> $b[HilosModerationRuleTableRow::id]);

        return $rows;
    }

    /**
     * @param string|int|null $rowKey Candidate row key or source id
     * @return ?int Positive id, or null for absent, noncanonical or out-of-range input
     */
    private static function ruleIdOf(string|int|null $rowKey): ?int
    {
        $id = (int) $rowKey;

        return $id > 0 && (string) $id === (string) $rowKey ? $id : null;
    }

    /**
     * @param ModerationRule $rule Persisted View row
     * @return HilosModerationRuleTableRow Typed rule projection
     * @throws InvalidFormatException When the persisted id is not positive
     */
    private function rowFromModerationRule(ModerationRule $rule): HilosModerationRuleTableRow
    {
        return new HilosModerationRuleTableRow($rule->id, $rule->kind, $rule->rule);
    }
}
