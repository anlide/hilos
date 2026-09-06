<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\SqlSortDirection;

/**
 * Axis INDEX of the entity schema audit: holds an Entity's _indexes against the live secondary
 * indexes, by name, by ordered columns with their directions, and by uniqueness.
 *
 * Split out of {@see EntitySchemaAudit} because it is the one axis that needs no database of its
 * own: it is handed the declaration and the `information_schema.STATISTICS` rows and answers from
 * those alone. That is what lets a unit test ask it about a descending index without an
 * installation to hold one - which is the only way the direction half of this axis is checkable
 * at all.
 */
final class EntitySchemaIndexAudit
{
    /** `information_schema.STATISTICS.COLLATION` of a column stored in descending order. */
    private const string COLLATION_DESCENDING = 'D';

    /**
     * Compare declared indexes with live ones, both directions, collecting every divergence
     * rather than stopping at the first. PRIMARY and FK-backing indexes take no part: the first
     * has its own axis, the second is the database's own doing.
     *
     * @param class-string<Entity> $entityClass Entity being audited
     * @param string $table The Entity's _table
     * @param array<string, array<string, mixed>> $indexes The Entity's _indexes
     * @param list<array<string, mixed>> $statistics Live `information_schema.STATISTICS` rows
     * @param list<string> $foreignKeyNames Constraint names of the table's foreign keys
     * @return list<EntitySchemaMismatch> Every divergence found on this axis
     * @throws InvalidArgumentException When an index declaration names a direction it cannot name
     */
    public static function audit(
        string $entityClass,
        string $table,
        array $indexes,
        array $statistics,
        array $foreignKeyNames,
    ): array {
        $liveIndexes = self::groupLiveIndexes($statistics, $foreignKeyNames);

        $mismatches = [];
        foreach ($indexes as $name => $definition) {
            $declaredColumns = Entity::indexComponents($definition);
            $declaredUnique = (bool) ($definition[Entity::INDEX_UNIQUE] ?? false);

            if (!isset($liveIndexes[$name])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    'index ' . self::describeColumns($declaredColumns),
                    'missing',
                );
                continue;
            }

            $live = $liveIndexes[$name];
            if ($live['columns'] !== $declaredColumns || $live['unique'] !== $declaredUnique) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    self::describeIndex($declaredColumns, $declaredUnique),
                    self::describeIndex($live['columns'], $live['unique']),
                );
            }
        }

        foreach ($liveIndexes as $name => $live) {
            if (!isset($indexes[$name])) {
                $mismatches[] = new EntitySchemaMismatch(
                    EntitySchemaAxis::INDEX,
                    $entityClass,
                    $table,
                    $name,
                    'declared in _indexes',
                    self::describeIndex($live['columns'], $live['unique']),
                );
            }
        }

        return $mismatches;
    }

    /**
     * Group STATISTICS rows into secondary indexes, dropping PRIMARY and any index that backs a
     * foreign key. A column the database marks `D` is descending; anything else, NULL included,
     * is ascending - a FULLTEXT index marks no direction at all, and reading that as descending
     * would make every one of them a finding.
     *
     * @param list<array<string, mixed>> $statistics Live STATISTICS rows
     * @param list<string> $foreignKeyNames Constraint names of the table's foreign keys
     * @return array<string, array{columns: list<array{column: string, direction: string}>, unique: bool}>
     *     Secondary indexes by name, columns in sequence
     */
    private static function groupLiveIndexes(array $statistics, array $foreignKeyNames): array
    {
        $indexes = [];
        foreach ($statistics as $row) {
            $name = (string) $row[EntitySchemaAudit::COL_INDEX_NAME];
            if ($name === EntitySchemaAudit::INDEX_PRIMARY || in_array($name, $foreignKeyNames, true)) {
                continue;
            }
            $indexes[$name] ??= ['columns' => [], 'unique' => (int) $row[EntitySchemaAudit::COL_NON_UNIQUE] === 0];
            $indexes[$name]['columns'][] = [
                'column' => (string) $row[EntitySchemaAudit::COL_NAME],
                'direction' => $row[EntitySchemaAudit::COL_COLLATION] === self::COLLATION_DESCENDING
                    ? SqlSortDirection::DESC
                    : SqlSortDirection::ASC,
            ];
        }
        return $indexes;
    }

    /**
     * @param list<array{column: string, direction: string}> $columns Index columns in order
     * @param bool $unique Whether the index is unique
     * @return string Human description, e.g. `unique(a,b)` or `(channel,created_at DESC)`
     */
    private static function describeIndex(array $columns, bool $unique): string
    {
        // external-boundary: the neutral element of the signature — a plain index is spelled without the word
        return ($unique ? 'unique' : '') . self::describeColumns($columns);
    }

    /**
     * Spell the columns of an index, naming a direction only where it is descending, so the text
     * of every ascending index reads exactly as it did before directions existed.
     *
     * @param list<array{column: string, direction: string}> $columns Index columns in order
     * @return string Parenthesized column list, e.g. `(channel,created_at DESC)`
     */
    private static function describeColumns(array $columns): string
    {
        $spelled = [];
        foreach ($columns as $component) {
            $spelled[] = $component['direction'] === SqlSortDirection::DESC
                ? "{$component['column']} " . SqlSortDirection::DESC
                : $component['column'];
        }
        return '(' . implode(',', $spelled) . ')';
    }
}
