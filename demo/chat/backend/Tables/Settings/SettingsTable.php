<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\Actions\SettingItemActions;
use Demo\Chat\Tables\Settings\Actions\SettingsTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\Setting as ViewSetting;

/**
 * SettingsTable - Table definition merging catalog (PHP config) and DB.
 *
 * Operations: change setting, delete orphan (via item actions).
 * Collection-level add via SettingsTableActions.
 */
final class SettingsTable extends TableDefinition
{
    /**
     * Queries settings by merging catalog entries with DB rows.
     *
     * For each catalog key, the row contains the DB value when present or a
     * placeholder with the configured default. DB keys missing from the catalog
     * are appended as orphan rows.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableResultDTO Settings table rows
     */
    protected function query(TableQueryDTO $query): TableResultDTO
    {
        $catalog = SettingsCatalog::getCatalog();
        $dbByKey = $this->buildDbByKey();

        $rows = [];
        foreach ($catalog as $key => $entry) {
            if (isset($dbByKey[$key])) {
                $rows[] = $dbByKey[$key];
            } else {
                $rows[] = $this->createPlaceholderRow($key, $entry);
            }
        }

        foreach (Hilos::$db->settings->getOrphans($catalog) as $orphan) {
            $rows[] = $this->settingToRow($orphan);
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures table-level actions (add) and item-level actions (update, delete orphan).
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
     * @return array<string, array<string, mixed>>
     */
    private function buildDbByKey(): array
    {
        $result = [];
        $collection = Hilos::$db->settings;
        $arr = $collection->toArray(idAsIndex: false, toFrontend: true);
        foreach ($arr as $row) {
            $key = $row['key'] ?? null;
            if (is_string($key)) {
                $result[$key] = $row;
            }
        }
        return $result;
    }

    /**
     * Creates a frontend row for a catalog key that has no persisted DB row.
     *
     * @param array<string, mixed> $entry Catalog entry
     * @return array<string, mixed> Placeholder row
     */
    private function createPlaceholderRow(string $key, array $entry): array
    {
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;
        $default = $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null;
        $value = $this->serializeDefault($default, $type);

        return [
            'id' => null,
            'key' => $key,
            'type' => $type,
            'value' => $value,
        ];
    }

    /**
     * Serializes a catalog default value for display in the settings table.
     */
    private function serializeDefault(mixed $value, string $type): ?string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (string)(int)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (string)(int)(bool)$value,
            default => is_scalar($value) ? (string)$value : null,
        };
    }

    /**
     * Converts a persisted setting item to a table row payload.
     *
     * @return array<string, mixed>
     */
    private function settingToRow(ViewSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'key' => $setting->key,
            'type' => $setting->type,
            'value' => $setting->value,
        ];
    }
}
