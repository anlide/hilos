<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogOverview;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Database\View\Collection\ChangeLogEntries;
use Hilos\Hilos;
use Hilos\HilosException;

/** Reads the journal overview, live tables, and table history for admin pages. */
final class ChangeLogSectionReader
{
    public const int MAX_WINDOW_ROWS = ChangeLogEntries::MAX_WINDOW_ROWS;

    private const array JOURNAL_TABLES = [
        'hilos_change_log_table', 'hilos_change_log_field', 'hilos_change_log_receipt',
        'hilos_change_log', 'hilos_change_log_change', 'hilos_change_log_value',
    ];

    private const array INTEGER_KEY_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /**
     * @return ChangeLogOverview Exact row count, approximate bytes, and journaled live table names
     * @throws HilosException When the journal or live schema cannot be read
     */
    public function overview(): ChangeLogOverview
    {
        return $this->onPrimary(function (): ChangeLogOverview {
            $databaseName = ChangeLogDatabase::configuredName();
            $database = ChangeLogDatabase::identifier($databaseName);
            Database::sql("SELECT COUNT(*) AS entry_count FROM {$database}.`hilos_change_log`");
            $entries = (int)Database::field('entry_count');
            Database::sql("SELECT `created_at` FROM {$database}.`hilos_change_log` ORDER BY `id` ASC LIMIT 1");
            $oldest = Database::row();

            $placeholders = implode(', ', array_fill(0, count(self::JOURNAL_TABLES), '?'));
            Database::sql(
                'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS journal_bytes'
                . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . $placeholders . ')',
                array_merge([$databaseName], self::JOURNAL_TABLES),
            );
            $bytes = (int)Database::field('journal_bytes');

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
                self::moment($oldest['created_at'] ?? null),
                $journaledNames,
                count($live),
            );
        });
    }

    /**
     * @return list<ChangeLogTableSummary> Every live base table in name order
     * @throws HilosException When journal SQL, live schema, or placement cannot be read
     */
    public function tables(): array
    {
        return $this->onPrimary(function (): array {
            $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            Database::sql(
                "SELECT t.`name`, COUNT(*) AS recent_count FROM {$database}.`hilos_change_log` l"
                . " JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
                . ' WHERE l.`created_at` >= UTC_TIMESTAMP(6) - INTERVAL 24 HOUR GROUP BY t.`id`, t.`name`',
            );
            $recent = [];
            foreach (Database::rows() as $row) {
                $recent[(string)$row['name']] = (int)$row['recent_count'];
            }
            Database::sql(
                "SELECT t.`name`, (SELECT MAX(l.`created_at`) FROM {$database}.`hilos_change_log` l"
                . ' WHERE l.`table_id` = t.`id`) AS last_change_at'
                . " FROM {$database}.`hilos_change_log_table` t",
            );
            $last = [];
            foreach (Database::rows() as $row) {
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
            return $summaries;
        });
    }

    /**
     * @return list<ChangeLogNoisyColumn> Live journaled noise declarations in table and column order
     * @throws HilosException When live schema, personal-data verdicts, or column placement cannot be read
     */
    public function noisyColumns(): array
    {
        return $this->onPrimary(function (): array {
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
            return $noisyColumns;
        });
    }

    /**
     * @param string $name Live table name
     * @return ?ChangeLogTableDetail Null when no live table has this name
     * @throws HilosException When schema, placement, or trigger files cannot be read
     */
    public function table(string $name): ?ChangeLogTableDetail
    {
        $summary = null;
        foreach ($this->tables() as $candidate) {
            if ($candidate->name === $name) {
                $summary = $candidate;
                break;
            }
        }
        if ($summary === null) {
            return null;
        }

        return $this->onPrimary(function () use ($name, $summary): ChangeLogTableDetail {
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
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person, and period filters
     * @param bool $newestFirst True for descending source time
     * @param ?ChangeLogHistoryAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested rows, capped at MAX_WINDOW_ROWS
     * @param int $skip Rows to skip from the chosen edge when there is no anchor
     * @return list<ChangeLogHistoryRow> Journal rows in the chosen order
     * @throws InvalidArgumentException When the record key is invalid or a skip is used with an anchor
     * @throws HilosException When journal, schema, or person SQL fails
     */
    public function historyWindow(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ?ChangeLogHistoryAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): array {
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Change log skip requires an edge');
        }
        $skip = max(0, $skip);
        if ($skip > TableConstants::COUNT_CEILING) {
            return [];
        }
        return $this->onPrimary(function () use ($table, $filter, $newestFirst, $anchor, $direction, $limit, $skip): array {
            $tableId = $this->journalTableId($table);
            $limit = $this->boundedLimit($limit);
            if ($tableId === null || $limit === 0) {
                return [];
            }
            $params = [];
            $where = $this->historyWhere($tableId, $table, $filter, $params);
            $cursor = $this->historyAnchorClause($anchor, $newestFirst, $direction, $params);
            $descending = $newestFirst === ($direction === TableAnchorDirection::After);
            $order = $descending ? 'DESC' : 'ASC';
            $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            Database::sql(
                "SELECT l.`id` FROM {$database}.`hilos_change_log` l"
                . " WHERE {$where}{$cursor} ORDER BY l.`created_at` {$order}, l.`id` {$order} LIMIT {$limit} OFFSET {$skip}",
                $params,
            );
            $ids = array_map(static fn(array $row): int => (int)$row['id'], Database::rows());
            if ($direction === TableAnchorDirection::Before) {
                $ids = array_reverse($ids);
            }
            $entries = Hilos::$db->changeLogEntries->sectionEntriesByIds($ids, false);
            $receiptIds = [];
            foreach ($entries as $entry) {
                if ($entry->receiptId !== null) {
                    $receiptIds[$entry->receiptId] = true;
                }
            }
            $headers = Hilos::$db->changeLogReceipts->sectionHeadersByIds(array_keys($receiptIds));
            $rows = [];
            foreach ($ids as $id) {
                $entry = $entries->get($id);
                $rows[] = new ChangeLogHistoryRow(
                    $entry,
                    $entry->receiptId === null ? null : $headers->get($entry->receiptId),
                );
            }
            return $rows;
        });
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person, and period filters
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Number of matching rows, at most ceiling plus one
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal or schema SQL fails
     */
    public function historyCount(
        string $table,
        ChangeLogHistoryFilter $filter,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->onPrimary(function () use ($table, $filter, $ceiling): int {
            $tableId = $this->journalTableId($table);
            return $tableId === null ? 0 : $this->boundedHistoryCount($tableId, $table, $filter, null, true, $ceiling);
        });
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person, and period filters
     * @param bool $newestFirst Chosen history order
     * @param ChangeLogHistoryAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Number of rows preceding the anchor in the chosen order
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal or schema SQL fails
     */
    public function historyCountBefore(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ChangeLogHistoryAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->onPrimary(function () use ($table, $filter, $newestFirst, $anchor, $ceiling): int {
            $tableId = $this->journalTableId($table);
            return $tableId === null ? 0
                : $this->boundedHistoryCount($tableId, $table, $filter, $anchor, $newestFirst, $ceiling);
        });
    }

    /**
     * @param string $table Dictionary table name
     * @return ?int Journal dictionary id, absent before its first write
     * @throws DatabaseException When dictionary SQL fails
     */
    private function journalTableId(string $table): ?int
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql("SELECT `id` FROM {$database}.`hilos_change_log_table` WHERE `name` = ? LIMIT 1", [$table]);
        $row = Database::row();
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * @param int $tableId Journal dictionary id
     * @param string $table Live table name for primary-key type validation
     * @param ChangeLogHistoryFilter $filter Selected history subset
     * @param list<mixed> $params Appended SQL parameters
     * @return string AND-joined SQL predicate for journal alias l
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When the live schema cannot be read
     */
    private function historyWhere(int $tableId, string $table, ChangeLogHistoryFilter $filter, array &$params): string
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $where = ['l.`table_id` = ?'];
        $params[] = $tableId;
        if ($filter->recordKey !== null) {
            $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $schema = $schemas[$table] ?? null;
            if ($schema === null || count($filter->recordKey) !== count($schema->primaryKey)) {
                throw new InvalidArgumentException("Record key does not match the live primary key of {$table}");
            }
            $keys = [];
            foreach ($schema->primaryKey as $position => $column) {
                $value = $filter->recordKey[$position];
                if (in_array($schema->typeOf($column), self::INTEGER_KEY_TYPES, true)) {
                    $number = self::decimalInteger((string)$value);
                    if ($number === null) {
                        throw new InvalidArgumentException("Record key {$table}.{$column} is not an integer");
                    }
                    $keys[] = $number;
                } else {
                    $keys[] = (string)$value;
                }
            }
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $where[] = "l.`record_key_hash` = UNHEX(SHA2(JSON_ARRAY({$placeholders}), 256))";
            array_push($params, ...$keys);
        }
        if ($filter->field !== null) {
            $where[] = "l.`mutation_type` = 'update' AND EXISTS (SELECT 1"
                . " FROM {$database}.`hilos_change_log_change` ch"
                . " JOIN {$database}.`hilos_change_log_field` f ON f.`id` = ch.`field_id`"
                . ' WHERE ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at` AND f.`name` = ?)';
            $params[] = $filter->field;
        }
        if ($filter->who !== null) {
            $whoCondition = self::personCondition($filter->who, $params);
            $where[] = "EXISTS (SELECT 1 FROM {$database}.`hilos_change_log_receipt` r"
                . " WHERE r.`id` = l.`receipt_id` AND {$whoCondition})";
        }
        self::appendPeriod('l', $filter->since, $filter->until, $where, $params);
        return implode(' AND ', $where);
    }

    /**
     * @param ?ChangeLogHistoryAnchor $anchor Complete journal row key
     * @param bool $newestFirst Chosen canonical order
     * @param TableAnchorDirection $direction Side of the key in that order
     * @param list<mixed> $params Appended SQL parameters
     * @return string Predicate including leading AND, or empty at an edge
     */
    private function historyAnchorClause(
        ?ChangeLogHistoryAnchor $anchor,
        bool $newestFirst,
        TableAnchorDirection $direction,
        array &$params,
    ): string {
        if ($anchor === null) {
            return '';
        }
        $moment = self::sqlMoment($anchor->createdAt);
        array_push($params, $moment, $moment, $anchor->id);
        $afterIsOlder = $newestFirst === ($direction === TableAnchorDirection::After);
        $operator = $afterIsOlder ? '<' : '>';
        return " AND (l.`created_at` {$operator} ?"
            . " OR (l.`created_at` = ? AND l.`id` {$operator} ?))";
    }

    /**
     * @param int $tableId Journal dictionary id
     * @param string $table Live table name for primary-key type validation
     * @param ChangeLogHistoryFilter $filter Selected history subset
     * @param ?ChangeLogHistoryAnchor $anchor Count rows preceding this key
     * @param bool $newestFirst Chosen canonical order
     * @param int $ceiling Exact-count ceiling
     * @return int Count capped at ceiling plus one
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal or live schema SQL fails
     */
    private function boundedHistoryCount(
        int $tableId,
        string $table,
        ChangeLogHistoryFilter $filter,
        ?ChangeLogHistoryAnchor $anchor,
        bool $newestFirst,
        int $ceiling,
    ): int {
        $params = [];
        $where = $this->historyWhere($tableId, $table, $filter, $params);
        $cursor = $this->historyAnchorClause($anchor, $newestFirst, TableAnchorDirection::Before, $params);
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql(
            "SELECT COUNT(*) AS total_count FROM (SELECT 1 FROM {$database}.`hilos_change_log` l"
            . " WHERE {$where}{$cursor} LIMIT {$limit}) capped",
            $params,
        );
        return (int)Database::field('total_count');
    }

    /**
     * @param string $who Trimmed numeric id or name substring
     * @param list<mixed> $params Appended SQL parameters
     * @return string Predicate over receipt alias r
     */
    private static function personCondition(string $who, array &$params): string
    {
        if (preg_match('/\A#?[0-9]+\z/D', $who) === 1) {
            $digits = $who[0] === '#' ? substr($who, 1) : $who;
            $id = self::decimalInteger($digits) ?? -1;
            array_push($params, $id, $id);
            return '(r.`actor_user_id` = ? OR r.`subject_user_id` = ?)';
        }
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $who) . '%';
        $params[] = $pattern;
        return "EXISTS (SELECT 1 FROM `hilos_user` u WHERE u.`id` IN (r.`actor_user_id`, r.`subject_user_id`)"
            . " AND u.`name` LIKE ? ESCAPE '!')";
    }

    /**
     * @param string $text Signed decimal digits, possibly padded with zeroes
     * @return ?int Integer value, or null for malformed or overflowing input
     */
    private static function decimalInteger(string $text): ?int
    {
        $negative = str_starts_with($text, '-');
        $digits = $negative ? substr($text, 1) : $text;
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }
        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            return 0;
        }
        if ($negative) {
            $normalized = '-' . $normalized;
        }
        $number = filter_var($normalized, FILTER_VALIDATE_INT);
        return $number === false ? null : $number;
    }

    /**
     * @param string $alias SQL source alias selected by this reader
     * @param ?DateTimeImmutable $since Inclusive lower bound
     * @param ?DateTimeImmutable $until Exclusive upper bound
     * @param list<string> $where Appended SQL predicates
     * @param list<mixed> $params Appended SQL parameters
     */
    private static function appendPeriod(
        string $alias,
        ?DateTimeImmutable $since,
        ?DateTimeImmutable $until,
        array &$where,
        array &$params,
    ): void {
        if ($since !== null) {
            $where[] = "{$alias}.`created_at` >= ?";
            $params[] = self::sqlMoment($since);
        }
        if ($until !== null) {
            $where[] = "{$alias}.`created_at` < ?";
            $params[] = self::sqlMoment($until);
        }
    }

    /**
     * @param DateTimeImmutable $moment Moment in any timezone
     * @return string DATETIME(6) in UTC
     */
    private static function sqlMoment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /**
     * @param int $requestedRows Requested number of rows
     * @return int Nonnegative limit capped for an admin window
     */
    private function boundedLimit(int $requestedRows): int
    {
        return max(0, min($requestedRows, self::MAX_WINDOW_ROWS));
    }

    /**
     * @param callable(): mixed $read One SQL read on the primary connection
     * @return mixed Typed result of the read
     * @throws DatabaseException When the journal connection is not configured
     */
    private function onPrimary(callable $read): mixed
    {
        if (!in_array(ChangeLogDatabase::CONNECTION_INDEX, Database::getConfiguredIndices(), true)) {
            throw new DatabaseException('Change log database connection is not configured');
        }
        $previous = Database::getCurrentIndex();
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
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
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string)$value, new DateTimeZone('UTC'));
        if ($moment === false) {
            throw new DatabaseException('Journal returned an invalid DATETIME(6)');
        }
        return $moment;
    }
}
