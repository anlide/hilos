<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use Hilos\Database\Exception\PropertyNotAccessibleException;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;

/**
 * @extends Object_<EntityStatusWorkComponent>
 * @property ?int $id Scalar storage field
 * @property int $work_id Scalar storage field
 * @property string $component_key Scalar storage field
 */
class StatusWorkComponent extends Object_
{
    public const string ENTITY_CLASS = EntityStatusWorkComponent::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusWorkComponents::class;

    public const string id = 'id';
    public const string work_id = 'work_id';
    public const string component_key = 'component_key';

    /**
     * @param string $property Scalar storage field
     * @return mixed Stored value or inherited property
     * @throws DatabaseException When the property is unknown
     * @throws PropertyNotAccessibleException When the inherited property is inaccessible
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::work_id => $this->entity->work_id,
            self::component_key => $this->entity->component_key,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Scalar storage field
     * @param mixed $value Value with the declared field type
     * @throws DatabaseException When the property is not writable
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::work_id => $this->entity->work_id = $value,
            self::component_key => $this->entity->component_key = $value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar storage row */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::work_id => $this->entity->work_id,
            self::component_key => $this->entity->component_key,
        ];
    }

    /**
     * Catalog keys are values, not references to persisted component rows.
     *
     * @param list<string> $keys Selected code-catalog keys at the storage boundary
     * @return list<string> Unique sorted keys suitable for comparison or a detached JSON snapshot
     * @throws ValidationException When a key is malformed
     */
    public static function normalizeKeys(array $keys): array
    {
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) > 128 || !preg_match('/^[a-z][a-z0-9_.-]*$/D', $key)) {
                throw new ValidationException('Choose a valid status component.');
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }
}
