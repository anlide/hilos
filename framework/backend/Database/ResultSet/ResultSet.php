<?php

namespace Hilos\Database\ResultSet;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Collection\EntityCollection;
use mysqli_result;
use RuntimeException;

/**
 * Single result set wrapper
 * Stores raw data from database and provides lazy conversion to Entity collections
 *
 * @implements \Iterator<int|string, array<string, mixed>>
 * @implements \Countable
 */
class ResultSet implements \Iterator, \Countable
{
    /** @var ?mysqli_result Original mysqli result (if not loaded yet) */
    private ?mysqli_result $mysqliResult = null;

    /**
     * Get mysqli_result (for comparison to detect result changes)
     *
     * @return ?mysqli_result
     */
    public function getMysqliResult(): ?mysqli_result
    {
        return $this->mysqliResult;
    }

    /** @var array<int, array<string, mixed>> Raw rows from database (lazy loaded) */
    private array $rows = [];

    /** @var array<string> Column names */
    private array $columns = [];

    /** @var bool Whether rows are already loaded */
    private bool $loaded = false;

    /** @var int Current iterator position */
    private int $position = 0;

    /**
     * Private constructor - use fromMysqliResult() instead
     */
    private function __construct()
    {
    }

    /**
     * Public clone - prevent cloning.
     *
     * Magic methods in PHP must be public to be called.
     * ResultSet instances should not be cloned.
     *
     * @throws RuntimeException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new RuntimeException('ResultSet cannot be cloned');
    }

    /**
     * Create from mysqli_result (lazy loading).
     *
     * @param ?mysqli_result $result mysqli result or null
     * @param bool $resetPointer Reset result pointer to start (false when reusing for streaming)
     * @return self ResultSet instance
     */
    public static function fromMysqliResult(?mysqli_result $result, bool $resetPointer = true): self
    {
        $instance = new self();
        $instance->mysqliResult = $result;

        if ($result !== null) {
            // Get column names immediately
            $fields = mysqli_fetch_fields($result);
            $instance->columns = array_map(fn($field) => $field->name, $fields);

            // Reset result pointer only if requested (default: true for new queries)
            // Set to false when reusing existing ResultSet to preserve position for streaming
            if ($resetPointer) {
                mysqli_data_seek($result, 0);
            }
        }

        return $instance;
    }

    /**
     * Load all rows from mysqli_result (if not loaded yet)
     */
    private function loadRows(): void
    {
        if ($this->loaded) {
            return;
        }

        if ($this->mysqliResult === null) {
            $this->loaded = true;
            return;
        }

        $this->rows = [];
        mysqli_data_seek($this->mysqliResult, 0);

        while ($row = mysqli_fetch_assoc($this->mysqliResult)) {
            $this->rows[] = $row;
        }

        $this->loaded = true;
    }

    /**
     * Get all rows from result set
     * Note: This loads all rows into memory. For large datasets, use row() in a loop instead.
     *
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function rows(): array
    {
        $this->loadRows();
        return $this->rows;
    }

    /**
     * Get single row from result set
     * Advances the internal pointer, so each call returns the next row
     *
     * @return array<string, mixed>|null Associative array or null if no more rows
     */
    public function row(): ?array
    {
        if ($this->loaded) {
            // Use cached rows
            if (!isset($this->rows[$this->position])) {
                return null;
            }
            $row = $this->rows[$this->position];
            $this->position++;
            return $row;
        }

        // Load from mysqli_result on demand
        if ($this->mysqliResult === null) {
            return null;
        }

        $row = mysqli_fetch_assoc($this->mysqliResult);
        if ($row !== null) {
            $this->rows[] = $row;
            $this->position++;
        }

        return $row !== null ? $row : null;
    }

    /**
     * Get number of rows in result set.
     *
     * @return int Row count
     */
    public function count(): int
    {
        if ($this->mysqliResult !== null && !$this->loaded) {
            return mysqli_num_rows($this->mysqliResult);
        }

        return count($this->rows);
    }

    /**
     * Get column names
     *
     * @return array<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get first row
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        if ($this->loaded) {
            return $this->rows[0] ?? null;
        }

        if ($this->mysqliResult === null) {
            return null;
        }

        mysqli_data_seek($this->mysqliResult, 0);
        $row = mysqli_fetch_assoc($this->mysqliResult);
        mysqli_data_seek($this->mysqliResult, 0);

        return $row !== null ? $row : null;
    }

    /**
     * Convert to EntityCollection (only if this is an entity query).
     *
     * @param class-string<Entity> $entityClass Entity class name
     * @param string|int|null $primaryKey Primary key column name for indexing
     * @return EntityCollection
     */
    public function toEntityCollection(string $entityClass, string|int|null $primaryKey = null): EntityCollection
    {
        $collection = EntityCollection::empty();

        if ($this->mysqliResult !== null && !$this->loaded) {
            // Load from mysqli_result on demand
            mysqli_data_seek($this->mysqliResult, 0);

            while ($row = mysqli_fetch_assoc($this->mysqliResult)) {
                $entity = $entityClass::fromRow($row);

                $key = $primaryKey !== null ? ($row[$primaryKey] ?? null) : null;
                $collection->add($entity, $key);
            }
        } else {
            // Use cached rows
            $this->loadRows();

            foreach ($this->rows as $row) {
                $entity = $entityClass::fromRow($row);

                $key = $primaryKey !== null ? ($row[$primaryKey] ?? null) : null;
                $collection->add($entity, $key);
            }
        }

        return $collection;
    }

    /**
     * Get raw rows as array
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->rows();
    }

    /**
     * Reset iterator to beginning.
     */
    public function rewind(): void
    {
        $this->position = 0;

        if ($this->mysqliResult !== null && !$this->loaded) {
            mysqli_data_seek($this->mysqliResult, 0);
        }
    }

    /**
     * Get current row
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        if ($this->loaded) {
            return $this->rows[$this->position] ?? null;
        }

        // For non-loaded mode, we need to track position differently
        // This is a simplified version - in practice, row() should be used
        $this->loadRows();
        return $this->rows[$this->position] ?? null;
    }

    /**
     * Get current key
     *
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Move to next row.
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * Check if current position is valid.
     *
     * @return bool True if current position has row
     */
    public function valid(): bool
    {
        if (!$this->loaded) {
            $this->loadRows();
        }

        return isset($this->rows[$this->position]);
    }
}

