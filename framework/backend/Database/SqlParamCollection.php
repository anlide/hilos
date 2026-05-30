<?php

namespace Hilos\Database;

use ArrayAccess;
use Countable;
use Hilos\Database\Exception\DatabaseParamsException;
use Iterator;

/**
 * Type-safe ordered collection of SqlParam values.
 *
 * @implements ArrayAccess<int, SqlParam>
 * @implements Iterator<int, SqlParam>
 */
class SqlParamCollection implements ArrayAccess, Countable, Iterator
{
    /** @var list<SqlParam> Bound parameters */
    private array $params = [];

    /** @var int Iterator position */
    private int $position = 0;

    /**
     * @param list<mixed> $values SqlParam values preserved, others wrapped with auto()
     * @return self New collection
     * @throws DatabaseParamsException When value wrapping produces invalid SqlParam
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
     * @return self Empty collection
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param SqlParam $param Parameter to append
     * @return self Fluent append
     */
    public function add(SqlParam $param): self
    {
        $this->params[] = $param;
        return $this;
    }

    /**
     * @return list<mixed> Bound values in order
     */
    public function getValues(): array
    {
        return array_map(fn(SqlParam $p) => $p->value, $this->params);
    }

    /**
     * @return string Concatenated bind type chars (e.g. iss)
     */
    public function getTypeString(): string
    {
        return implode('', array_map(fn(SqlParam $p) => $p->type, $this->params));
    }

    /**
     * @return list<mixed> Value copies for bind_param compatibility
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
     * @param mixed $offset Parameter index
     * @return bool Whether parameter exists at index
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    /**
     * @param mixed $offset Parameter index
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
     * @param mixed $offset Parameter index to remove
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
        $this->params = array_values($this->params); // Re-index
    }

    /**
     * @return int Parameter count
     */
    public function count(): int
    {
        return count($this->params);
    }

    /**
     * @return SqlParam Current parameter
     */
    public function current(): SqlParam
    {
        return $this->params[$this->position];
    }

    /**
     * @return int Current iterator position
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Iterator position increment.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Iterator position reset.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * @return bool Whether current iterator position is valid
     */
    public function valid(): bool
    {
        return isset($this->params[$this->position]);
    }
}
