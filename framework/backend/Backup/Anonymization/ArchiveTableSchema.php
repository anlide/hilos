<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

/**
 * ArchiveTableSchema - one table as the archive itself declares it.
 *
 * Read out of the `CREATE TABLE` block of a dump file by {@see ArchiveSchemaReader},
 * never off the Entity classes: a registry row may name a raw table that has no class
 * at all, and reading a class would also mean Reflection, which this project does not
 * use. It is also the honest source - the gate judges what the archive carries, not
 * what the code currently believes.
 *
 * The value is what the two gates need and nothing else: which columns exist and
 * whether each may hold NULL ({@see AnonymizationStrategy::NULLIFY}), how long a string
 * column may be ({@see AnonymizationStrategy::HASH} truncates to it), and the primary
 * key the `fake-*` strategies derive from.
 */
final class ArchiveTableSchema
{
    /**
     * @param string $table Table name as written in the dump
     * @param array<string, bool> $columns Column name to whether it accepts NULL, in declaration order
     * @param array<string, ?int> $columnLengths Column name to its declared character length; null
     *     for a column whose type carries no length the way `char(n)` does
     * @param list<string> $primaryKey Primary key columns, in key order; empty when the table declares none
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $columnLengths,
        public readonly array $primaryKey,
    ) {
    }

    /**
     * Tells whether the table declares a column of this name.
     *
     * @param string $column Column name
     * @return bool Whether the column exists
     */
    public function hasColumn(string $column): bool
    {
        return array_key_exists($column, $this->columns);
    }

    /**
     * Tells whether a column of this table accepts NULL.
     *
     * @param string $column Column name; an unknown column answers false
     * @return bool Whether the column is nullable
     */
    public function isNullable(string $column): bool
    {
        return $this->columns[$column] ?? false;
    }

    /**
     * Returns the declared character length of a column.
     *
     * @param string $column Column name
     * @return ?int Declared length, or null when the column (or its type) carries none
     */
    public function lengthOf(string $column): ?int
    {
        return $this->columnLengths[$column] ?? null;
    }

    /**
     * Returns the single primary-key column the `fake-*` strategies derive from.
     *
     * @return ?string The one primary key column, or null when the key is composite or absent
     */
    public function singlePrimaryKey(): ?string
    {
        return count($this->primaryKey) === 1 ? $this->primaryKey[0] : null;
    }
}
