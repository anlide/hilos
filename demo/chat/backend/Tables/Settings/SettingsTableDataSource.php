<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Settings;

use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Hilos;
use Hilos\Core\Table\DataSource\InMemoryTableFilter;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Core\Table\TableType;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\Setting as ViewSetting;

/**
 * SettingsTableDataSource - Merges catalog (PHP config) with DB rows.
 *
 * For each catalog key: uses DB row if exists, else placeholder (id=null, default value).
 * Appends orphan rows (DB keys not in catalog).
 */
final class SettingsTableDataSource implements TableDataSourceInterface
{
    /**
     * Get table type (Entity).
     *
     * @return TableType Entity type
     */
    public function getType(): TableType
    {
        return TableType::Entity;
    }

    /**
     * Query settings (catalog + DB merge with placeholders and orphans).
     *
     * @param TableQueryDTO $query Table query (sort, limit, etc.)
     * @return TableResultDTO Filtered result
     */
    public function query(TableQueryDTO $query): TableResultDTO
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
     * @param array<string, mixed> $entry Catalog entry
     * @return array<string, mixed> Placeholder row (id=null)
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
     * Serialize default value to string by type.
     *
     * @param mixed $value Default value
     * @param string $type Type (integer, boolean, string)
     * @return ?string Serialized value or null
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
     * Convert setting view item to row.
     *
     * @param ViewSetting $setting Setting view item
     * @return array<string, mixed> Row data
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
