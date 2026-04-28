<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Settings\Actions\SettingItemActions;
use Demo\Chat\Tables\Settings\Actions\SettingsTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
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
     * Builds a settings table row mutation from a settings DB source event.
     *
     * @param TableSourceEventDTO $event Settings source event
     * @return ?TableRowMutationDTO Settings row mutation, or null when the event does not affect this table
     */
    public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
    {
        if ($event->sourceKey !== HilosDbContext::settings) {
            return null;
        }

        $key = (string)$event->sourceRowKey;
        if ($key === '') {
            return null;
        }

        if ($event->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $key);
        }

        $setting = Hilos::$db->settings->findByKey($key);
        if ($setting === null) {
            return null;
        }

        return $this->mutation(
            $event->mutationType,
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
     * @return array<string, ViewSetting>
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
     */
    private function rowFromCatalogEntry(string $key, array $entry): SettingTableRow
    {
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;
        $default = $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null;
        $value = $this->serializeDefault($default, $type);

        return new SettingTableRow(
            id: null,
            key: $key,
            type: $type,
            value: $value,
        );
    }

    /**
     * Serializes a catalog default value for display in the settings table.
     *
     * @param mixed $value Catalog default value
     * @param string $type Setting type from the catalog
     * @return ?string Serialized display value
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
     * Builds the settings table row from a persisted setting item.
     *
     * @param ViewSetting $setting Persisted setting DB item
     * @return SettingTableRow Settings table row payload
     */
    public function rowFromSetting(ViewSetting $setting): SettingTableRow
    {
        return new SettingTableRow(
            id: $setting->id,
            key: $setting->key,
            type: $setting->type,
            value: $setting->value,
        );
    }
}
