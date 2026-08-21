<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

/**
 * LiveTableSchema - one table as the LIVE database declares it after the migrations.
 *
 * Read out of `information_schema` by {@see LiveSchemaReader}, and it is the schema the
 * anonymization pass actually writes into: the archive was dumped from an older code
 * base, and everything between it and the `UPDATE` - the import and the forward
 * migrations - may have narrowed a column, widened a key or renamed a table. Judging the
 * dump's own text would answer about a schema that no longer exists by the time the pass
 * runs.
 *
 * The value carries what the compatibility gate and the pass ask of a column and nothing
 * else: whether it exists and may hold NULL ({@see AnonymizationStrategy::NULLIFY}), what
 * type it is and how many characters it takes (a substitution has to fit, and
 * {@see AnonymizationStrategy::HASH} truncates), the primary key the `fake-*` strategies
 * derive from, and the unique indexes a non-injective substitution would collide inside
 * the moment it touches one of their columns.
 */
final class LiveTableSchema
{
    /**
     * @param string $table Table name as the database spells it
     * @param array<string, bool> $columns Column name to whether it accepts NULL, in ordinal order
     * @param array<string, string> $columnTypes Column name to its `DATA_TYPE`, lower case and
     *     without the parenthesized part: `varchar`, `text`, `json`, `int`, `binary`
     * @param array<string, ?int> $columnLengths Column name to its `CHARACTER_MAXIMUM_LENGTH`;
     *     null for a type that bounds no characters the way `char(n)` does
     * @param list<string> $primaryKey Primary key columns, in key order; empty when the table
     *     declares none
     * @param array<string, list<string>> $uniqueIndexes Unique index name to its columns, in
     *     `SEQ_IN_INDEX` order; the primary key is one of them, under its own name `PRIMARY`
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $columnTypes,
        public readonly array $columnLengths,
        public readonly array $primaryKey,
        public readonly array $uniqueIndexes,
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
     * Returns the declared type of a column.
     *
     * @param string $column Column name
     * @return ?string Type name in lower case, or null when the column does not exist
     */
    public function typeOf(string $column): ?string
    {
        return $this->columnTypes[$column] ?? null;
    }

    /**
     * Returns the number of characters a column bounds its values by.
     *
     * @param string $column Column name
     * @return ?int Character length, or null when the column (or its type) bounds none
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

    /**
     * Returns the unique indexes a rewrite of these columns would reach into.
     *
     * One rewritten column is enough: a strategy that repeats itself collides inside an
     * index the moment two rows shared the rest of its columns, and the pass meets that
     * collision as a `1062` in the middle of an already restored database. The primary key
     * is one of the indexes answered about, under its own name.
     *
     * @param list<string> $columns Columns the pass rewrites in this table
     * @return array<string, list<string>> Name of every touched unique index to the columns
     *     of that index the rewrite reaches, in index order
     */
    public function uniqueIndexesTouchedBy(array $columns): array
    {
        $touched = [];
        foreach ($this->uniqueIndexes as $index => $indexColumns) {
            $shared = array_values(array_intersect($indexColumns, $columns));
            if ($shared !== []) {
                $touched[$index] = $shared;
            }
        }

        return $touched;
    }
}
