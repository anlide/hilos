<?php

namespace Hilos\Database\Object;

use ArrayAccess;
use Countable;
use Iterator;

/**
 * Abstract base class for Object collections
 * Provides lazy loading support and common collection operations
 *
 * @template T of Object_
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 *
 * @property-read bool allowLazyLoading
 */
abstract class Objects implements Iterator, ArrayAccess, Countable
{
    public const string allowLazyLoading = 'allowLazyLoading';

    /** @var Object_[] */
    protected array $objects = [];

    /** @var bool */
    protected bool $_allowLazyLoading = false;

    /** @var int */
    protected int $index = 0;

    /** @var int */
    private int $backupIndex = 0;

    /**
     * Initialize collection with all objects from database
     *
     * @return self
     */
    abstract public static function initFullDB(): self;

    /**
     * Initialize collection with partial database loading (lazy loading enabled)
     *
     * @return self
     */
    abstract public static function initPartialDB(): self;

    /**
     * Initialize empty collection
     *
     * @return self
     */
    abstract public static function initEmpty(): self;

    /**
     * Reload all objects from database
     */
    abstract public function initAgainFullDB(): void;

    /**
     * Reload with partial database loading (lazy loading enabled)
     */
    public function initAgainPartialDB(): void
    {
        $this->initAgainEmpty();
        $this->_allowLazyLoading = true;
    }

    /**
     * Clear collection
     */
    public function initAgainEmpty(): void
    {
        $this->objects = [];
    }

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    /**
     * Backup current iterator index
     */
    public function backupIndex(): void
    {
        $this->backupIndex = $this->index;
    }

    /**
     * Restore iterator index from backup
     */
    public function restoreIndex(): void
    {
        $this->index = $this->backupIndex;
    }

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Get current object
     *
     * @return Object_
     */
    public function current(): Object_
    {
        return $this->objects[array_keys($this->objects)[$this->index]];
    }

    /**
     * Move to next object
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * Get current key
     *
     * @return string|int
     */
    public function key(): string|int
    {
        return array_keys($this->objects)[$this->index];
    }

    /**
     * Check if current position is valid
     */
    public function valid(): bool
    {
        return isset(array_keys($this->objects)[$this->index]);
    }

    /**
     * Reset iterator
     */
    public function rewind(): void
    {
        $this->index = 0;
    }

    /**
     * Set object at offset
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof Object_) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Check if offset exists
     *
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->objects[$offset]);
    }

    /**
     * Unset object at offset
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->objects[$offset]);
    }

    /**
     * Get object at offset
     *
     * @param mixed $offset
     * @return Object_|null
     */
    public function offsetGet($offset): ?Object_
    {
        return $this->objects[$offset] ?? null;
    }

    /**
     * Get count of objects
     */
    public function count(): int
    {
        return count($this->objects);
    }

    /**
     * Magic getter
     *
     * @param string $property
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function __get(string $property): bool
    {
        return match ($property) {
            self::allowLazyLoading => $this->_allowLazyLoading,
            default => throw new \InvalidArgumentException("Property [{$property}] does not exist on " . static::class),
        };
    }

    /**
     * Convert collection to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_map(function ($object) {
            return $object->toArray();
        }, $this->objects);
    }
}

