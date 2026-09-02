<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\UnclassifiedLiveSchemaException;

/**
 * AnonymizationCoverageValidator - the gate that asks whether a PII registry covers everything.
 *
 * Two questions, equal in rank and told apart by the name of the call rather than by a
 * parameter: {@see validateArchiveTables()} asks it of the tables an archive carries,
 * {@see validateLiveSchema()} of the tables and columns the live database declares. They
 * differ in what they can see - a dump's text names tables and nothing finer, a live
 * schema names every column - and in the moment they run: the first between the unpacked
 * archive and the first import, the second at the startup of a node that carries backup.
 *
 * The question itself is the one the feature exists for: is everything classified? An
 * unclassified table is the shape a leak takes - a migration adds a table, nobody writes a
 * row for it, and its rows ride into staging untouched - and a column is the same story
 * one size down. There is no override flag, and the cost was accepted knowingly: something
 * new stays refused until somebody classifies it.
 *
 * Whether a classified column can actually carry what its strategy produces is a question
 * about the schema the pass writes into, not about coverage, and it is asked after the
 * forward migrations by {@see AnonymizationCompatibilityValidator}.
 *
 * All findings are collected before the throw rather than reported one per run: the
 * registry is edited by hand, and an operator fixing a first restore's complaint only to
 * meet a second one is how a coverage gate acquires a reputation for being in the way.
 */
final class AnonymizationCoverageValidator
{
    /**
     * Checks that a registry classifies every table an archive carries.
     *
     * @param PiiRegistry $registry Registry this restore run was built with
     * @param array<int, list<string>> $tablesByConnection Archive table names per connection index
     * @throws AnonymizationConfigException When any table carries no PII declaration
     */
    public static function validateArchiveTables(PiiRegistry $registry, array $tablesByConnection): void
    {
        $problems = [];
        foreach ($tablesByConnection as $connectionIndex => $tables) {
            $uncovered = [];
            foreach ($tables as $table) {
                if ($registry->strategiesFor($connectionIndex, $table) === null) {
                    $uncovered[] = $table;
                }
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
     * Checks that a registry classifies every table and every column the live schema declares.
     *
     * A table the registry does not know is named whole and its columns are not listed: one
     * unclassified table would otherwise arrive as forty lines of noise around a single
     * missing verdict. A table declared {@see AnonymizationStrategy::PURGE} is not asked about
     * its columns at all, because none of its rows survives a restore. Every other table owes
     * a verdict on each of its live columns, in one half or the other - a strategy, or the
     * judgement that the column holds nothing personal.
     *
     * The reverse direction - a column the registry declares that the schema does not have -
     * is not asked here: that is compatibility, and {@see AnonymizationCompatibilityValidator}
     * asks it where a restore's forward migrations have already run.
     *
     * @param PiiRegistry $registry Registry collected from this installation's declarations
     * @param array<int, array<string, LiveTableSchema>> $schemasByConnection Live tables by name,
     *     per connection index
     * @throws UnclassifiedLiveSchemaException When any table or column carries no PII verdict
     */
    public static function validateLiveSchema(PiiRegistry $registry, array $schemasByConnection): void
    {
        $problems = [];
        foreach ($schemasByConnection as $connectionIndex => $schemas) {
            $uncoveredTables = [];
            $uncoveredColumns = [];
            foreach ($schemas as $table => $schema) {
                $strategies = $registry->strategiesFor($connectionIndex, $table);
                if ($strategies === null) {
                    $uncoveredTables[] = $table;
                    continue;
                }

                if ($registry->isPurged($connectionIndex, $table)) {
                    continue;
                }

                $judged = array_merge(
                    array_keys($strategies),
                    $registry->notPersonalColumns($connectionIndex, $table) ?? [],
                );
                foreach (array_keys($schema->columns) as $column) {
                    if (!in_array($column, $judged, true)) {
                        $uncoveredColumns[] = "{$table}.{$column}";
                    }
                }
            }

            if ($uncoveredTables !== []) {
                $problems[] = "connection {$connectionIndex}: tables carry no PII verdict: "
                    . implode(', ', $uncoveredTables);
            }

            if ($uncoveredColumns !== []) {
                $problems[] = "connection {$connectionIndex}: columns carry no PII verdict: "
                    . implode(', ', $uncoveredColumns);
            }
        }

        if ($problems !== []) {
            throw new UnclassifiedLiveSchemaException(
                'The live schema is not classified for anonymization: ' . implode('; ', $problems),
            );
        }
    }
}
