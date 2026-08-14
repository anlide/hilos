<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Backup\Exception\AnonymizationConfigException;

/**
 * AnonymizationCoverageValidator - the preflight gate between a PII registry and an archive.
 *
 * Runs after the archive is unpacked and before the first import, which is the last moment
 * where the target database is still untouched. A refusal here costs an operator a rerun;
 * the same problem found one step later costs a database full of production data.
 *
 * One question, and it is the one the feature exists for: is every table of the archive
 * classified? An unclassified table is the shape a leak takes - a migration adds a table,
 * nobody writes a row for it, and its rows ride into staging untouched. There is no
 * override flag, and the cost was accepted knowingly: a new table breaks restore until
 * somebody classifies it.
 *
 * Whether a classified column can actually carry what its strategy produces is a question
 * about the schema the pass writes into, not about the dump text, and it is asked after the
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
    public static function validate(PiiRegistry $registry, array $tablesByConnection): void
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
}
