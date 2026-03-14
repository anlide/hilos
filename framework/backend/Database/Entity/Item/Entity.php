<?php

namespace Hilos\Database\Entity\Item;

use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\PhpType;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * Base Entity class — represents a row in a database table.
 *
 * Child classes must define:
 * - const string _table — table name
 * - const string|array _primary — primary key column(s)
 * - const array _columns — all column names
 * - const array _types — column types mapping
 * - const array _foreign — foreign key relationships (optional)
 * - const array _indexes — index definitions (optional)
 *
 * @property-read bool $_related
 */
abstract class Entity
{
    // Meta property names
    public const string META_TABLE = '_table';
    public const string META_PRIMARY = '_primary';
    public const string META_COLUMNS = '_columns';
    public const string META_TYPES = '_types';
    public const string META_FOREIGN = '_foreign';
    public const string META_INDEXES = '_indexes';

    // Index definition keys (for _indexes array structure)
    public const string INDEX_COLUMNS = 'columns';
    public const string INDEX_UNIQUE = 'unique';

    // Internal property names
    public const string PROP_RELATED = '_related';

    /** @var bool Whether this entity is related to a database row */
    private bool $_related = false;

    /** @var array<string, mixed> original row data from DB (for change tracking) */
    private array $_originalData = [];

    /**
     * Resets relation status and original data on clone.
     */
    public function __clone()
    {
        $this->_related = false;
        $this->_originalData = [];
    }

    /**
     * Creates entity instance.
     */
    public function __construct()
    {
    }

    /**
     * Save entity to database.
     *
     * @param array<string> $columns Specific columns to save (empty = all changed columns)
     * @return bool Always true on success
     * @throws DatabaseException When database operation fails
     */
    public function save(array $columns = []): bool
    {
        if ($this->_related) {
            $this->saveUpdate($columns);
        } else {
            $this->saveInsert();
        }
        return true;
    }

    /**
     * Save only columns that differ from the original entity state.
     *
     * @param Entity $originalEntity Entity state to compare against
     * @return bool True if saved (or no changes)
     * @throws DatabaseException When database operation fails
     */
    public function saveDiff(Entity $originalEntity): bool
    {
        $changedColumns = [];
        $columns = static::_columns;

        foreach ($columns as $column) {
            if ($this->$column !== $originalEntity->$column) {
                $changedColumns[] = $column;
            }
        }

        if (empty($changedColumns)) {
            return true; // No changes
        }

        return $this->save($changedColumns);
    }

    /**
     * Insert new row and update auto-increment primary key.
     *
     * @throws DatabaseException When SQL execution fails
     */
    private function saveInsert(): void
    {
        $table = static::_table;
        $columns = static::_columns;
        $types = static::_types;

        $insertColumns = [];
        $params = SqlParamCollection::empty();

        foreach ($columns as $column) {
            $value = $this->$column;

            // Skip auto-increment primary keys if null
            $primaryKeys = is_array(static::_primary) ? static::_primary : [static::_primary];
            if (in_array($column, $primaryKeys, true) && $value === null) {
                continue;
            }

            $insertColumns[] = "`{$column}`";
            $params->add(self::createParam($value, $types[$column]));
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $sql = "INSERT INTO `{$table}` (" . implode(', ', $insertColumns) . ") VALUES ({$placeholders})";

        Database::sql($sql, $params);

        // Update primary key if auto-increment
        $primaryKey = is_array(static::_primary) ? static::_primary[0] : static::_primary;
        if ($this->$primaryKey === null) {
            $this->$primaryKey = Database::lastInsertId();
        }

        $this->_related = true;
        $this->_originalData = $this->toArray();
    }

    /**
     * Update existing row.
     *
     * @param array<string> $columns Columns to update (empty = all non-primary columns)
     * @throws DatabaseException When SQL execution fails
     */
    private function saveUpdate(array $columns = []): void
    {
        $table = static::_table;
        $allColumns = static::_columns;
        $types = static::_types;
        $primaryKeys = is_array(static::_primary) ? static::_primary : [static::_primary];

        // Determine which columns to update
        if (empty($columns)) {
            $columns = array_diff($allColumns, $primaryKeys);
        }

        $updateParts = [];
        $params = SqlParamCollection::empty();

        foreach ($columns as $column) {
            $value = $this->$column;
            $updateParts[] = "`{$column}` = ?";
            $params->add(self::createParam($value, $types[$column]));
        }

        // Add WHERE conditions for primary key(s)
        $whereParts = [];
        foreach ($primaryKeys as $primaryKey) {
            $whereParts[] = "`{$primaryKey}` = ?";
            $params->add(self::createParam($this->$primaryKey, $types[$primaryKey]));
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $updateParts) . " WHERE " . implode(' AND ', $whereParts);

        Database::sql($sql, $params);

        $this->_originalData = $this->toArray();
    }

    /**
     * Delete entity from database.
     *
     * @throws DatabaseException If entity is not related to a database row
     */
    public function delete(): void
    {
        if (!$this->_related) {
            throw new DatabaseException("Cannot delete entity that is not related to database");
        }

        $table = static::_table;
        $primaryKeys = is_array(static::_primary) ? static::_primary : [static::_primary];
        $types = static::_types;

        $whereParts = [];
        $params = SqlParamCollection::empty();

        foreach ($primaryKeys as $primaryKey) {
            $whereParts[] = "`{$primaryKey}` = ?";
            $params->add(self::createParam($this->$primaryKey, $types[$primaryKey]));
        }

        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereParts);

        Database::sql($sql, $params);

        $this->_related = false;
        $this->_originalData = [];
    }

