<?php

namespace Demo\Chat\Database\Object\Collection;

use ArrayAccess;
use Countable;
use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;
use Iterator;
use RuntimeException;

/**
 * Events Object Collection
 */
final class Events extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectEvent[] $objects */
    protected array $objects = [];

    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityEvents = EntityEvents::initFullDB();
        foreach ($EntityEvents as $key => $EntityEvent) {
            $this->objects[$key] = ObjectEvent::fromEntity($EntityEvent);
        }
        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    public static function initEmpty(): static
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    public function current(): ?ObjectEvent
    {
        return parent::current();
    }

    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectEvent) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    public function offsetGet($offset): ?ObjectEvent
    {
        return parent::offsetGet($offset);
    }

    protected function lazyLoadObject(int|string $key): ?ObjectEvent
    {
        $EntityEvent = EntityEvent::getById((int)$key);
        return $EntityEvent !== null ? ObjectEvent::fromEntity($EntityEvent) : null;
    }

    protected function lazyLoadCount(): int
    {
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityEvent::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    protected function lazyLoadAll(): void
    {
        $EntityEvents = EntityEvents::initFullDB();

        foreach ($EntityEvents as $key => $EntityEvent) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectEvent::fromEntity($EntityEvent);
            }
        }

        $this->_allLoaded = true;
    }

    public function getTableName(): string
    {
        return EntityEvent::_table;
    }

    public function getCollectionKey(): string
    {
        return Hilos::events;
    }

    public function deleteAll(): void
    {
        Database::sql("DELETE FROM `event`;");
        $this->objects = [];
    }

    public function add(string $type, ?int $userId = null, ?array $data = null): ObjectEvent
    {
        if ($this->_lazyStrategy === Objects::LAZY_STRATEGY_NONE) {
            $hasTruthSource = \Hilos\Database\Idea\TruthSourceRegistry::hasTruthSource(Hilos::events);

            if (!$hasTruthSource) {
                throw new WriteNotAllowedException("Write operation not allowed: no truth source registered for collection '" . Hilos::events . "'. Register via TruthSourceRegistry::register() first.");
            }

            if (!$this->_allLoaded) {
                $this->loadAllFromDB();
            }
        }

        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = date('Y-m-d H:i:s');
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new RuntimeException("Failed to save event to database: id is null after sync");
        }

        $this->objects[$objectEvent->id] = $objectEvent;
        return $objectEvent;
    }
}
