<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\DatabaseException;

/** One canonical service trigger file, including its migration header. */
final readonly class JournalTriggerFile
{
    /**
     * @param string $name Trigger and file stem
     * @param string $body One CREATE TRIGGER or DROP TRIGGER statement
     * @param int $migrationIndex First migration for which this body is valid
     * @param bool $tombstone Whether the body removes an obsolete trigger
     */
    public function __construct(
        public string $name,
        public string $body,
        public int $migrationIndex,
        public bool $tombstone,
    ) {
    }

    /**
     * @return string Canonical file content
     */
    public function content(): string
    {
        return "-- valid from migration #{$this->migrationIndex}\n{$this->body}\n";
    }

    /**
     * @param string $database Journal database name
     * @return string One installation-specific statement, without the migration header
     * @throws DatabaseException When the database name is invalid
     */
    public function sql(string $database): string
    {
        return str_replace(JournalTriggerRenderer::DATABASE_TOKEN, ChangeLogDatabase::identifier($database), $this->body);
    }

    /**
     * Compares INFORMATION_SCHEMA coordinates and body, ignoring server-owned metadata such as DEFINER.
     *
     * @param array<string, mixed> $row Raw INFORMATION_SCHEMA.TRIGGERS row
     * @param string $database Journal database name
     * @return bool Whether this active file is already installed exactly
     * @throws DatabaseException When the database name is invalid
     */
    public function matches(array $row, string $database): bool
    {
        if ($this->tombstone || preg_match(
            '/\ACREATE TRIGGER `([^`]+)` AFTER (INSERT|UPDATE|DELETE) ON `([^`]+)`\nFOR EACH ROW (BEGIN\n.*\nEND);\z/sD',
            $this->sql($database),
            $parts,
        ) !== 1) {
            return false;
        }

        return $row['TRIGGER_NAME'] === $parts[1]
            && $row['EVENT_MANIPULATION'] === $parts[2]
            && $row['EVENT_OBJECT_TABLE'] === $parts[3]
            && $row['ACTION_TIMING'] === 'AFTER'
            && $row['ACTION_STATEMENT'] === $parts[4];
    }
}
