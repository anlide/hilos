<?php

namespace Hilos\Database;

use ArrayAccess;
use Countable;
use Hilos\Database\Exception\DatabaseParamsException;
use Iterator;

/**
 * Type-safe collection of SQL parameters
 * 
 * @implements ArrayAccess<int, SqlParam>
 * @implements Iterator<int, SqlParam>
 */
class SqlParamCollection implements ArrayAccess, Countable, Iterator
{
    /** @var SqlParam[] */
    private array $params = [];
    private int $position = 0;

    /**
     * Create from array of values (auto-detect types)
     */
    public static function fromArray(array $values): self
    {
        $collection = new self();
        foreach ($values as $value) {
            if ($value instanceof SqlParam) {
                $collection->add($value);
            } else {
                $collection->add(SqlParam::auto($value));
            }
        }
        return $collection;
    }

    /**
     * Create empty collection
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Add parameter to collection
     */
    public function add(SqlParam $param): self
    {
        $this->params[] = $param;
        return $this;
    }

    /**
     * Get all parameter values
     */
    public function getValues(): array
    {
        return array_map(fn(SqlParam $p) => $p->value, $this->params);
    }

    /**
     * Get type string for bind_param (e.g., "iss" for int, string, string)
     */
    public function getTypeString(): string
    {
        return implode('', array_map(fn(SqlParam $p) => $p->type, $this->params));
    }

    /**
     * Get references to values (needed for bind_param)
     * Note: This method is not used in current implementation (we use parseSqlWithParams instead)
     * Kept for potential future use with prepared statements
     */
    public function getValueReferences(): array
    {
        $refs = [];
        foreach ($this->params as $param) {
            // Cannot get reference to readonly property, return value instead
            // If needed for bind_param, would need to restructure SqlParam
            $refs[] = $param->value;
        }
        return $refs;
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    /**
     * @throws DatabaseParamsException
     */
    public function offsetGet(mixed $offset): SqlParam
    {
        if (!isset($this->params[$offset])) {
            throw new DatabaseParamsException("Parameter at index {$offset} does not exist");
        }
        return $this->params[$offset];
    }

    /**
     * @throws DatabaseParamsException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!($value instanceof SqlParam)) {
            throw new DatabaseParamsException("Value must be instance of SqlParam");
        }

        if ($offset === null) {
            $this->params[] = $value;
        } else {
            $this->params[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
        $this->params = array_values($this->params); // Re-index
    }

    // Countable implementation
    public function count(): int
    {
        return count($this->params);
    }

    // Iterator implementation
    public function current(): SqlParam
    {
        return $this->params[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->params[$this->position]);
    }
}

