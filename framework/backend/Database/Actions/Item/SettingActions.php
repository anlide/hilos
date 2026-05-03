<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\View\Item\Setting;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Item\DbActions;

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
     * @param mixed $value New value
     * @throws ItemNotFoundForUpdateException If setting id is null
     */
    public function updateValue(mixed $value): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Setting not found for update (id is null)');
        }

        $this->object->value = $value !== null ? $this->serializeValue($value, $this->object->type) : null;

        $this->object->sync();
    }

    /**
     * Deletes the setting (including orphans).
     *
     * @throws ItemNotFoundForDeleteException If setting id is null
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Setting not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new \Hilos\Database\Actions\Exception\ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }

    /**
     * Serializes value to string by type.
     *
     * @param mixed $value Value to serialize
     * @param string $type Type from catalog (string, integer, boolean)
     * @return ?string Serialized string or null
     */
    private function serializeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (string)(int)$value,
            SettingsCatalogConstants::TYPE_BOOLEAN => (string)(int)(bool)$value,
            default => is_scalar($value) ? (string)$value : null,
        };
    }
}
