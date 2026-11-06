<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\ChangeLog\ChangeLogDatabase;

/** The migrator's line-based DELIMITER reader, shared with read-only restore preparation. */
final class MigrationSqlReader
{
    /**
     * Preserves execution semantics: a delimiter completes a statement only at the end of a line.
     * In particular, several semicolon-separated commands on one line remain one execution unit.
     *
     * @param string $content Raw migration file bytes
     * @return list<string> SQL execution units in file order, with journal identifiers substituted
     * @throws DatabaseException When a required journal database name cannot be resolved
     */
    public static function statements(string $content): array
    {
        if (str_contains($content, '{{change_log_database}}')) {
            $content = str_replace(
                '{{change_log_database}}',
                ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName()),
                $content,
            );
        }
        $delimiter = ';';
        $statement = '';
        $statements = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '--') || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
                $delimiter = trim($matches[1]);
                continue;
            }
            $statement .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $statement = rtrim($statement);
                $statement = $delimiter !== ';'
                    ? substr($statement, 0, -strlen($delimiter)) : rtrim($statement, ';');
                $statement = trim($statement);
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
                $statement = '';
            }
        }
        $statement = trim($statement);
        if (!empty($statement)) {
            $statements[] = $statement;
        }
        return $statements;
    }
}
