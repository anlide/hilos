<?php

declare(strict_types=1);

namespace Hilos\Tables\Settings;

use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Item\Setting as ViewSetting;
use Hilos\Hilos;
use Hilos\Tables\Settings\Actions\HilosSettingItemActions;
use Hilos\Tables\Settings\Actions\HilosSettingsTableActions;
use Throwable;

/**
 * Table definition that merges settings catalog metadata with persisted rows.
 *
 * The catalog is bound once to the settings facade (Hilos::$setting); this table
 * reads it back through the accessor instead of importing a project catalog.
 */
final class HilosSettingsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'settings';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::DB_SETTINGS_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::DB_SETTINGS_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectSetting::key,
                BrowserTableFieldKey::FIELDS => [
                    ObjectSetting::id => HilosSettingTableRow::id,
                    ObjectSetting::key => HilosSettingTableRow::key,
                    ObjectSetting::type => HilosSettingTableRow::type,
                    ObjectSetting::value => HilosSettingTableRow::overrideValue,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosSettingTableRow::value,
                    HilosSettingTableRow::defaultValue,
                    HilosSettingTableRow::defaultReferenceKey,
                    HilosSettingTableRow::valueSource,
                ],
            ],
        ],
    ];

    private const array DB_SETTINGS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::settings,
    ];

    /**
     * Builds a settings table row mutation from a settings DB source change.
     *
     * @param SourceChange $change Settings source change
     * @return ?TableRowMutationDTO Settings row mutation, or null when the change does not affect this table
     * @throws DatabaseException When persisted settings or referenced defaults cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = $this->settingKeyFromSourceChange($change);
        if ($key === '') {
            return null;
        }

        $row = $this->rowForKey($key);
        if ($row === null) {
            return $this->mutation(TableMutationType::Delete, $key);
        }

        return $this->mutation(
            $change->mutationType === TableMutationType::Delete ? TableMutationType::Update : $change->mutationType,
            $key,
            $row,
        );
    }

    /**
     * Builds the current table row for a setting key.
     *
     * Catalog keys without a persisted setting return their placeholder row.
     * Unknown uncataloged keys return null so browser state can delete orphan rows.
     *
     * @param string $key Setting key
     * @return ?HilosSettingTableRow Current settings table row, or null when the key is not visible
     * @throws DatabaseException When persisted settings or referenced defaults cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    public function rowForKey(string $key): ?HilosSettingTableRow
    {
        if ($key === '') {
            return null;
        }

        $setting = $this->settingsCollection()[$key] ?? null;
        if ($setting !== null) {
            return $this->rowFromSetting($setting);
        }

        $catalog = $this->settingsAccessor()->catalog();
        if (!isset($catalog[$key])) {
            return null;
        }

        return $this->rowFromCatalogEntry($key, $catalog[$key]);
    }

    /**
     * Builds the settings table row from a persisted setting item.
     *
     * @param ViewSetting $setting Persisted setting DB item
     * @return HilosSettingTableRow Settings table row payload
     * @throws DatabaseException When a referenced persisted default cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    public function rowFromSetting(ViewSetting $setting): HilosSettingTableRow
    {
        $catalog = $this->settingsAccessor()->catalog();
        if (!isset($catalog[$setting->key])) {
            return new HilosSettingTableRow(
                id: $setting->id,
                key: $setting->key,
                type: $setting->type,
                value: $setting->value,
                overrideValue: $setting->value,
                defaultValue: null,
                defaultReferenceKey: null,
                valueSource: HilosSettingTableRow::VALUE_SOURCE_ORPHAN,
                persisted: true,
            );
        }

        $type = $catalog[$setting->key][SettingsCatalogConstants::CATALOG_ENTRY_TYPE]
            ?? SettingsCatalogConstants::TYPE_STRING;
        $defaultValue = $this->serializeValue($this->settingsAccessor()->defaultValueFor($setting->key), $type);
        $defaultReferenceKey = $this->settingsAccessor()->defaultReferenceKeyFor($setting->key);
        $overrideValue = $setting->value;

        return new HilosSettingTableRow(
            id: $setting->id,
            key: $setting->key,
            type: $type,
            value: $overrideValue ?? $defaultValue,
            overrideValue: $overrideValue,
            defaultValue: $defaultValue,
            defaultReferenceKey: $defaultReferenceKey,
            valueSource: $overrideValue !== null
                ? HilosSettingTableRow::VALUE_SOURCE_OVERRIDE
                : ($defaultReferenceKey !== null
                    ? HilosSettingTableRow::VALUE_SOURCE_REFERENCE
                    : HilosSettingTableRow::VALUE_SOURCE_DEFAULT),
            persisted: true,
        );
    }

    /**
     * Serializes one settings row into its internal browser-row envelope.
     *
     * The DB id is dropped from the slot: the frontend normalizer treats any slot
     * carrying a non-null id as a referenced entity, but settings rows are
     * page-scoped, keyed by the setting key, and carry a nullable id (catalog
     * placeholders have none). The row's own `persisted` flag carries that fact
     * instead — valueSource cannot, since a stored NULL and an absent row both
     * read as default/reference.
     *
     * @param AbstractTableRow $row Settings table row from this table's snapshot or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $slot = $row->toArray();
        unset($slot[HilosSettingTableRow::id]);

        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
            BrowserPageSignalData::sources => [
                HilosDbContext::settings => $slot,
            ],
        ];
    }

    /**
     * Queries settings by merging catalog entries with DB rows.
     *
     * For each catalog key, the row contains the DB value when present or a
     * placeholder with the configured default. DB keys missing from the catalog
     * are appended as orphan rows.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Settings table snapshot
     * @throws DatabaseException When settings rows or referenced defaults cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $catalog = $this->settingsAccessor()->catalog();
        $dbByKey = $this->buildDbByKey();

        $rows = [];
        foreach ($catalog as $key => $entry) {
            if (isset($dbByKey[$key])) {
                $rows[] = $this->rowFromSetting($dbByKey[$key])->toArray();
            } else {
                $rows[] = $this->rowFromCatalogEntry($key, $entry)->toArray();
            }
        }

        foreach ($this->settingsCollection()->getOrphans($catalog) as $orphan) {
            $rows[] = $this->rowFromSetting($orphan)->toArray();
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures the row shape and actions used by the settings table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosSettingTableRow::class);
        $this->setActionsClass(HilosSettingsTableActions::class);
        $this->setItemActionsClass(HilosSettingItemActions::class);
    }

    /**
     * Builds an index of persisted setting rows by setting key.
     *
     * @return array<string, ViewSetting>
     * @throws DatabaseException When settings query execution fails
     */
    private function buildDbByKey(): array
    {
        $result = [];
        $settings = $this->settingsCollection()->queryPageItems(new TableQueryDTO());
        foreach ($settings[TableConstants::RESULT_KEY_ROWS] as $setting) {
            $result[$setting->key] = $setting;
        }
        return $result;
    }

    /**
     * Resolves the settings key from a DB source change.
     *
     * @param SourceChange $change Settings source change
     * @return string Setting key, or an empty string when it cannot be resolved
     */
    private function settingKeyFromSourceChange(SourceChange $change): string
    {
        $key = $change->row[ObjectSetting::key] ?? null;
        if (is_string($key) || is_int($key)) {
            return (string) $key;
        }

        try {
            $setting = ctype_digit($change->sourceId)
                ? ($this->settingsCollection()[(int) $change->sourceId] ?? null)
                : null;
            if ($setting !== null) {
                return $setting->key;
            }
        } catch (Throwable) {
            return '';
        }

        return $change->sourceId;
    }

    /**
     * Creates a frontend row for a catalog key that has no persisted DB row.
     *
     * @param string $key Setting key from the catalog
     * @param array<string, mixed> $entry Catalog entry
     * @return HilosSettingTableRow Placeholder settings table row
     * @throws DatabaseException When a referenced persisted default cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    private function rowFromCatalogEntry(string $key, array $entry): HilosSettingTableRow
    {
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;
        $defaultValue = $this->serializeValue($this->settingsAccessor()->defaultValueFor($key), $type);
        $defaultReferenceKey = $this->settingsAccessor()->defaultReferenceKeyFor($key);

        return new HilosSettingTableRow(
            id: null,
            key: $key,
            type: $type,
            value: $defaultValue,
            overrideValue: null,
            defaultValue: $defaultValue,
            defaultReferenceKey: $defaultReferenceKey,
            valueSource: $defaultReferenceKey !== null
                ? HilosSettingTableRow::VALUE_SOURCE_REFERENCE
                : HilosSettingTableRow::VALUE_SOURCE_DEFAULT,
            persisted: false,
        );
    }

    /**
     * Serializes a value for display in the settings table.
     *
     * @param mixed $value Setting value
     * @param string $type Setting type
     * @return ?string Serialized display value
     */
    private function serializeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (string)(int)$value,
            SettingsCatalogConstants::TYPE_FLOAT => (string)(float)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (string)(int)(bool)$value,
            default => is_scalar($value) ? (string)$value : null,
        };
    }

    /**
     * Returns the framework settings DB collection through the narrowed context.
     *
     * @return DbCollectionSettings Settings DB collection
     * @throws DatabaseException When the active database context is not a HilosDbContext
     */
    private function settingsCollection(): DbCollectionSettings
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new DatabaseException('Settings table requires a HilosDbContext database context');
        }

        return $db->settings;
    }

    /**
     * Returns the catalog-backed settings accessor through the facade.
     *
     * @return SettingsAccessor Settings accessor
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     */
    private function settingsAccessor(): SettingsAccessor
    {
        return Hilos::$setting
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
    }
}
