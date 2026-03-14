<?php

namespace Hilos\Database;

use ArrayAccess;
use Countable;
use Hilos\Database\Exception\DatabaseParamsException;
use Iterator;

/**
 * Type-safe collection of SQL parameters.
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
     * Creates collection from array of values (auto-detects types for non-SqlParam).
     *
     * @param array<int, mixed> $values Values (SqlParam preserved, others wrapped with auto())
     * @return self New collection
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
     * Creates empty collection.
     *
     * @return self New empty collection
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Adds parameter to collection.
     *
     * @param SqlParam $param Parameter to add
     * @return self For chaining
     */
    public function add(SqlParam $param): self
    {
        $this->params[] = $param;
        return $this;
    }

    /**
     * Returns all parameter values for bind_param.
     *
     * @return array<int, mixed> Values in order
     */
    public function getValues(): array
    {
        return array_map(fn(SqlParam $p) => $p->value, $this->params);
    }

    /**
     * Returns type string for bind_param (e.g. "iss" for int, string, string).
     *
     * @return string Concatenated type chars
     */
    public function getTypeString(): string
    {
        return implode('', array_map(fn(SqlParam $p) => $p->type, $this->params));
    }

    /**
     * Returns value references for bind_param (kept for future use).
     *
     * @return array<int, mixed> Value references (or copies for readonly)
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

    /**
     * ArrayAccess: checks if parameter exists at offset.
     *
     * @param mixed $offset Index
     * @return bool True if exists
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    /**
     * ArrayAccess: returns parameter at offset.
     *
     * @param mixed $offset Index
     * @return SqlParam Parameter at index
     * @throws DatabaseParamsException When index does not exist
     */
    public function offsetGet(mixed $offset): SqlParam
    {
        if (!isset($this->params[$offset])) {
            throw new DatabaseParamsException("Parameter at index {$offset} does not exist");
        }
        return $this->params[$offset];
    }

    /**
     * ArrayAccess: sets parameter at offset (or appends if offset is null).
     *
     * @param mixed $offset Index or null to append
     * @param mixed $value SqlParam instance
     * @throws DatabaseParamsException When value is not SqlParam
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

    /**
     * ArrayAccess: removes parameter at offset and re-indexes.
     *
     * @param mixed $offset Index to unset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
        $this->params = array_values($this->params); // Re-index
    }

    /**
     * Countable: returns number of parameters.
     *
     * @return int Parameter count
     */
    public function count(): int
    {
        return count($this->params);
    }

    /**
     * Iterator: returns current parameter.
     *
     * @return SqlParam Current parameter
     */
    public function current(): SqlParam
    {
        return $this->params[$this->position];
    }

    /**
     * Iterator: returns current key (position).
     *
     * @return int Current position
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Iterator: advances to next element.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Iterator: resets position to start.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Iterator: checks if current position is valid.
     *
     * @return bool True if valid
     */
    public function valid(): bool
    {
        return isset($this->params[$this->position]);
    }
}

