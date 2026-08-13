<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\Exception\AnonymizationConfigException;

/**
 * AnonymizationValidator - the preflight gate between a PII registry and an archive.
 *
 * Runs after the archive is unpacked and before the first import, which is the only
 * moment where both the registry and the real schema are in hand and nothing has been
 * overwritten yet. Everything it can say is a refusal, and a refusal here costs an
 * operator a rerun; the same problem found one step later costs a database full of
 * production data.
 *
 * Three questions, and the first is the one the feature exists for:
 *
 * - COVERAGE - is every table of the archive classified? An unclassified table is the
 *   shape a leak takes: a migration adds a table, nobody writes a row for it, and its
 *   rows ride into staging untouched. There is no override flag, and the cost was
 *   accepted knowingly - a new table breaks restore until somebody classifies it.
 * - COLUMNS - does every declared column exist in the archive? Catches a typo and a
 *   registry row left behind by a rename, both of which otherwise anonymize nothing
 *   while looking like they did.
 * - COMPATIBILITY - can the column carry what the strategy produces? `null` needs a
 *   nullable column, and the `fake-*` strategies derive from a single primary key.
 *
 * All findings are collected before the throw rather than reported one per run: the
 * registry is edited by hand, and an operator fixing a first restore's complaint only to
 * meet a second one is how a coverage gate acquires a reputation for being in the way.
 */
final class AnonymizationValidator
{
    /**
     * Checks a registry against the schemas an archive declares.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param array<int, list<ArchiveTableSchema>> $schemasByConnection Archive tables per connection index
     * @throws AnonymizationConfigException When any table is unclassified, or a declared column
     *     is absent or cannot carry its strategy
     */
    public static function validate(PiiRegistry $registry, array $schemasByConnection): void
    {
        $problems = [];
        foreach ($schemasByConnection as $connectionIndex => $schemas) {
            $uncovered = [];
            foreach ($schemas as $schema) {
                if ($registry->strategiesFor($connectionIndex, $schema->table) === null) {
                    $uncovered[] = $schema->table;

                    continue;
                }
                self::checkTable($registry, $connectionIndex, $schema, $problems);
            }

            if ($uncovered !== []) {
                $problems[] = "connection {$connectionIndex}: tables carry no PII declaration: "
                    . implode(', ', $uncovered);
            }
        }

        if ($problems !== []) {
            throw new AnonymizationConfigException(
                'The PII registry does not match the archive: ' . implode('; ', $problems),
            );
        }
    }

    /**
     * Checks one classified table's columns against its archive schema.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param int $connectionIndex Connection index the table belongs to
     * @param ArchiveTableSchema $schema Table as the archive declares it
     * @param list<string> $problems Findings so far, appended to in place
     */
    private static function checkTable(
        PiiRegistry $registry,
        int $connectionIndex,
        ArchiveTableSchema $schema,
        array &$problems,
    ): void {
        foreach ($registry->strategiesFor($connectionIndex, $schema->table) ?? [] as $column => $strategy) {
            $where = "connection {$connectionIndex}: {$schema->table}.{$column}";
            if (!$schema->hasColumn($column)) {
                $problems[] = "{$where} is declared but the archive has no such column";

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
            }
        }
    }
}