    /**
     * Set entity data from database row and mark as related.
     *
     * @param array<string, mixed> $row Database row data
     */
    protected function setRelatedData(array $row): void
    {
        $columns = static::_columns;

        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $this->$column = self::castValue($row[$column], static::_types[$column]);
            }
        }

        $this->_related = true;
        $this->_originalData = $row;
    }

    /**
     * Check if entity is persisted to a database row.
     *
     * @return bool True if entity has DB row
     */
    public function isRelated(): bool
    {
        return $this->_related;
    }

    /**
     * Mark entity as related and snapshot current data as original.
     */
    public function flushRelated(): void
    {
        $this->_related = true;
        $this->_originalData = $this->toArray();
    }

    /**
     * Builds WHERE clause and params from filters.
     *
     * @param array<string, mixed>|string $filters Column => value pairs or raw WHERE clause
     * @param array<int, mixed>|string $filtersParam Bound parameters for raw WHERE clause
     *
     * @return array{string, SqlParamCollection} [whereClause, params]
     */
    private static function buildWhere(array|string $filters = [], array|string $filtersParam = []): array
    {
        $whereClause = '';
        $params = SqlParamCollection::empty();

        if (!empty($filters)) {
            if (is_string($filters)) {
                $whereClause = " WHERE {$filters}";
                if (is_array($filtersParam)) {
                    $params = SqlParamCollection::fromArray($filtersParam);
                }
            } elseif (is_array($filters)) {
                $whereParts = [];
                foreach ($filters as $key => $value) {
                    $whereParts[] = "`{$key}` = ?";
                    $params->add(SqlParam::auto($value));
                }
                if (!empty($whereParts)) {
                    $whereClause = " WHERE " . implode(' AND ', $whereParts);
                }
            }
        }

        return [$whereClause, $params];
    }

    /**
     * Builds ORDER BY clause from orderBy specification.
     *
     * @param array<string, string>|string $orderBy Column => direction pairs or raw ORDER BY clause
     *
     * @return string ORDER BY clause (with leading space) or empty string
     */
    private static function buildOrderBy(array|string $orderBy = []): string
    {
        if (empty($orderBy)) {
            return '';
        }

        if (is_string($orderBy)) {
            return " ORDER BY {$orderBy}";
        }

        $orderByParts = [];
        foreach ($orderBy as $column => $direction) {
            $direction = strtoupper($direction);
            $orderByParts[] = "`{$column}` {$direction}";
        }

        return !empty($orderByParts) ? " ORDER BY " . implode(', ', $orderByParts) : '';
    }

    /**
     * Build and execute SELECT query with optional filters, ordering and pagination.
     *
     * @param array<string, mixed>|string $filters Column => value pairs or raw WHERE clause
     * @param array<int, mixed>|string $filtersParam Bound parameters for raw WHERE clause
     * @param array<string, string>|string $orderBy Column => direction pairs or raw ORDER BY clause
     * @param int $limit Row limit (TableConstants::NO_LIMIT = all rows)
     * @param int $offset Zero-based offset
     *
     * @return EntityCollection Entity collection
     * @throws DatabaseException When SQL execution fails
     */
    private static function getEntities(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = TableConstants::NO_LIMIT,
        int $offset = 0,
    ): EntityCollection {
        $table = static::class::_table;

        [$whereClause, $params] = self::buildWhere($filters, $filtersParam);
        $orderByClause = self::buildOrderBy($orderBy);

        $limitClause = '';
        if ($limit !== TableConstants::NO_LIMIT) {
            $limitClause = " LIMIT {$limit} OFFSET {$offset}";
        }

        $sql = "SELECT * FROM `{$table}`{$whereClause}{$orderByClause}{$limitClause}";

        $resultSetCollection = Database::sql($sql, $params);
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return EntityCollection::empty();
        }

        $primaryKey = is_array(static::class::_primary) ? static::class::_primary[0] : static::class::_primary;
        return $firstResultSet->toEntityCollection(static::class, $primaryKey);
    }

    /**
     * Get entities matching the given filters.
     *
     * Child classes should override the return type via @method PHPDoc
     * to return their typed EntityCollection subclass.
     *
     * @param array<string, mixed>|string $filters Column => value pairs or raw WHERE clause
     * @param array<int, mixed>|string $filtersParam Bound parameters for raw WHERE clause
     * @param array<string, string>|string $orderBy Column => direction pairs or raw ORDER BY clause
     * @param int $limit Row limit (TableConstants::NO_LIMIT = all rows)
     * @param int $offset Zero-based offset
     *
     * @return EntityCollection Entity collection
     * @throws DatabaseException When SQL execution fails
     */
    public static function get(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = TableConstants::NO_LIMIT,
        int $offset = 0,
    ): EntityCollection {
        return self::getEntities($filters, $filtersParam, $orderBy, $limit, $offset);
    }

    /**
     * Count entities matching the given filters.
     *
     * @param array<string, mixed>|string $filters Column => value pairs or raw WHERE clause
     * @param array<int, mixed>|string $filtersParam Bound parameters for raw WHERE clause
     *
     * @return int Row count
     * @throws DatabaseException When SQL execution fails
     */
    public static function count(array|string $filters = [], array|string $filtersParam = []): int
    {
        $table = static::class::_table;

        [$whereClause, $params] = self::buildWhere($filters, $filtersParam);

        $sql = "SELECT COUNT(*) as `cnt` FROM `{$table}`{$whereClause}";

        $resultSetCollection = Database::sql($sql, $params);
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int) ($row['cnt'] ?? 0) : 0;
    }

    /**
     * Get entity by primary key value.
     *
     * @param mixed $id Primary key value
     * @return ?static Entity or null if not found
     * @throws DatabaseException When SQL execution fails
     */
    public static function getById(mixed $id): ?static
    {
        $primaryKey = is_array(static::_primary) ? static::_primary[0] : static::_primary;
        $collection = self::get([$primaryKey => $id]);
        return $collection->first();
    }

    /**
     * Create a new entity instance not related to any database row.
     *
     * @return static New entity instance
     */
    public static function getEmpty(): static
    {
        return new static();
    }

    /**
     * Get all entities from the table.
     *
     * @return EntityCollection Entity collection
     * @throws DatabaseException When SQL execution fails
     */
    public static function getAll(): EntityCollection
    {
        return self::get();
    }

    /**
     * Create entity from database row array
     *
     * @param array<string, mixed> $row Database row data
     * @return static
     */
    public static function fromRow(array $row): static
    {
        $entity = new static();
        $entity->setRelatedData($row);
        return $entity;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $name Property name
     * @return mixed Property value
     * @throws DatabaseException When property does not exist
     */
    public function __get(string $name): mixed
    {
        if ($name === self::PROP_RELATED) {
            return $this->_related;
        }

        if (property_exists($this, $name)) {
            return $this->$name;
        }

        throw new DatabaseException("Property {$name} does not exist on " . static::class);
    }

    /**
     * Convert entity to associative array of column => value pairs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        foreach (static::_columns as $column) {
            $data[$column] = $this->$column;
        }
        return $data;
    }

    /**
     * Create SqlParam from PHP value and PHP type
     * Converts PHP value to SqlParam for database operations
     *
     * @param mixed $value PHP value
     * @param string $type PHP type (from _types array: 'integer', 'string', 'boolean', etc.)
     * @return SqlParam Parameter ready for database query
     */
    private static function createParam(mixed $value, string $type): SqlParam
    {
        if ($value === null) {
            return SqlParam::auto(null);
        }

        return match ($type) {
            PhpType::INTEGER->value => SqlParam::int((int)$value),
            PhpType::FLOAT->value, PhpType::DECIMAL->value, PhpType::DOUBLE->value => SqlParam::double((float)$value),
            PhpType::BOOLEAN->value => SqlParam::bool((bool)$value),
            default => SqlParam::string((string)$value),
        };
    }

    /**
     * Cast database value to PHP type
     * Converts value from database to appropriate PHP type
     *
     * @param mixed $value Value from database
     * @param string $type PHP type (from _types array: 'integer', 'string', 'boolean', etc.)
     * @return mixed Value cast to PHP type
     */
    private static function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            PhpType::INTEGER->value => (int)$value,
            PhpType::FLOAT->value, PhpType::DECIMAL->value, PhpType::DOUBLE->value => (float)$value,
            PhpType::BOOLEAN->value => (bool)$value,
            PhpType::STRING->value, PhpType::TEXT->value => (string)$value,
            // datetime, date, time, json, binary are handled as strings but not explicitly cast
            // to preserve their original format from database
            default => $value,
        };
    }
}
