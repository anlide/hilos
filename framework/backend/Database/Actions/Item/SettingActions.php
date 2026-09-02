<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Database\View\Item\Setting;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;

/**
 * Setting Actions - write operations for a single Setting item.
 *
 * Item-level operations: update, delete only.
 *
 * @extends DbActions<Setting, ObjectSetting>
 * @property-read ObjectSetting $object
 */
final class SettingActions extends DbActions
{
    /**
     * Updates setting value.
     *
     * @param mixed $value New value to store (a setting row without a value does not exist)
     * @throws ItemNotFoundForUpdateException When setting object has no persisted id
     * @throws SettingValueRefusedException When the key declares a catalog rule the value fails
     * @throws SettingInvalidValueException When the value is null, or the catalog names a rule that is not one
     * @throws DatabaseException When collection loading or setting persistence fails
     * @throws ObjectCollectionNullException When the setting action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the setting primary key is null during the per-item write check
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the setting write
     */
    public function updateValue(mixed $value): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Setting not found for update (id is null)');
        }

        // A row without a value does not exist: resetting a cataloged key to its default
        // is delete(), so an update always carries a value to check like any other.
        if ($value === null) {
            throw new SettingInvalidValueException(
                "Setting '{$this->object->key}' cannot be stored without a value; delete the row to reset it",
            );
        }
        SettingValueRules::assertValid($this->object->key, $value);

        $this->object->value = $this->serializeValue($value, $this->object->type);

        $this->object->sync();
    }

    /**
     * Deletes the setting (including orphans).
     *
     * @throws ItemNotFoundForDeleteException When setting object has no persisted id
     * @throws ObjectCollectionNullException When the setting action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the setting object cannot expose its id string
     * @throws DatabaseException When collection loading or setting deletion fails
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the setting delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Setting not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
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
