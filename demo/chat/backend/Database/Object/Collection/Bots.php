<?php

namespace Demo\Chat\Database\Object\Collection;

use ArrayAccess;
use Countable;
use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Demo\Chat\Database\Entity\Collection\Bots as EntityBots;
use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Bots Object Collection
 */
final class Bots extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectBot[] $objects */
    protected array $objects = [];

    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityBots = EntityBots::initFullDB();
        foreach ($EntityBots as $key => $EntityBot) {
            $this->objects[$key] = ObjectBot::fromEntity($EntityBot);
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
        return EntityBot::_table;
    }

    public function getCollectionKey(): string
    {
        return Hilos::bots;
    }

    public function current(): ?ObjectBot
    {
        return parent::current();
    }

    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectBot) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    public function offsetGet($offset): ?ObjectBot
    {
        return parent::offsetGet($offset);
    }

    protected function lazyLoadObject(int|string $key): ?ObjectBot
    {
        $EntityBot = EntityBot::getById((int)$key);
        return $EntityBot !== null ? ObjectBot::fromEntity($EntityBot) : null;
    }

    protected function lazyLoadCount(): int
    {
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityBot::_table . "`"
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
        $EntityBots = EntityBots::initFullDB();

        foreach ($EntityBots as $key => $EntityBot) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectBot::fromEntity($EntityBot);
            }
        }

        $this->_allLoaded = true;
    }
}
