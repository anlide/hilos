<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Item\Setting;
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
     * @throws SettingNotInCatalogException When key is not declared in the settings catalog
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or setting persistence fails
     * @throws DuplicateIdException When the created setting id already exists in the collection
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws CreateNotAllowedException When the truth source rejects settings collection creation
     */
    public function add(string $key, mixed $value, array $catalog): Setting
    {
        $this->ensureCanCreate();

        if (!array_key_exists($key, $catalog)) {
            throw new SettingNotInCatalogException("Setting key '{$key}' is not in catalog");
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

    /**
     * Serializes value to string by type.
     *
     * @param mixed $value Value to serialize
     * @param string $type Type from catalog (string, integer, float, boolean)
     * @return ?string Serialized string or null
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
}
