<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Item\Setting;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;

/**
 * Settings Actions - write operations for Settings collection.
 *
 * Collection-level operation: add only.
 *
 * @extends DbActions<Setting, ObjectSettings>
 * @property-read DbCollectionSettings $collection
 * @property-read ObjectSettings $objectCollection
 */
final class SettingsActions extends DbActions
{
    /**
     * Adds a new setting. Key must exist in catalog.
     *
     * @param string $key Setting key (must be in catalog)
     * @param mixed $value Value (null = use default_value from catalog)
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return Setting Created setting Db item
     * @throws \InvalidArgumentException If key not in catalog
     */
    public function add(string $key, mixed $value, array $catalog): Setting
    {
        $this->ensureCanCreate();

        if (!array_key_exists($key, $catalog)) {
            throw new \InvalidArgumentException("Setting key '{$key}' is not in catalog");
        }

        $entry = $catalog[$key];
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;
        $defaultValue = $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE] ?? null;

        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = $type;
        $setting->value = $value !== null ? $this->serializeValue($value, $type) : ($defaultValue !== null ? $this->serializeValue($defaultValue, $type) : null);
        $setting->sync();

        $this->addObjectToCollection($setting);

        return $this->createDbItemFromObject($setting);
    }

    private function serializeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (string)(int)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (string)(int)(bool)$value,
            default => is_scalar($value) ? (string)$value : null,
        };
    }
}
