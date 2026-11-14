<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StatusObservedComponents as EntityStatusObservedComponents;
use Hilos\Database\Entity\Item\StatusObservedComponent as EntityStatusObservedComponent;
use Hilos\Database\Object\Item\StatusObservedComponent as ObjectStatusObservedComponent;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Lookups over the moments Status components came under observation.
 *
 * @extends Objects<ObjectStatusObservedComponent>
 * @method ObjectStatusObservedComponent|null first()
 * @method ObjectStatusObservedComponent|null last()
 * @method ObjectStatusObservedComponent|null get(int|string $key)
 * @method ObjectStatusObservedComponent|null offsetGet(mixed $offset)
 */
class StatusObservedComponents extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusObservedComponent::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusObservedComponents::class;
    public const string COLLECTION_KEY = HilosDbContext::statusObservedComponents;

    /**
     * @param string $componentKey Status component key
     * @return ?ObjectStatusObservedComponent Persisted observation start, or null when absent
     * @throws HilosException When the lookup cannot be executed
     */
    public function findByComponentKey(string $componentKey): ?ObjectStatusObservedComponent
    {
        foreach ($this->loadByColumn(EntityStatusObservedComponent::component_key, $componentKey) as $id) {
            return $this->objects[$id];
        }
        return null;
    }

    /**
     * A UNIQUE collision observes current storage even when the caller already owns an older snapshot.
     *
     * The ordinary finder answers from the lazy mirror or from a consistent read; neither sees a
     * row another process committed after the caller's snapshot was taken, which is exactly the row
     * a duplicate key just named.
     *
     * @param string $componentKey Contended Status component key
     * @return ?ObjectStatusObservedComponent Committed winner held by this collection, or null when none
     * @throws DatabaseException When the stored row has no id
     * @throws HilosException When the current read cannot be executed
     */
    public function currentByComponentKey(string $componentKey): ?ObjectStatusObservedComponent
    {
        $rows = Database::sql(
            'SELECT * FROM ' . static::entityClass()::getSqlTableName() . ' WHERE `'
                . EntityStatusObservedComponent::component_key . '` = ? LOCK IN SHARE MODE',
            [$componentKey],
            index: static::entityClass()::getDatabase()->connectionIndex,
        )->rows();
        if ($rows === []) {
            return null;
        }
        $entity = static::entityClass()::fromRow($rows[0]);
        $id = $entity->id ?? throw new DatabaseException('Stored Status observation start has no id');
        if (!isset($this->objects[$id])) {
            $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        return $this->objects[$id];
    }
}
