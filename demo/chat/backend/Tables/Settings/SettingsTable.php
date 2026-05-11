<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\Actions\SettingItemActions;
use Demo\Chat\Tables\Settings\Actions\SettingsTableActions;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\Setting as ViewSetting;

/**
 * Table definition that merges settings catalog metadata with persisted rows.
 */
final class SettingsTable extends TableDefinition
{
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

        $key = $change->sourceId;
        if ($key === '') {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $key);
        }

        $setting = Hilos::$db->settings[$key];
        if ($setting === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $key,
            $this->rowFromSetting($setting),
        );
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
        $catalog = SettingsCatalog::getCatalog();
        $dbByKey = $this->buildDbByKey();

        $rows = [];
        foreach ($catalog as $key => $entry) {
            if (isset($dbByKey[$key])) {
                $rows[] = $this->rowFromSetting($dbByKey[$key])->toArray();
            } else {
                $rows[] = $this->rowFromCatalogEntry($key, $entry)->toArray();
            }
        }

        foreach (Hilos::$db->settings->getOrphans($catalog) as $orphan) {
            $rows[] = $this->rowFromSetting($orphan)->toArray();
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures the row shape and actions used by the settings table.
     */
    protected function init(): void
    {
        $this->setRowClass(SettingTableRow::class);
        $this->setActionsClass(SettingsTableActions::class);
        $this->setItemActionsClass(SettingItemActions::class);
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
        $settings = Hilos::$db->settings->queryPageItems(new TableQueryDTO());
        foreach ($settings[TableConstants::RESULT_KEY_ROWS] as $setting) {
            $result[$setting->key] = $setting;
        }
        return $result;
    }

    /**
     * Creates a frontend row for a catalog key that has no persisted DB row.
     *
     * @param string $key Setting key from the catalog
     * @param array<string, mixed> $entry Catalog entry
     * @return SettingTableRow Placeholder settings table row
     * @throws DatabaseException When a referenced persisted default cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    private function rowFromCatalogEntry(string $key, array $entry): SettingTableRow
    {
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;
        $defaultValue = $this->serializeValue(Hilos::$setting->defaultValueFor($key), $type);
        $defaultReferenceKey = Hilos::$setting->defaultReferenceKeyFor($key);

        return new SettingTableRow(
            id: null,
            key: $key,
            type: $type,
            value: $defaultValue,
            overrideValue: null,
            defaultValue: $defaultValue,
            defaultReferenceKey: $defaultReferenceKey,
            valueSource: $defaultReferenceKey !== null
                ? SettingTableRow::VALUE_SOURCE_REFERENCE
                : SettingTableRow::VALUE_SOURCE_DEFAULT,
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
     * Builds the settings table row from a persisted setting item.
     *
     * @param ViewSetting $setting Persisted setting DB item
     * @return SettingTableRow Settings table row payload
     * @throws DatabaseException When a referenced persisted default cannot be read
     * @throws SettingException When catalog default metadata is invalid
     */
    public function rowFromSetting(ViewSetting $setting): SettingTableRow
    {
        $catalog = SettingsCatalog::getCatalog();
        if (!isset($catalog[$setting->key])) {
            return new SettingTableRow(
                id: $setting->id,
                key: $setting->key,
                type: $setting->type,
                value: $setting->value,
                overrideValue: $setting->value,
                defaultValue: null,
                defaultReferenceKey: null,
                valueSource: SettingTableRow::VALUE_SOURCE_ORPHAN,
            );
        }

        $type = $catalog[$setting->key][SettingsCatalogConstants::CATALOG_ENTRY_TYPE]
            ?? SettingsCatalogConstants::TYPE_STRING;
        $defaultValue = $this->serializeValue(Hilos::$setting->defaultValueFor($setting->key), $type);
        $defaultReferenceKey = Hilos::$setting->defaultReferenceKeyFor($setting->key);
        $overrideValue = $setting->value;

        return new SettingTableRow(
            id: $setting->id,
            key: $setting->key,
            type: $type,
            value: $overrideValue ?? $defaultValue,
            overrideValue: $overrideValue,
            defaultValue: $defaultValue,
            defaultReferenceKey: $defaultReferenceKey,
            valueSource: $overrideValue !== null
                ? SettingTableRow::VALUE_SOURCE_OVERRIDE
                : ($defaultReferenceKey !== null
                    ? SettingTableRow::VALUE_SOURCE_REFERENCE
                    : SettingTableRow::VALUE_SOURCE_DEFAULT),
        );
    }
}
