<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Database\DatabaseException;

/**
 * AnonymizationCompatibilityValidator - the gate between a PII registry and the schema the
 * pass will write into.
 *
 * Runs after the forward migrations and before the first `UPDATE` of any connection, over
 * the live schema {@see LiveSchemaReader} reads. That is later than a refusal would ideally
 * come - the target database already holds the archive's rows by then - and it is the
 * earliest moment the question can be answered honestly: between the dump and the pass sit
 * an import and every migration the code has gained since, and any of them may have
 * narrowed a column or widened a key.
 *
 * Asks whether every classified column can carry what its strategy produces: that the
 * column exists at all, that {@see AnonymizationStrategy::NULLIFY} has a nullable column to
 * write NULL into, that the `fake-*` family has the single primary key it derives from,
 * that the column's type holds characters when the strategy writes them, that the widest
 * value the strategy can write fits the column, and that no column of a UNIQUE index is
 * touched by a strategy that writes the same value over every row. Of a table taken whole
 * by {@see AnonymizationStrategy::PURGE} it asks one question more: whether anything
 * references it with a key that forbids deleting a parent row.
 *
 * Every one of those is a refusal rather than an adjustment. A gate that quietly shortened
 * a mask or widened a column would be deciding, at restore time and without an operator,
 * what a project's data is allowed to look like - and the one case where shortening IS the
 * declared behavior, {@see AnonymizationStrategy::HASH}, says so in the strategy itself.
 *
 * A registry row naming a table the live schema does not carry is skipped rather than
 * refused: the archive is judged for coverage before the import ({@see
 * AnonymizationCoverageValidator}), and a declaration that runs ahead of - or behind - the
 * tables of one particular installation says nothing about the data actually restored.
 *
 * All findings are collected before the throw, for the reason the coverage gate collects
 * them: an operator meeting one complaint per restore learns to dread the gate.
 */
final class AnonymizationCompatibilityValidator
{
    /**
     * MySQL types that hold characters, and are therefore the ones a written replacement
     * can go into. `enum` and `set` are absent on purpose: they hold characters but only
     * the ones they list, so a mask is refused by the column rather than truncated by it.
     */
    private const array TEXTUAL_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    /**
     * Hex characters a truncated hash must keep to stay unique in practice. 32 of them are
     * 128 bits, which is the width the birthday bound stops being an argument at for any
     * row count a database holds; below it a UNIQUE index is a coin flip the restore
     * cannot afford, because it fails mid-pass over a database already holding the data.
     */
    private const int UNIQUE_HASH_MIN_LENGTH = 32;

    /**
     * Printed in place of one rule name when the keys holding a table back do not agree on
     * one. Naming both is the whole answer: they are the two rules InnoDB checks at once,
     * and which of them stopped the `DELETE` first changes nothing for the reader.
     */
    private const string MIXED_DELETE_RULES = 'RESTRICT/NO ACTION';

    /**
     * Checks a registry against the live schema of one connection.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param int $connectionIndex Connection index the schema belongs to
     * @param array<string, LiveTableSchema> $schemas Live tables of that connection, by name
     * @param callable(string, string): int $maxPrimaryKey Largest primary key a table holds,
     *     by table and key column; the `fake-*` widths are measured against it, because the
     *     width of the key's TYPE would refuse a `varchar(32)` over four-digit ids
     * @throws AnonymizationConfigException When a declared column is absent or cannot carry
     *     its strategy
     * @throws DatabaseException When the caller's reader cannot read a table's largest key
     */
    public static function validate(
        PiiRegistry $registry,
        int $connectionIndex,
        array $schemas,
        callable $maxPrimaryKey,
    ): void {
        $problems = [];
        foreach ($registry->declaredTables($connectionIndex) as $table) {
            $schema = $schemas[$table] ?? null;
            if ($schema === null) {
                continue;
            }
            self::checkTable($registry, $connectionIndex, $schema, $maxPrimaryKey, $problems);
            self::checkUniqueIndexes($registry, $connectionIndex, $schema, $problems);
            self::checkPurgeKeys($registry, $connectionIndex, $schema, $problems);
        }

        if ($problems !== []) {
            throw new AnonymizationConfigException(
                'The PII registry does not fit the restored schema: ' . implode('; ', $problems),
            );
        }
    }

    /**
     * Checks one table's declared columns against its live schema.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param int $connectionIndex Connection index the table belongs to
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param callable(string, string): int $maxPrimaryKey Largest primary key a table holds
     * @param list<string> $problems Findings so far, appended to in place
     * @throws DatabaseException When the caller's reader cannot read the table's largest key
     */
    private static function checkTable(
        PiiRegistry $registry,
        int $connectionIndex,
        LiveTableSchema $schema,
        callable $maxPrimaryKey,
        array &$problems,
    ): void {
        foreach ($registry->strategiesFor($connectionIndex, $schema->table) ?? [] as $column => $strategy) {
            $where = "connection {$connectionIndex}: {$schema->table}.{$column}";
            if (!$schema->hasColumn($column)) {
                $problems[] = "{$where} is declared but the database has no such column";

                continue;
            }
            if ($strategy === AnonymizationStrategy::NULLIFY && !$schema->isNullable($column)) {
                $problems[] = "{$where} is NOT NULL and cannot take ["
                    . AnonymizationStrategy::NULLIFY->value . ']';

                continue;
            }
            if ($strategy->needsPrimaryKey() && $schema->singlePrimaryKey() === null) {
                $problems[] = "{$where} takes [{$strategy->value}], which derives from a single "
                    . 'primary key column, but the table declares '
                    . ($schema->primaryKey === [] ? 'none' : 'a composite one')
                    . '; use [' . AnonymizationStrategy::HASH->value
                    . '] or [' . AnonymizationStrategy::MASK->value . '] instead';

                continue;
            }
            if ($strategy->needsTextualColumn() && !in_array($schema->typeOf($column), self::TEXTUAL_TYPES, true)) {
                $problems[] = "{$where} is [" . (string)$schema->typeOf($column)
                    . "] and cannot hold what [{$strategy->value}] writes; only "
                    . implode(', ', self::TEXTUAL_TYPES) . ' can';

                continue;
            }
            self::checkWidth($schema, $column, $strategy, $maxPrimaryKey, $where, $problems);
        }
    }

