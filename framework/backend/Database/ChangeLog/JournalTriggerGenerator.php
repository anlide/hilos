<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournaledTables;
use Hilos\HilosException;

/** Validates the applied schema and prepares all project service trigger files. */
final class JournalTriggerGenerator
{
    private const array JOURNAL_TABLES = [
        'hilos_change_log_table', 'hilos_change_log_field', 'hilos_change_log_receipt',
        'hilos_change_log', 'hilos_change_log_change', 'hilos_change_log_value',
    ];

    /**
     * The caller can inspect this complete plan without writing files or applying SQL.
     *
     * @return list<JournalTriggerFile> Canonical files and tombstones in table-name order
     * @throws HilosException When migration, schema, placement, or files cannot be read safely
     */
    public static function plan(): array
    {
        $originalIndex = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $status = Migration::getStatus();
            if ($status['failed_migrations'] !== [] || $status['pending_count'] !== 0) {
                throw new DatabaseException('Journal trigger generation requires all migrations applied and none failed');
            }
            $migrationIndex = Migration::getCurrentIndex();
            self::assertJournalDatabase();

            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $mounted = JournaledTables::mounted();
            $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $liveTables = array_fill_keys(EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX), true);
            $types = JournalTriggerColumnTypes::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $pii = PiiRegistry::collect();

            $entities = [];
            foreach ($mounted as $entityClass) {
                if (isset($liveTables[$entityClass::_table])) {
                    $entities[$entityClass::_table] = $entityClass;
                }
            }
            ksort($entities);
            self::assertEntitySchema(array_values($entities));

            $files = [];
            foreach ($entities as $table => $entityClass) {
                $placements = JournalColumnPolicy::forTable($entityClass, $schemas[$table], $pii);
                array_push($files, ...JournalTriggerRenderer::render($schemas[$table], $placements, $types, $migrationIndex));
            }
            foreach (JournalTriggerFiles::existingTables() as $table) {
                if (!isset($entities[$table])) {
                    array_push($files, ...JournalTriggerRenderer::tombstones($table, $migrationIndex));
                }
            }

            usort($files, static fn(JournalTriggerFile $a, JournalTriggerFile $b): int => strcmp($a->name, $b->name));
            return $files;
        } finally {
            Database::useConnection($originalIndex);
        }
    }

    /**
     * @throws DatabaseException When the derived journal schema or its six tables are absent
     */
    private static function assertJournalDatabase(): void
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            throw new DatabaseException('Change log database connection is not configured');
        }
        $configured = Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX)->database;
        if ($configured !== ChangeLogDatabase::configuredName()) {
            throw new DatabaseException("Change log database name does not match the primary database: {$configured}");
        }
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        Database::sql(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );
        $live = array_column(Database::rows(), 'TABLE_NAME');
        foreach (self::JOURNAL_TABLES as $table) {
            if (!in_array($table, $live, true)) {
                throw new DatabaseException("Change log database lacks {$table}");
            }
        }
    }

    /**
     * @param list<class-string<Entity>> $entities Mounted Entity classes for present tables
     * @throws DatabaseException When an Entity differs from the live SQL schema
     */
    private static function assertEntitySchema(array $entities): void
    {
        $mismatches = EntitySchemaAudit::audit($entities, DatabaseConnectionDefaults::PRIMARY_INDEX);
        if ($mismatches !== []) {
            throw new DatabaseException('Journaled Entity schema mismatch: '
                . implode('; ', array_map(static fn($problem): string => $problem->describe(), $mismatches)));
        }
    }
}
