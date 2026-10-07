<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\HilosException;

/** Reconciles service triggers under the caller's schema rollout claim. */
final class JournalTriggerInstaller
{
    /**
     * Preflights every source file and live trigger before the first DDL. MariaDB does not
     * roll trigger DDL back; a retry converges after a partial failure without touching matches.
     *
     * @throws DatabaseException When preflight, catalog reading, trigger DDL or final verification fails
     */
    public static function apply(): void
    {
        $originalIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            try {
                $files = JournalTriggerFiles::readAll(JournalTriggerGenerator::plan(), Migration::getCurrentIndex());
                $database = ChangeLogDatabase::configuredName();
            } catch (HilosException $e) {
                throw new DatabaseException('Journal trigger preflight failed: ' . $e->getMessage(), previous: $e);
            }
            $live = self::catalog();
            self::refuseUnfiledTriggers($files, $live);

            foreach ($files as $file) {
                try {
                    if ($file->tombstone) {
                        if (isset($live[$file->name])) {
                            Database::sql($file->sql($database));
                        }
                        continue;
                    }
                    if (isset($live[$file->name])) {
                        if ($file->matches($live[$file->name], $database)) {
                            continue;
                        }
                        Database::sql('DROP TRIGGER `' . $file->name . '`');
                    }
                    Database::sql($file->sql($database));
                } catch (DatabaseException $e) {
                    throw new DatabaseException("Journal trigger {$file->name}: " . $e->getMessage(), previous: $e);
                }
            }

            $live = self::catalog();
            self::refuseUnfiledTriggers($files, $live);
            foreach ($files as $file) {
                $row = $live[$file->name] ?? null;
                if ($file->tombstone ? $row !== null : ($row === null || !$file->matches($row, $database))) {
                    throw new DatabaseException("Journal trigger {$file->name}: installed state differs from its file");
                }
            }
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * @return array<string, array<string, mixed>> Raw primary-schema trigger rows keyed by name
     * @throws DatabaseException When the primary trigger catalog cannot be read
     */
    private static function catalog(): array
    {
        try {
            Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
                . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
            return array_column(Database::rows(), null, 'TRIGGER_NAME');
        } catch (DatabaseException $e) {
            throw new DatabaseException('Journal trigger catalog: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @param list<JournalTriggerFile> $files Validated generator files
     * @param array<string, array<string, mixed>> $live Raw primary-schema catalog
     * @throws DatabaseException When any live trigger has no source file
     */
    private static function refuseUnfiledTriggers(array $files, array $live): void
    {
        $unfiled = array_diff(array_keys($live), array_map(static fn(JournalTriggerFile $file): string => $file->name, $files));
        if ($unfiled !== []) {
            throw new DatabaseException('Journal triggers without files: ' . implode(', ', $unfiled));
        }
    }
}
