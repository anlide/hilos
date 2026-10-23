<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\Exception\NamedDatabaseException;

/** Immutable indices into this process's current connection configuration. */
final readonly class DatabaseBinding
{
    /**
     * @param string $name Logical database name
     * @param int $schemaIndex Physical schema configuration index
     * @param int $connectionIndex ORM row and transaction connection index
     */
    public function __construct(
        public string $name,
        public int $schemaIndex,
        public int $connectionIndex,
    ) {
    }

    /**
     * Reads configuration afresh so a binding does not retain a previous test piece's schema name.
     *
     * @return string Physical schema name
     * @throws NamedDatabaseException When the schema index or physical name is not configured
     */
    public function getDatabaseName(): string
    {
        try {
            $database = Database::getConnectionConfig($this->schemaIndex)->database;
        } catch (DatabaseException $failure) {
            throw new NamedDatabaseException($failure->getMessage(), previous: $failure);
        }
        if ($database === '' || str_contains($database, "\0")) {
            throw new NamedDatabaseException("Database '{$this->name}' has no usable physical schema name");
        }

        return $database;
    }

    /**
     * @param string $table Unquoted table identifier
     * @return string Fully quoted physical schema and table
     * @throws NamedDatabaseException When the schema configuration or table identifier is invalid
     */
    public function getSqlTableName(string $table): string
    {
        if ($table === '' || str_contains($table, "\0")) {
            throw new NamedDatabaseException('A table identifier must be nonempty and contain no NUL byte');
        }

        return '`' . str_replace('`', '``', $this->getDatabaseName()) . '`.`' . str_replace('`', '``', $table) . '`';
    }

    /**
     * @return string Unambiguous endpoint and physical schema identity, without credentials or logical names
     * @throws NamedDatabaseException When the physical schema is not configured
     */
    public function getIdentity(): string
    {
        try {
            $config = Database::getConnectionConfig($this->schemaIndex);
        } catch (DatabaseException $failure) {
            throw new NamedDatabaseException($failure->getMessage(), previous: $failure);
        }

        return serialize([$config->host, $config->port, $config->socket, $this->getDatabaseName()]);
    }
}
