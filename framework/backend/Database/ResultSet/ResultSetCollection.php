<?php

namespace Hilos\Database\ResultSet;

use Hilos\Database\Database;
use mysqli;
use mysqli_result;
use RuntimeException;

/**
 * Collection of multiple result sets
 * Used for stored procedures that return multiple result sets
 * Also used as primary way to work with database results (even single result set)
 *
 * @implements \Iterator<int, ResultSet>
 * @implements \Countable
 */
class ResultSetCollection implements \Iterator, \Countable
{
    /** @var ResultSet[] */
    private array $resultSets = [];

    /** @var int Current position */
    private int $position = 0;

    /**
     * Private constructor - use fromDatabase() or empty() instead
     */
    private function __construct()
    {
    }

    /**
     * Public clone - prevent cloning
     *
     * Magic methods in PHP must be public to be called.
     * ResultSetCollection instances should not be cloned.
     */
    public function __clone(): void
    {
        throw new RuntimeException('ResultSetCollection cannot be cloned');
    }

    /**
     * Create empty collection
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create from Database after query execution
     * Only gets current result set (lazy loading - doesn't collect all result sets)
     * This allows for streaming/paginated reading of large datasets
     * Reuses cached ResultSet from Database to preserve pointer position
     *
     * @param int|null $index Connection index
     * @return self
     */
    public static function fromDatabase(?int $index = null): self
    {
        $instance = new self();
        $currentIndex = $index ?? Database::getCurrentIndex();

        // Get cached ResultSet from Database (reuse same instance to preserve pointer position)
        // This allows row() to work correctly with streaming data
        $resultSet = Database::getCachedResultSet($currentIndex);
        if ($resultSet !== null) {
            $instance->add($resultSet);
        }

        // Don't collect remaining result sets here - they should be collected on-demand
        // via Database::nextResult() when needed for multi-query scenarios

        return $instance;
    }

    /**
     * Get current mysqli_result from Database
     *
     * @param int|null $index Connection index
     * @return mysqli_result|null
     */
    private static function getCurrentResult(?int $index = null): ?mysqli_result
    {
        return Database::getCurrentResult($index);
    }

    /**
     * Add result set to collection
     *
     * @param ResultSet $resultSet
     * @return self
     */
    public function add(ResultSet $resultSet): self
    {
        $this->resultSets[] = $resultSet;
        return $this;
    }

    /**
     * Get result set by index
     *
     * @param int $index
     * @return ResultSet|null
     */
    public function get(int $index): ?ResultSet
    {
        return $this->resultSets[$index] ?? null;
    }

    /**
     * Get first result set
     *
     * @return ResultSet|null
     */
    public function first(): ?ResultSet
    {
        return $this->resultSets[0] ?? null;
    }

    /**
     * Get last result set
     *
     * @return ResultSet|null
     */
    public function last(): ?ResultSet
    {
        if (empty($this->resultSets)) {
            return null;
        }
        return $this->resultSets[count($this->resultSets) - 1];
    }

    /**
     * Get count of result sets
     */
    public function count(): int
    {
        return count($this->resultSets);
    }

