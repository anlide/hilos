<?php

namespace Demo\Chat\Database\Object\Collection;

use ArrayAccess;
use Countable;
use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Collection\ModeratorPromptPieces as EntityModeratorPromptPieces;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Iterator;

/**
 * ModeratorPromptPieces Object Collection
 */
final class ModeratorPromptPieces extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectModeratorPromptPiece[] $objects */
    protected array $objects = [];

    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityModeratorPromptPieces = EntityModeratorPromptPieces::initFullDB();
        foreach ($EntityModeratorPromptPieces as $key => $EntityModeratorPromptPiece) {
            $this->objects[$key] = ObjectModeratorPromptPiece::fromEntity($EntityModeratorPromptPiece);
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
        return EntityModeratorPromptPiece::_table;
    }

    public function getCollectionKey(): string
    {
        return DbChatContext::moderatorPromptPieces;
    }

    public function current(): ?ObjectModeratorPromptPiece
    {
        return parent::current();
    }

    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectModeratorPromptPiece) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    public function offsetGet($offset): ?ObjectModeratorPromptPiece
    {
        return parent::offsetGet($offset);
    }

    protected function lazyLoadObject(int|string $key): ?ObjectModeratorPromptPiece
    {
        $EntityModeratorPromptPiece = EntityModeratorPromptPiece::getById((int)$key);
        return $EntityModeratorPromptPiece !== null ? ObjectModeratorPromptPiece::fromEntity($EntityModeratorPromptPiece) : null;
    }

    protected function lazyLoadCount(): int
    {
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityModeratorPromptPiece::_table . "`"
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
        $EntityModeratorPromptPieces = EntityModeratorPromptPieces::initFullDB();

        foreach ($EntityModeratorPromptPieces as $key => $EntityModeratorPromptPiece) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectModeratorPromptPiece::fromEntity($EntityModeratorPromptPiece);
            }
        }

        $this->_allLoaded = true;
    }
}
