<?php

namespace Hilos\Database\ResultSet;

use Hilos\Database\Database;
use mysqli;
use mysqli_result;

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
     * Private clone - prevent cloning
     */
    private function __clone()
    {
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
     * Automatically collects all result sets (including first one)
     *
     * @param int|null $index Connection index
     * @return self
     */
    public static function fromDatabase(?int $index = null): self
    {
        $instance = new self();
        $currentIndex = $index ?? Database::getCurrentIndex();

        // Get first result set from Database
        $result = Database::getCurrentResult($currentIndex);
        if ($result !== null) {
            $instance->add(ResultSet::fromMysqliResult($result));
        }

        // Collect remaining result sets from multi-query
        // Use Database::nextResult() which properly handles state
        while (Database::nextResult()) {
            $result = Database::getCurrentResult($currentIndex);
            if ($result !== null) {
                $instance->add(ResultSet::fromMysqliResult($result));
            }
        }

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
}

