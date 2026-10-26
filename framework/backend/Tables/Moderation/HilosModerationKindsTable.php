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
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
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
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationLlmConstants;
use Hilos\Moderation\ModerationSettingsCatalog;

/** Published moderation kinds, in directory order unless the window selects another order. */
final class HilosModerationKindsTable extends TableDefinition implements MultiRowViewportTable
{
    public const string TABLE = 'hilosModerationKinds';
    public const string FILTER_KIND = 'kind';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [self::RULES_SOURCE, self::SETTINGS_SOURCE],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::RULES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectModerationRule::kind,
                BrowserTableFieldKey::FIELDS => [
                    ObjectModerationRule::id,
                    ObjectModerationRule::kind,
                    ObjectModerationRule::rule,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::SETTINGS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectSetting::id,
                BrowserTableFieldKey::FIELDS => [ObjectSetting::id, ObjectSetting::key, ObjectSetting::value],
            ],
        ],
    ];

    private const string ROW_SLOT = 'kind';
    private const string OWNER_FRAMEWORK = 'framework';
    private const string OWNER_PROJECT = 'project';
    private const int WINDOW_ROWS = 25;
    private const array RULES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::moderationRules,
    ];
    private const array SETTINGS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::settings,
    ];

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /** @return ?TableSortOrderDTO Null preserves the directory order */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return null;
    }

    /**
     * A rule changes its kind's count; a model setting changes every kind of that input profile.
     * An update's diff need not carry the source key, so current rows resolve it; deleted rows
     * are addressed from the fact itself.
     *
     * @param SourceChange $change Database rule or setting fact
     * @return list<TableRowMutationDTO> Current affected kinds, or none for unrelated sources
     * @throws DatabaseException When a rule or setting cannot be read
     * @throws InvalidArgumentException When a directory or loaded object is invalid
     * @throws LogicException When a collection is not configured
     * @throws SettingException When the model setting cannot be resolved
     * @throws InvalidFormatException When a projected count is negative
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->kind !== SourceChange::KIND_DB || !in_array($change->mutationType, [
            TableMutationType::Create, TableMutationType::Update, TableMutationType::Delete,
        ], true)) {
            return [];
        }

        if ($change->sourceKey === HilosDbContext::moderationRules) {
            $kindKey = $change->mutationType === TableMutationType::Delete
                ? ($change->row[ObjectModerationRule::kind] ?? $change->previous[ObjectModerationRule::kind] ?? null)
                : (isset(Hilos::$db->moderationRules[(int) $change->sourceId])
                    ? Hilos::$db->moderationRules[(int) $change->sourceId]->kind : null);
            $kind = is_string($kindKey) ? (Hilos::moderationKinds()[$kindKey] ?? null) : null;

            return $kind === null ? [] : [$this->mutation(TableMutationType::Update, $kind->key, $this->row($kind))];
        }

        if ($change->sourceKey !== HilosDbContext::settings) {
            return [];
        }
        $settingKey = $change->row[ObjectSetting::key] ?? $change->previous[ObjectSetting::key] ?? null;
        if ($settingKey === null && $change->mutationType !== TableMutationType::Delete
            && ctype_digit($change->sourceId) && isset(Hilos::$db->settings[(int) $change->sourceId])) {
            $settingKey = Hilos::$db->settings[(int) $change->sourceId]->key;
        }
        if ($settingKey !== ModerationSettingsCatalog::TEXT_MODEL && $settingKey !== ModerationSettingsCatalog::IMAGE_MODEL) {
            return [];
        }

        $mutations = [];
        foreach (Hilos::moderationKinds() as $kind) {
            if (($kind->input === ModerationInput::IMAGE) === ($settingKey === ModerationSettingsCatalog::IMAGE_MODEL)) {
                $mutations[] = $this->mutation(TableMutationType::Update, $kind->key, $this->row($kind));
            }
        }

        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Kind row from a query or source mutation
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

    /** @return array<string, WireField> Directory facts and the input-specific model setting origin */
    public function wireFields(): array
    {
        return [
            HilosModerationKindTableRow::kind => WireField::notPersonal(),
            HilosModerationKindTableRow::title => WireField::notPersonal(),
            HilosModerationKindTableRow::owner => WireField::notPersonal(),
            HilosModerationKindTableRow::input => WireField::notPersonal(),
            HilosModerationKindTableRow::model => WireField::settingFrom(
                static fn (array $row): string => ($row[HilosModerationKindTableRow::input] ?? null) === ModerationInput::IMAGE->value
                    ? ModerationSettingsCatalog::IMAGE_MODEL : ModerationSettingsCatalog::TEXT_MODEL,
            ),
            HilosModerationKindTableRow::ownRuleCount => WireField::notPersonal(),
            HilosModerationKindTableRow::profile => WireField::notPersonal(),
            HilosModerationKindTableRow::reasons => WireField::notPersonal(),
        ];
    }

    /**
     * @param string|int $rowKey Candidate kind key
     * @param TableQueryDTO $query Exact kind filter and search applied to the same complete rows as query
     * @return ?bool Whether the published kind matches; always known
     * @throws DatabaseException When a rule or setting cannot be read
     * @throws InvalidArgumentException When a directory or loaded object is invalid
     * @throws LogicException When a collection is not configured
     * @throws SettingException When a model setting cannot be resolved
     * @throws InvalidFormatException When a projected count is negative
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     * @throws TableSearchNotSupportedException When the search has no declared fields
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->rows($query), $rowKey, $this->scopeSearch($query));
    }

    /** Configures the typed kind row contract. */
    protected function init(): void
    {
        $this->setRowClass(HilosModerationKindTableRow::class);
    }

    /** @return array<string, string> Single-field orders, each mapped to its own payload field */
    protected function sortableFields(): array
    {
        return [
            HilosModerationKindTableRow::title => HilosModerationKindTableRow::title,
            HilosModerationKindTableRow::kind => HilosModerationKindTableRow::kind,
            HilosModerationKindTableRow::owner => HilosModerationKindTableRow::owner,
            HilosModerationKindTableRow::model => HilosModerationKindTableRow::model,
            HilosModerationKindTableRow::ownRuleCount => HilosModerationKindTableRow::ownRuleCount,
        ];
    }

    /** @return array<string, string> Title, key and model substring searches */
    protected function searchableFields(): array
    {
        return [
            HilosModerationKindTableRow::title => HilosModerationKindTableRow::title,
            HilosModerationKindTableRow::kind => HilosModerationKindTableRow::kind,
            HilosModerationKindTableRow::model => HilosModerationKindTableRow::model,
        ];
    }

    /** @return array<string, TableSortOrderDTO> No composite orders */
    protected function sortOrders(): array
    {
        return [];
    }

    /**
     * @param TableQueryDTO $query Optional exact kind filter, scoped search and selected window order
     * @return TableSnapshotDTO Window over the matching published kinds
     * @throws DatabaseException When a rule or setting cannot be read
     * @throws InvalidArgumentException When a directory or loaded object is invalid
     * @throws LogicException When a collection is not configured
     * @throws SettingException When a model setting cannot be resolved
     * @throws InvalidFormatException When a projected count is negative
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
     * @param TableQueryDTO $query Optional exact kind filter; absent keeps the complete directory
     * @return list<array<string, mixed>> Matching current rows in directory order
     * @throws DatabaseException When a rule or setting cannot be read
     * @throws InvalidArgumentException When a directory or loaded object is invalid
     * @throws LogicException When a collection is not configured
     * @throws SettingException When a model setting cannot be resolved
     * @throws InvalidFormatException When a projected count is negative
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     */
    private function rows(TableQueryDTO $query): array
    {
        $kinds = Hilos::moderationKinds();
        if (array_key_exists(self::FILTER_KIND, $query->filter)) {
            $kindKey = $query->filter[self::FILTER_KIND];
            $kinds = is_string($kindKey) && isset($kinds[$kindKey]) ? [$kinds[$kindKey]] : [];
        }
        $rows = [];
        foreach ($kinds as $kind) {
            $rows[] = $this->row($kind)->toArray();
        }

        return $rows;
    }

    /**
     * @param ModerationKind $kind Published directory entry
     * @return HilosModerationKindTableRow Current model and persisted own-rule count
     * @throws DatabaseException When a rule or setting cannot be read
     * @throws InvalidArgumentException When a directory or loaded object is invalid
     * @throws LogicException When a collection is not configured
     * @throws SettingException When a model setting cannot be resolved
     * @throws InvalidFormatException When the projected count is negative
     * @throws ObjectGetIdStringNotImplementedException When a matched rule has no object key
     * @throws CollectionNotManualException When a matched rule cannot enter the selection
     */
    private function row(ModerationKind $kind): HilosModerationKindTableRow
    {
        return new HilosModerationKindTableRow(
            kind: $kind->key,
            title: $kind->title,
            owner: Hilos::moderationKindDirectoryClass()::isFramework($kind->key) ? self::OWNER_FRAMEWORK : self::OWNER_PROJECT,
            input: $kind->input->value,
            model: Hilos::$setting[$kind->input === ModerationInput::IMAGE
                ? ModerationSettingsCatalog::IMAGE_MODEL : ModerationSettingsCatalog::TEXT_MODEL]->string(),
            ownRuleCount: count(Hilos::$db->moderationRules->whereColumnIs(ObjectModerationRule::kind, $kind->key)),
            profile: $kind->input === ModerationInput::IMAGE ? ModerationLlmConstants::PROFILE_IMAGE : ModerationLlmConstants::PROFILE_TEXT,
            reasons: $kind->reasons,
        );
    }
}
