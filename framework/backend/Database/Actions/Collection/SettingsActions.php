<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingKeyInCatalogException;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Item\Setting;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\HilosException;

/**
 * Settings Actions - write operations for Settings collection.
 *
 * Collection-level operations: add (catalog key), and the orphan pair
 * addOrphan/deleteOrphan (non-catalog key) used by test fixtures.
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
     * @param mixed $value Value (null = use catalog default when reading)
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return Setting Created setting Db item
     * @throws SettingNotInCatalogException When key is not declared in the settings catalog
     * @throws SettingInvalidValueException When the key declares a catalog rule the value fails
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or setting persistence fails
     * @throws DuplicateIdException When the created setting id already exists in the collection
     * @throws ObjectGetIdStringNotImplementedException When the created setting has no persisted id
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws CreateNotAllowedException When the truth source rejects settings collection creation
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function add(string $key, mixed $value, array $catalog): Setting
    {
        $this->ensureCanCreate();

        if (!array_key_exists($key, $catalog)) {
            throw new SettingNotInCatalogException("Setting key '{$key}' is not in catalog");
        }

        // Storing null is a row without an override, so it carries no value for a rule to judge.
        if ($value !== null) {
            SettingValueRules::assertValid($key, $value);
        }

        $entry = $catalog[$key];
        $type = $entry[SettingsCatalogConstants::CATALOG_ENTRY_TYPE] ?? SettingsCatalogConstants::TYPE_STRING;

        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = $type;
        $setting->value = $value !== null ? $this->serializeValue($value, $type) : null;
        $setting->sync();

        $this->addObjectToCollection($setting);

        return $this->createDbItemFromObject($setting);
    }

    /**
     * Adds a persisted orphan setting for a key that is NOT in the catalog.
     *
     * Mirror of {@see add()} with an inverted catalog guard: where add() requires the
     * key to be in the catalog, this requires it to be absent, so the written row reads
     * back as a true orphan (valueSource=orphan). Non-idempotent: refuses if a row for
     * the key already exists. The type is caller-supplied (no catalog to derive it from).
     *
     * @param string $key Setting key (must NOT be in catalog)
     * @param string $type Value type (string, integer, float, boolean)
     * @param mixed $value Value (null = stored as null)
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @return Setting Created orphan setting Db item
     * @throws SettingKeyInCatalogException When the key is in the catalog (use add() for catalog keys)
     * @throws SettingTypeMismatchException When the type is not a supported setting type
     * @throws DuplicateValueException When a setting row for the key already exists
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or setting persistence fails
     * @throws DuplicateIdException When the created setting id already exists in the collection
     * @throws ObjectGetIdStringNotImplementedException When the created setting has no persisted id
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws CreateNotAllowedException When the truth source rejects settings collection creation
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function addOrphan(string $key, string $type, mixed $value, array $catalog): Setting
    {
        $this->ensureCanCreate();

        if (array_key_exists($key, $catalog)) {
            throw new SettingKeyInCatalogException("Setting key '{$key}' is in catalog; orphan write refused");
        }
        $this->ensureSupportedType($type);
        if (isset($this->collection[$key])) {
            throw new DuplicateValueException("Orphan setting for key '{$key}' already exists");
        }

        $setting = ObjectSetting::create();
        $setting->key = $key;
        $setting->type = $type;
        $setting->value = $value !== null ? $this->serializeValue($value, $type) : null;
        $setting->sync();

        $this->addObjectToCollection($setting);

        return $this->createDbItemFromObject($setting);
    }

    /**
     * Deletes a persisted orphan setting for a non-catalog key.
     *
     * Inverted catalog guard protects catalog overrides from deletion through this path;
     * the actual removal is delegated to the item's own actions. Non-idempotent: refuses
     * if no row for the key exists.
     *
     * @param string $key Setting key (must NOT be in catalog)
     * @param array<string, array<string, mixed>> $catalog Catalog: key => [type, default_value]
     * @throws SettingKeyInCatalogException When the key is in the catalog (catalog overrides are not orphans)
     * @throws ItemNotFoundForDeleteException When no setting row for the key exists
     * @throws DatabaseException When collection loading or setting deletion fails
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the setting delete
     */
    public function deleteOrphan(string $key, array $catalog): void
    {
        $this->ensureCanWrite();

        if (array_key_exists($key, $catalog)) {
            throw new SettingKeyInCatalogException("Setting key '{$key}' is in catalog; orphan delete refused");
        }
        if (!isset($this->collection[$key])) {
            throw new ItemNotFoundForDeleteException("Orphan setting for key '{$key}' not found");
        }

        $this->collection[$key]->actions->delete();
    }

    /**
     * Ensures a caller-supplied value type is one the settings layer understands.
     *
     * @param string $type Value type to check
     * @throws SettingTypeMismatchException When the type is not a supported setting type
     */
    private function ensureSupportedType(string $type): void
    {
        $supported = match ($type) {
            SettingsCatalogConstants::TYPE_STRING,
            SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::TYPE_FLOAT,
            SettingsCatalogConstants::TYPE_BOOLEAN => true,
            default => false,
        };
        if (!$supported) {
            throw new SettingTypeMismatchException("Setting type '{$type}' is not a supported setting type");
        }
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
