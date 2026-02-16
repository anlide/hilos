<?php

namespace Demo\Chat\Database\Object\Collection;

use ArrayAccess;
use Countable;
use Demo\Chat\Database\Entity\Item\Moderator as EntityModerator;
use Demo\Chat\Database\Entity\Collection\Moderators as EntityModerators;
use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Database\Object\Item\Moderator as ObjectModerator;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Moderators Object Collection
 */
final class Moderators extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectModerator[] $objects */
    protected array $objects = [];

    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityModerators = EntityModerators::initFullDB();
        foreach ($EntityModerators as $key => $EntityModerator) {
            $this->objects[$key] = ObjectModerator::fromEntity($EntityModerator);
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

    public function getTableName(): string
    {
        return EntityModerator::_table;
    }

    public function getCollectionKey(): string
    {
        return Hilos::moderators;
    }

    public function current(): ?ObjectModerator
    {
        return parent::current();
    }

    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectModerator) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    public function offsetGet($offset): ?ObjectModerator
    {
        return parent::offsetGet($offset);
    }

    protected function lazyLoadObject(int|string $key): ?ObjectModerator
    {
        $EntityModerator = EntityModerator::getById((int)$key);
        return $EntityModerator !== null ? ObjectModerator::fromEntity($EntityModerator) : null;
    }

    protected function lazyLoadCount(): int
    {
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityModerator::_table . "`"
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
        $EntityModerators = EntityModerators::initFullDB();

        foreach ($EntityModerators as $key => $EntityModerator) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectModerator::fromEntity($EntityModerator);
            }
        }

        $this->_allLoaded = true;
    }
}