    /**
     * Checks that what a strategy writes into one column still fits it.
     *
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param string $column Column being checked
     * @param AnonymizationStrategy $strategy Strategy declared for it
     * @param callable(string, string): int $maxPrimaryKey Largest primary key a table holds
     * @param string $where Opening of a finding about this column
     * @param list<string> $problems Findings so far, appended to in place
     * @throws DatabaseException When the caller's reader cannot read the table's largest key
     */
    private static function checkWidth(
        LiveTableSchema $schema,
        string $column,
        AnonymizationStrategy $strategy,
        callable $maxPrimaryKey,
        string $where,
        array &$problems,
    ): void {
        $length = $schema->lengthOf($column);
        if ($length === null) {
            return;
        }

        if ($strategy === AnonymizationStrategy::HASH) {
            $touched = $schema->uniqueIndexesTouchedBy([$column]);
            if ($length < self::UNIQUE_HASH_MIN_LENGTH && $touched !== []) {
                $problems[] = "{$where} takes [{$strategy->value}] inside UNIQUE ["
                    . implode(', ', array_keys($touched)) . "] and would be cut to {$length} "
                    . 'characters to fit; a UNIQUE index needs at least '
                    . self::UNIQUE_HASH_MIN_LENGTH . ' of them';
            }

            return;
        }

        // The key is read only for the strategies that render it: a mask is the same width
        // in every table, and asking the database for a number nobody uses is a query the
        // restore pays for once per declared column.
        $largestKey = $strategy->needsPrimaryKey()
            ? $maxPrimaryKey($schema->table, (string)$schema->singlePrimaryKey())
            : 0;
        $written = AnonymizationSqlBuilder::substitutionLength($strategy, $largestKey);
        if ($written !== null && $written > $length) {
            $problems[] = "{$where} takes [{$strategy->value}], which writes up to {$written} "
                . "characters into a column of {$length}";
        }
    }

    /**
     * Checks that no UNIQUE index is left with nothing to tell its rows apart by.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param int $connectionIndex Connection index the table belongs to
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param list<string> $problems Findings so far, appended to in place
     */
    private static function checkUniqueIndexes(
        PiiRegistry $registry,
        int $connectionIndex,
        LiveTableSchema $schema,
        array &$problems,
    ): void {
        $repeated = [];
        foreach ($registry->strategiesFor($connectionIndex, $schema->table) ?? [] as $column => $strategy) {
            if (!$strategy->keepsValuesDistinct() && $schema->hasColumn($column)) {
                $repeated[] = $column;
            }
        }
        if ($repeated === []) {
            return;
        }

        foreach ($schema->uniqueIndexesTouchedBy($repeated) as $index => $touchedColumns) {
            $problems[] = "connection {$connectionIndex}: {$schema->table} UNIQUE index [{$index}] on ("
                . implode(', ', $schema->uniqueIndexes[$index]) . ') takes ['
                . AnonymizationStrategy::MASK->value . '] on (' . implode(', ', $touchedColumns)
                . '); one value over every row cannot keep the index unique - use ['
                . AnonymizationStrategy::HASH->value . '] there';
        }
    }

    /**
     * Checks that a table declared purged can be emptied at all.
     *
     * A whole-table purge is a `DELETE`, and an incoming key that forbids deleting a parent
     * row stops it in the middle of the pass, over a database already holding the archive's
     * rows. Only a purge is asked about: a per-column verdict is written by an `UPDATE`,
     * which touches no key.
     *
     * The question is put to the schema and not to the data - a child table that happens to
     * be empty on one installation says nothing about the next restore - and not to the
     * order the registry declares its tables in either, so that whether a configuration is
     * correct cannot depend on the order of lines in a file.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param int $connectionIndex Connection index the table belongs to
     * @param LiveTableSchema $schema Table as the live database declares it
     * @param list<string> $problems Findings so far, appended to in place
     */
    private static function checkPurgeKeys(
        PiiRegistry $registry,
        int $connectionIndex,
        LiveTableSchema $schema,
        array &$problems,
    ): void {
        if (!$registry->isPurged($connectionIndex, $schema->table) || $schema->restrictingKeys === []) {
            return;
        }

        $references = [];
        $rules = [];
        foreach ($schema->restrictingKeys as $key) {
            $references[] = "{$key->childTable}." . implode(',', $key->childColumns) . " ({$key->constraint})";
            $rules[$key->deleteRule] = true;
        }
        $rule = count($rules) === 1 ? $schema->restrictingKeys[0]->deleteRule : self::MIXED_DELETE_RULES;

        $problems[] = "connection {$connectionIndex}: {$schema->table} takes ["
            . AnonymizationStrategy::PURGE->value . '] but ' . implode(', ', $references)
            . " references it ON DELETE {$rule}; a purged parent fails mid-pass over an already"
            . ' restored database - classify it per column instead';
    }
}
