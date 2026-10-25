<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\TimeConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogOverview;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\ChangeLogTables as EntityChangeLogTables;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use Hilos\Database\Object\Item\ChangeLogTable as ObjectChangeLogTable;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends Objects<ObjectChangeLogTable>
 * @method ObjectChangeLogTable|null current()
 * @method ObjectChangeLogTable|null first()
 * @method ObjectChangeLogTable|null last()
 * @method ObjectChangeLogTable|null get(int|string $key)
 * @method ObjectChangeLogTable|null offsetGet(mixed $offset)
 */
class ChangeLogTables extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogTable::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogTables::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogTables;
    public const bool CACHE_ROWS = false;

    private const string DATABASE_REQUIRED_MESSAGE = 'Journal section reads require a database-backed collection';

    /**
     * @return ChangeLogOverview Exact row count, approximate bytes, and journaled live table names
     * @throws HilosException When the journal or live schema cannot be read
     */
    public function sectionOverview(): ChangeLogOverview
    {
        return $this->preservingConnection(function (): ChangeLogOverview {
            $entries = count(Hilos::$db->changeLogEntries);
            $oldest = Hilos::$db->changeLogEntries->oldestCreatedAt();
            $bytes = ChangeLogDatabase::approximateBytes();

            $live = EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $journaled = array_fill_keys(array_map(
                static fn(string $entityClass): string => $entityClass::_table,
                array_values(JournaledTables::mounted()),
            ), true);
            $journaledNames = array_keys(array_intersect_key($journaled, array_fill_keys($live, true)));
            sort($journaledNames, SORT_STRING);

            return new ChangeLogOverview(
                $entries,
                $bytes,
                $oldest,
                $journaledNames,
                count($live),
            );
        });
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogTableSummary> Every live base table in name order
     * @throws HilosException When journal SQL, live schema, or placement cannot be read
     */
    public function sectionTables(): ChangeLogReadCollection
    {
        return $this->preservingConnection(function (): ChangeLogReadCollection {
            $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
            $tables = $binding->getSqlTableName($this->getTableName());
            $entries = $binding->getSqlTableName(EntityChangeLogEntry::_table);
            $rows = Database::sql(
                "SELECT t.`name`, COUNT(*) AS recent_count FROM {$entries} l"
                . " JOIN {$tables} t ON t.`id` = l.`table_id`"
                . ' WHERE l.`created_at` >= UTC_TIMESTAMP(6) - INTERVAL 24 HOUR GROUP BY t.`id`, t.`name`',
                index: $binding->connectionIndex,
            )->rows();
            $recent = [];
            foreach ($rows as $row) {
                $recent[(string)$row['name']] = (int)$row['recent_count'];
            }
            $rows = Database::sql(
                "SELECT t.`name`, (SELECT MAX(l.`created_at`) FROM {$entries} l"
                . ' WHERE l.`table_id` = t.`id`) AS last_change_at'
                . " FROM {$tables} t",
                index: $binding->connectionIndex,
            )->rows();
            $last = [];
            foreach ($rows as $row) {
                $last[(string)$row['name']] = self::moment($row['last_change_at']);
            }

            $live = EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $framework = array_fill_keys(FrameworkTablesWithoutEntity::tables(), true);
            foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
                $framework[$entityClass::_table] = true;
            }
            $mounted = [];
            foreach (JournaledTables::mounted() as $entityClass) {
                $mounted[$entityClass::_table] = $entityClass;
            }
            $schemas = $mounted === [] ? [] : LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $pii = $mounted === [] ? null : PiiRegistry::collect();

            $summaries = [];
            foreach ($live as $name) {
                $entityClass = $mounted[$name] ?? null;
                $modeCounts = [];
                if ($entityClass !== null) {
                    foreach (JournalColumnPolicy::forTable($entityClass, $schemas[$name], $pii) as $placement) {
                        $mode = $placement->mode->value;
                        $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
                    }
                }
                $summaries[] = new ChangeLogTableSummary(
                    $name,
                    isset($framework[$name]) ? ChangeLogTableOwner::FRAMEWORK : ChangeLogTableOwner::PROJECT,
                    $entityClass !== null,
                    $modeCounts,
                    $entityClass === null ? null : ($recent[$name] ?? 0),
                    $entityClass === null ? null : ($last[$name] ?? null),
                );
            }
            return ChangeLogReadCollection::fromArray($summaries);
        });
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogNoisyColumn> Live journaled noise declarations in table and column order
     * @throws HilosException When live schema, personal-data verdicts, or column placement cannot be read
     */
    public function sectionNoisyColumns(): ChangeLogReadCollection
    {
        return $this->preservingConnection(function (): ChangeLogReadCollection {
            $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $pii = PiiRegistry::collect();
            $mounted = [];
            foreach (JournaledTables::mounted() as $entityClass) {
                $mounted[$entityClass::_table] = $entityClass;
            }

            $noisyColumns = [];
            foreach ($schemas as $table => $schema) {
                $entityClass = $mounted[$table] ?? null;
                if ($entityClass === null) {
                    continue;
                }
                foreach (JournalColumnPolicy::forTable($entityClass, $schema, $pii) as $column => $placement) {
                    if ($placement->mode === JournalColumnMode::NOISE) {
                        $noisyColumns[] = new ChangeLogNoisyColumn($table, $column, $placement->reason);
                    }
                }
            }
            usort($noisyColumns, static fn(ChangeLogNoisyColumn $left, ChangeLogNoisyColumn $right): int =>
                strcmp($left->table, $right->table) ?: strcmp($left->column, $right->column));
            return ChangeLogReadCollection::fromArray($noisyColumns);
        });
    }

    /**
     * @param string $name Live table name
     * @return ?ChangeLogTableDetail Null when no live table has this name
     * @throws HilosException When schema, placement, or trigger files cannot be read
     */
    public function sectionTable(string $name): ?ChangeLogTableDetail
    {
        $summary = null;
        foreach ($this->sectionTables() as $candidate) {
            if ($candidate->name === $name) {
                $summary = $candidate;
                break;
            }
        }
        if ($summary === null) {
            return null;
        }

        return $this->preservingConnection(function () use ($name, $summary): ChangeLogTableDetail {
            $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)[$name];
            $placements = [];
            $types = null;
            if ($summary->journaled) {
                foreach (JournaledTables::mounted() as $entityClass) {
                    if ($entityClass::_table === $name) {
                        $placements = JournalColumnPolicy::forTable($entityClass, $schema, PiiRegistry::collect());
                        break;
                    }
                }
                $types = JournalTriggerColumnTypes::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            }
            $columns = [];
            foreach (array_keys($schema->columns) as $column) {
                $placement = $placements[$column] ?? null;
                $kind = $placement === null ? null : $types->kindFor($schema, $column, $placement->mode);
                $columns[] = new ChangeLogColumn(
                    $column,
                    (string)$schema->typeOf($column),
                    $placement?->mode,
                    $kind === 'key' ? null : $kind,
                    $placement?->reason,
                );
            }
            $triggers = [];
            if ($summary->journaled) {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                    $triggerName = 'hilos_cl_' . $name . '_after_' . strtolower($event);
                    $triggers[] = new ChangeLogTrigger(
                        $triggerName, $event, $name, JournalTriggerFiles::validFrom($triggerName),
                    );
                }
            }
            return new ChangeLogTableDetail($summary, $columns, $triggers);
        });
    }

    /**
     * Names are external catalog keys; array offsets remain global dictionary row ids.
     *
     * @param string $name Live or formerly journaled table name
     * @return ?int Fresh journal dictionary id, or null when absent
     * @throws DatabaseException When the collection is not database-backed or journal SQL fails
     */
    public function sectionIdByName(string $name): ?int
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $row = Database::sql(
            "SELECT `id` FROM {$table} WHERE `name` = ? LIMIT 1",
            [$name],
            index: $binding->connectionIndex,
        )->row();
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * Metadata readers can select primary implicitly; restore the caller even when policy validation fails.
     * Journal queries use their binding's explicit connection index instead.
     *
     * @template T
     * @param callable(): T $read Fresh schema or journal read
     * @return T Typed result of the read
     * @throws DatabaseException When the journal connection is not configured
     */
    private function preservingConnection(callable $read): mixed
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            throw new DatabaseException('Change log database connection is not configured');
        }
        $previous = Database::getCurrentIndex();
        try {
            return $read();
        } finally {
            Database::useConnection($previous);
        }
    }

    /**
     * @param mixed $value Nullable DATETIME(6) returned by SQL
     * @return ?DateTimeImmutable UTC moment with its microseconds intact
     * @throws DatabaseException When SQL returned a malformed moment
     */
    private static function moment(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string)$value, new DateTimeZone(TimeConstants::UTC_TIME_ZONE));
        if ($moment === false) {
            throw new DatabaseException('Journal returned an invalid DATETIME(6)');
        }
        return $moment;
    }
}