    /**
     * Check if collection is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->resultSets);
    }

    /**
     * Reset iterator
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Get current result set
     *
     * @return ResultSet|null
     */
    public function current(): ?ResultSet
    {
        return $this->resultSets[$this->position] ?? null;
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
     * Move to next result set
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * Check if current position is valid
     */
    public function valid(): bool
    {
        return isset($this->resultSets[$this->position]);
    }

    /**
     * Get all rows from first result set (convenience method for chaining)
     * Note: This loads all rows into memory. For large datasets, use first()->row() in a loop instead.
     *
     * @return array Array of associative arrays
     */
    public function rows(): array
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return [];
        }
        return $firstResultSet->rows();
    }

    /**
     * Get single row from first result set (convenience method for chaining)
     * Advances the internal pointer, so each call returns the next row
     *
     * @return array|null Associative array or null if no more rows
     */
    public function row(): ?array
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return null;
        }
        return $firstResultSet->row();
    }

    /**
     * Get number of affected rows from last query
     * Useful for UPDATE/DELETE/INSERT queries
     *
     * @return int Number of affected rows
     */
    public function affectedRows(): int
    {
        return Database::affectedRows();
    }

    /**
     * Get number of rows in first result set (convenience method for chaining)
     * Note: count() returns number of result sets in collection.
     * This method returns number of rows in first result set.
     *
     * @return int Number of rows in first result set
     */
    public function rowCount(): int
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return 0;
        }
        return $firstResultSet->count();
    }

    /**
     * Alias for rowCount() - get number of rows in first result set
     * Provided for convenience and consistency with Database::count()
     *
     * @return int Number of rows in first result set
     */
    public function countRows(): int
    {
        return $this->rowCount();
    }

    /**
     * Get single field value from first row of first result set
     * Useful for queries like "SELECT column FROM table LIMIT 1"
     *
     * @param string $fieldName Field name
     * @return mixed Field value or null
     */
    public function field(string $fieldName): mixed
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return null;
        }
        $row = $firstResultSet->row();
        return $row[$fieldName] ?? null;
    }

    /**
     * Get first row from first result set (convenience method)
     * Does not advance the pointer - always returns the first row
     *
     * @return array|null First row or null if empty
     */
    public function firstRow(): ?array
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return null;
        }
        return $firstResultSet->first();
    }

    /**
     * Check if first result set has any rows
     *
     * @return bool True if has rows, false otherwise
     */
    public function exists(): bool
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return false;
        }
        return $firstResultSet->count() > 0;
    }

    /**
     * Check if first result set is empty (has no rows)
     * Note: This is different from isEmpty() which checks if collection has no result sets
     *
     * @return bool True if first result set is empty, false otherwise
     */
    public function isEmptyRows(): bool
    {
        return !$this->exists();
    }

    /**
     * Get array of values from a single column (pluck)
     * Useful for queries like "SELECT id FROM users"
     *
     * @param string $columnName Column name to pluck
     * @return array Array of column values
     */
    public function pluck(string $columnName): array
    {
        $firstResultSet = $this->first();
        if ($firstResultSet === null) {
            return [];
        }
        
        $values = [];
        // Reset to beginning to read all rows
        $firstResultSet->rewind();
        while ($row = $firstResultSet->row()) {
            if (isset($row[$columnName])) {
                $values[] = $row[$columnName];
            }
        }
        return $values;
    }

    /**
     * Get all rows as array (alias for rows() for consistency)
     *
     * @return array Array of associative arrays
     */
    public function toArray(): array
    {
        return $this->rows();
    }

    /**
     * Collect all result sets from multi-query/stored procedure
     * Automatically calls nextResult() until all result sets are collected
     *
     * @return self Returns self for chaining
     */
    public function collectAll(): self
    {
        // Already have first result set from sql()
        // Collect remaining result sets
        while (Database::nextResult()) {
            $resultSet = Database::getCachedResultSet();
            if ($resultSet !== null) {
                $this->add($resultSet);
            }
        }
        return $this;
    }

    /**
     * Get result set by index, automatically collecting if needed
     * If index is beyond current collection, automatically calls nextResult() to collect more
     *
     * @param int $index Result set index (0-based)
     * @return ResultSet|null Result set or null if index is out of bounds
     */
    public function getResultSet(int $index): ?ResultSet
    {
        // If we already have this result set, return it
        if (isset($this->resultSets[$index])) {
            return $this->resultSets[$index];
        }

        // If index is 0 and we have no result sets, try to get first one
        if ($index === 0 && empty($this->resultSets)) {
            $resultSet = Database::getCachedResultSet();
            if ($resultSet !== null) {
                $this->add($resultSet);
                return $resultSet;
            }
        }

        // Collect result sets until we reach the requested index
        while (count($this->resultSets) <= $index) {
            if (!Database::nextResult()) {
                // No more result sets available
                return null;
            }
            $resultSet = Database::getCachedResultSet();
            if ($resultSet !== null) {
                $this->add($resultSet);
            } else {
                // Null result set (e.g., for UPDATE/DELETE), but we still count it
                // Add null to maintain index consistency
                break;
            }
        }

        return $this->resultSets[$index] ?? null;
    }

    /**
     * Get all result sets as array (convenience method)
     * Automatically collects all result sets if not already collected
     *
     * @return ResultSet[] Array of result sets
     */
    public function all(): array
    {
        $this->collectAll();
        return $this->resultSets;
    }
}

