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
 * Settings table data source: merges catalog (PHP config) with DB rows.
 *
 * For each catalog key: uses DB row if exists, else placeholder (id=null, default value).
 * Appends orphan rows (DB keys not in catalog).
 */
final class SettingsTableDataSource implements TableDataSourceInterface
{
    public function getType(): TableType
    {
        return TableType::Entity;
    }

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

    private function serializeDefault(mixed $value, string $type): ?string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (string)(int)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (string)(int)(bool)$value,
            default => is_scalar($value) ? (string)$value : null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function settingToRow(\Hilos\Database\View\Item\Setting $setting): array
    {
        return [
            'id' => $setting->id,
            'key' => $setting->key,
            'type' => $setting->type,
            'value' => $setting->value,
        ];
    }
}
