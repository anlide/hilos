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
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\ChangeLog\Section\ChangeLogOverview;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Database\ChangeLog\Section\ChangeLogReceiptHeader;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Database\ChangeLog\Section\ChangeLogTableOwner;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\ChangeLog\Section\ChangeLogTrigger;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedRecord;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedTable;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournaledTables;
use Hilos\HilosException;

/** The single typed SQL reader of the separate journal database for admin pages. */
final class ChangeLogSectionReader
{
    public const int MAX_WINDOW_ROWS = 50;

    private const array JOURNAL_TABLES = [
        'hilos_change_log_table', 'hilos_change_log_field', 'hilos_change_log_receipt',
        'hilos_change_log', 'hilos_change_log_change', 'hilos_change_log_value',
    ];

    private const array INTEGER_KEY_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /**
     * @return ChangeLogOverview Exact row count, approximate bytes, and live table coverage
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

            return new ChangeLogOverview(
                $entries,
                $bytes,
                self::moment($oldest['created_at'] ?? null),
                count(array_intersect_key($journaled, array_fill_keys($live, true))),
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
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ?ChangeLogFeedAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in newest-first order
     * @param int $limit Requested rows, capped at MAX_WINDOW_ROWS
     * @return list<ChangeLogFeedItem> Rows in canonical newest-first order
     * @throws HilosException When journal or person SQL fails
     */
    public function feedWindow(
        ChangeLogFeedFilter $filter,
        ?ChangeLogFeedAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
    ): array {
        return $this->onPrimary(function () use ($filter, $anchor, $direction, $limit): array {
            $limit = $this->boundedLimit($limit);
            if ($limit === 0) {
                return [];
            }
            $params = [];
            $sources = $this->feedSources($filter, $params);
            $anchorClause = $this->feedAnchorClause($anchor, $direction, $params);
            $order = $direction === TableAnchorDirection::After
                ? 'created_at DESC, kind ASC, id DESC' : 'created_at ASC, kind DESC, id ASC';
            Database::sql(
                "SELECT feed.`created_at`, feed.`kind`, feed.`id` FROM ({$sources}) feed"
                . " WHERE 1 = 1{$anchorClause} ORDER BY {$order} LIMIT {$limit}",
                $params,
            );
            $rows = Database::rows();
            if ($direction === TableAnchorDirection::Before) {
                $rows = array_reverse($rows);
            }
            $receiptIds = [];
            $entryIds = [];
            foreach ($rows as $row) {
                if ($row['kind'] === ChangeLogFeedItemKind::RECEIPT->value) {
                    $receiptIds[] = (int)$row['id'];
                } else {
                    $entryIds[] = (int)$row['id'];
                }
            }
            $receipts = $this->receiptsByIds($receiptIds);
            $entries = $this->entriesByIds($entryIds, false);
            $items = [];
            foreach ($rows as $row) {
                $kind = ChangeLogFeedItemKind::from((string)$row['kind']);
                $id = (int)$row['id'];
                $items[] = new ChangeLogFeedItem(
                    $kind,
                    self::moment($row['created_at']),
                    $kind === ChangeLogFeedItemKind::RECEIPT ? $receipts[$id] : null,
                    $kind === ChangeLogFeedItemKind::ENTRY ? $entries[$id] : null,
                );
            }
            return $items;
        });
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Number of matching rows, at most ceiling plus one
     * @throws HilosException When journal SQL fails
     */
    public function feedCount(ChangeLogFeedFilter $filter, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->onPrimary(fn(): int => $this->boundedFeedCount($filter, null, $ceiling));
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ChangeLogFeedAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Number of rows before the anchor in newest-first order
     * @throws HilosException When journal SQL fails
     */
    public function feedCountBefore(
        ChangeLogFeedFilter $filter,
        ChangeLogFeedAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->onPrimary(fn(): int => $this->boundedFeedCount($filter, $anchor, $ceiling));
    }

    /**
     * @param int $id Receipt row number
     * @return ?ChangeLogReceipt Null for a missing or empty receipt
     * @throws HilosException When journal or person SQL fails
     */
    public function receipt(int $id): ?ChangeLogReceipt
    {
        return $this->onPrimary(function () use ($id): ?ChangeLogReceipt {
            $receipt = $this->receiptsByIds([$id])[$id] ?? null;
            return $receipt?->entryCount > 0 ? $receipt : null;
        });
    }

    /**
     * @param int $receiptId Receipt whose journal rows are read
     * @param ?int $afterEntryId Last row number of the preceding window
     * @param int $limit Requested rows, capped at MAX_WINDOW_ROWS
     * @return list<ChangeLogEntry> Full entries in write-time order
     * @throws HilosException When journal SQL fails
     */
    public function receiptEntries(int $receiptId, ?int $afterEntryId, int $limit): array
    {
        return $this->onPrimary(function () use ($receiptId, $afterEntryId, $limit): array {
            $limit = $this->boundedLimit($limit);
            if ($limit === 0) {
                return [];
            }
            $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            $cursor = '';
            $params = [$receiptId];
            if ($afterEntryId !== null) {
                Database::sql(
                    "SELECT `created_at` FROM {$database}.`hilos_change_log`"
                    . ' WHERE `id` = ? AND `receipt_id` = ? LIMIT 1',
                    [$afterEntryId, $receiptId],
                );
                $row = Database::row();
                if ($row === null) {
                    return [];
                }
                $cursor = ' AND (l.`created_at` > ? OR (l.`created_at` = ? AND l.`id` > ?))';
                array_push($params, $row['created_at'], $row['created_at'], $afterEntryId);
            }
            Database::sql(
                "SELECT l.`id` FROM {$database}.`hilos_change_log` l"
                . " WHERE l.`receipt_id` = ?{$cursor} ORDER BY l.`created_at` ASC, l.`id` ASC LIMIT {$limit}",
                $params,
            );
            $ids = array_map(static fn(array $row): int => (int)$row['id'], Database::rows());
            $entries = $this->entriesByIds($ids, true);
            return array_values(array_filter(array_map(static fn(int $id): ?ChangeLogEntry => $entries[$id] ?? null, $ids)));
        });
    }

    /**
     * @param int $entryId Journal row number
     * @return ?ChangeLogEntry Full entry, including long values, or null when absent
     * @throws HilosException When journal SQL fails
     */
    public function entry(int $entryId): ?ChangeLogEntry
    {
        return $this->onPrimary(fn(): ?ChangeLogEntry => $this->entriesByIds([$entryId], true)[$entryId] ?? null);
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person, and period filters
     * @param bool $newestFirst True for descending source time
     * @param ?ChangeLogHistoryAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested rows, capped at MAX_WINDOW_ROWS
     * @return list<ChangeLogHistoryRow> Journal rows in the chosen order
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal, schema, or person SQL fails
     */
    public function historyWindow(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ?ChangeLogHistoryAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
    ): array {
        return $this->onPrimary(function () use ($table, $filter, $newestFirst, $anchor, $direction, $limit): array {
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
                . " WHERE {$where}{$cursor} ORDER BY l.`created_at` {$order}, l.`id` {$order} LIMIT {$limit}",
                $params,
            );
            $ids = array_map(static fn(array $row): int => (int)$row['id'], Database::rows());
            if ($direction === TableAnchorDirection::Before) {
                $ids = array_reverse($ids);
            }
            $entries = $this->entriesByIds($ids, false);
            $receiptIds = [];
            foreach ($entries as $entry) {
                if ($entry->receiptId !== null) {
                    $receiptIds[$entry->receiptId] = true;
                }
            }
            $headers = $this->receiptHeadersByIds(array_keys($receiptIds));
            $rows = [];
            foreach ($ids as $id) {
                $entry = $entries[$id];
                $rows[] = new ChangeLogHistoryRow(
                    $entry,
                    $entry->receiptId === null ? null : ($headers[$entry->receiptId] ?? null),
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
     * @param list<int> $ids Receipt numbers attached to one history window
     * @return array<int, ChangeLogReceiptHeader> Current person labels and receipt attribution
     * @throws DatabaseException When journal or person SQL fails
     */
    private function receiptHeadersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql(
            "SELECT r.`id`, r.`created_at`, r.`actor_user_id`, r.`subject_user_id`, r.`session_id`,"
            . " r.`channel`, r.`action`, r.`agent`, r.`source` FROM {$database}.`hilos_change_log_receipt` r"
            . " WHERE r.`id` IN ({$placeholders})",
            $ids,
        );
        $rows = Database::rows();
        $personIds = [];
        foreach ($rows as $row) {
            foreach (['actor_user_id', 'subject_user_id'] as $field) {
                if ($row[$field] !== null) {
                    $personIds[(int)$row[$field]] = true;
                }
            }
        }
        $people = $this->peopleByIds(array_keys($personIds));
        $headers = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $actorId = $row['actor_user_id'] === null ? null : (int)$row['actor_user_id'];
            $subjectId = $row['subject_user_id'] === null ? null : (int)$row['subject_user_id'];
            $headers[$id] = new ChangeLogReceiptHeader(
                $id,
                self::moment($row['created_at']),
                $actorId === null ? null : $people[$actorId],
                $subjectId === null ? null : $people[$subjectId],
                $row['session_id'] === null ? null : (int)$row['session_id'],
                (string)$row['channel'],
                (string)$row['action'],
                $row['agent'] === null ? null : (string)$row['agent'],
                $row['source'] === null ? null : (string)$row['source'],
            );
        }
        return $headers;
    }

    /**
     * @param ChangeLogFeedFilter $filter Source filters
     * @param list<mixed> $params SQL parameters in UNION source order
     * @return string Bounded feed's receipt and bare-entry sources
     */
    private function feedSources(ChangeLogFeedFilter $filter, array &$params): string
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $receiptParams = [];
        $receiptEntries = "SELECT 1 FROM {$database}.`hilos_change_log` l";
        if ($filter->table !== null) {
            $receiptEntries .= " JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`";
        }
        $receiptEntries .= ' WHERE l.`receipt_id` = r.`id`';
        if ($filter->table !== null) {
            $receiptEntries .= ' AND t.`name` = ?';
            $receiptParams[] = $filter->table;
        }
        $receiptWhere = ["EXISTS ({$receiptEntries})"];
        if ($filter->who !== null) {
            $receiptWhere[] = self::personCondition($filter->who, $receiptParams);
        }
        if ($filter->channel !== null) {
            $receiptWhere[] = 'r.`channel` = ?';
            $receiptParams[] = $filter->channel;
        }
        self::appendPeriod('r', $filter->since, $filter->until, $receiptWhere, $receiptParams);
        $receiptSource = "SELECT r.`created_at`, 'receipt' AS `kind`, r.`id`"
            . " FROM {$database}.`hilos_change_log_receipt` r WHERE " . implode(' AND ', $receiptWhere);
        $params = $receiptParams;

        if ($filter->who !== null || $filter->channel !== null) {
            return $receiptSource;
        }
        $entryParams = [];
        $entrySource = "SELECT l.`created_at`, 'entry' AS `kind`, l.`id`"
            . " FROM {$database}.`hilos_change_log` l";
        if ($filter->table !== null) {
            $entrySource .= " JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`";
        }
        $entryWhere = ['l.`receipt_id` IS NULL'];
        if ($filter->table !== null) {
            $entryWhere[] = 't.`name` = ?';
            $entryParams[] = $filter->table;
        }
        self::appendPeriod('l', $filter->since, $filter->until, $entryWhere, $entryParams);
        $params = array_merge($receiptParams, $entryParams);
        return $receiptSource . ' UNION ALL ' . $entrySource . ' WHERE ' . implode(' AND ', $entryWhere);
    }

    /**
     * @param ?ChangeLogFeedAnchor $anchor Complete feed key
     * @param TableAnchorDirection $direction Side of the key in newest-first order
     * @param list<mixed> $params Appended SQL parameters
     * @return string SQL predicate including its leading AND, or empty at an edge
     */
    private function feedAnchorClause(
        ?ChangeLogFeedAnchor $anchor,
        TableAnchorDirection $direction,
        array &$params,
    ): string {
        if ($anchor === null) {
            return '';
        }
        $moment = self::sqlMoment($anchor->createdAt);
        array_push($params, $moment, $moment, $anchor->kind->value, $anchor->kind->value, $anchor->id);
        $timeOp = $direction === TableAnchorDirection::After ? '<' : '>';
        $kindOp = $direction === TableAnchorDirection::After ? '>' : '<';
        $idOp = $direction === TableAnchorDirection::After ? '<' : '>';
        return " AND (feed.`created_at` {$timeOp} ? OR (feed.`created_at` = ?"
            . " AND (feed.`kind` {$kindOp} ? OR (feed.`kind` = ? AND feed.`id` {$idOp} ?))))";
    }

    /**
     * @param ChangeLogFeedFilter $filter Source filters
     * @param ?ChangeLogFeedAnchor $anchor Count preceding rows when supplied
     * @param int $ceiling Exact-count ceiling
     * @return int Count capped at ceiling plus one
     * @throws DatabaseException When SQL fails
     */
    private function boundedFeedCount(ChangeLogFeedFilter $filter, ?ChangeLogFeedAnchor $anchor, int $ceiling): int
    {
        $params = [];
        $sources = $this->feedSources($filter, $params);
        $anchorClause = $this->feedAnchorClause($anchor, TableAnchorDirection::Before, $params);
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        Database::sql(
            "SELECT COUNT(*) AS total_count FROM (SELECT 1 FROM ({$sources}) feed"
            . " WHERE 1 = 1{$anchorClause} LIMIT {$limit}) capped",
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
     * @param list<int> $ids Receipt identifiers in the selected window
     * @return array<int, ChangeLogReceipt> Receipts keyed by row number
     * @throws DatabaseException When journal or person SQL fails
     */
    private function receiptsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql(
            "SELECT r.`id`, r.`created_at`, r.`actor_user_id`, r.`subject_user_id`, r.`session_id`,"
            . " r.`channel`, r.`action`, r.`agent`, r.`source` FROM {$database}.`hilos_change_log_receipt` r"
            . " WHERE r.`id` IN ({$placeholders})",
            $ids,
        );
        $headers = Database::rows();
        $personIds = [];
        foreach ($headers as $row) {
            foreach (['actor_user_id', 'subject_user_id'] as $field) {
                if ($row[$field] !== null) {
                    $personIds[(int)$row[$field]] = true;
                }
            }
        }
        $people = $this->peopleByIds(array_keys($personIds));

        Database::sql(
            "SELECT l.`receipt_id`, l.`table_id`, t.`name`, COUNT(*) AS records, MIN(l.`id`) AS first_id"
            . " FROM {$database}.`hilos_change_log` l"
            . " JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
            . " WHERE l.`receipt_id` IN ({$placeholders})"
            . ' GROUP BY l.`receipt_id`, l.`table_id`, t.`name` ORDER BY l.`receipt_id`, first_id',
            $ids,
        );
        $groups = [];
        $singleIds = [];
        foreach (Database::rows() as $row) {
            $receiptId = (int)$row['receipt_id'];
            $groups[$receiptId][] = $row;
            if ((int)$row['records'] === 1) {
                $singleIds[] = (int)$row['first_id'];
            }
        }
        $singles = $this->singleRecordsByIds($singleIds);

        $receipts = [];
        foreach ($headers as $row) {
            $id = (int)$row['id'];
            $entryCount = 0;
            $touched = [];
            foreach ($groups[$id] ?? [] as $group) {
                $records = (int)$group['records'];
                $entryCount += $records;
                $touched[] = new ChangeLogTouchedTable(
                    (string)$group['name'],
                    $records,
                    $records === 1 ? ($singles[(int)$group['first_id']] ?? null) : null,
                );
            }
            $actorId = $row['actor_user_id'] === null ? null : (int)$row['actor_user_id'];
            $subjectId = $row['subject_user_id'] === null ? null : (int)$row['subject_user_id'];
            $receipts[$id] = new ChangeLogReceipt(
                $id,
                self::moment($row['created_at']),
                $actorId === null ? null : $people[$actorId],
                $subjectId === null ? null : $people[$subjectId],
                $row['session_id'] === null ? null : (int)$row['session_id'],
                (string)$row['channel'],
                (string)$row['action'],
                $row['agent'] === null ? null : (string)$row['agent'],
                $row['source'] === null ? null : (string)$row['source'],
                $entryCount,
                $touched,
            );
        }
        return $receipts;
    }

    /**
     * @param list<int> $ids Person numbers appearing in the selected window
     * @return array<int, ChangeLogPerson> Current name or deleted-person label by id
     * @throws DatabaseException When the current person lookup fails
     */
    private function peopleByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql("SELECT `id`, `name` FROM `hilos_user` WHERE `id` IN ({$placeholders})", $ids);
        $names = [];
        foreach (Database::rows() as $row) {
            $names[(int)$row['id']] = (string)$row['name'];
        }
        $people = [];
        foreach ($ids as $id) {
            $people[$id] = new ChangeLogPerson($id, $names[$id] ?? 'Deleted user #' . $id);
        }
        return $people;
    }

    /**
     * @param list<int> $ids Single-record group entry numbers
     * @return array<int, ChangeLogTouchedRecord> Summary by entry number
     * @throws DatabaseException When journal SQL fails
     */
    private function singleRecordsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql(
            "SELECT l.`id`, l.`record_key`, l.`mutation_type`,"
            . " (SELECT COUNT(*) FROM {$database}.`hilos_change_log_change` ch"
            . ' WHERE ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at`) AS changed_fields'
            . " FROM {$database}.`hilos_change_log` l WHERE l.`id` IN ({$placeholders})",
            $ids,
        );
        $singles = [];
        foreach (Database::rows() as $row) {
            $singles[(int)$row['id']] = new ChangeLogTouchedRecord(
                self::recordKey($row['record_key']),
                (string)$row['mutation_type'],
                (int)$row['changed_fields'],
            );
        }
        return $singles;
    }

    /**
     * @param list<int> $ids Entry numbers in one bounded window
     * @param bool $includeLong Whether to read full long-value bodies
     * @return array<int, ChangeLogEntry> Entries keyed by row number
     * @throws DatabaseException When journal SQL fails
     */
    private function entriesByIds(array $ids, bool $includeLong): array
    {
        if ($ids === []) {
            return [];
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Database::sql(
            "SELECT l.`id`, l.`created_at`, l.`receipt_id`, t.`name`, l.`record_key`, l.`mutation_type`"
            . " FROM {$database}.`hilos_change_log` l"
            . " JOIN {$database}.`hilos_change_log_table` t ON t.`id` = l.`table_id`"
            . " WHERE l.`id` IN ({$placeholders})",
            $ids,
        );
        $rows = Database::rows();
        $changes = $this->changesByEntryIds($ids, $includeLong);
        $entries = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $entries[$id] = new ChangeLogEntry(
                $id,
                self::moment($row['created_at']),
                $row['receipt_id'] === null ? null : (int)$row['receipt_id'],
                (string)$row['name'],
                self::recordKey($row['record_key']),
                (string)$row['mutation_type'],
                $changes[$id] ?? [],
            );
        }
        return $entries;
    }

    /**
     * @param list<int> $ids Entry numbers in one bounded window
     * @param bool $includeLong Whether to join the long-value table
     * @return array<int, list<ChangeLogFieldChange>> Changes keyed by entry number
     * @throws DatabaseException When journal SQL fails
     */
    private function changesByEntryIds(array $ids, bool $includeLong): array
    {
        if ($ids === []) {
            return [];
        }
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $valueJoins = [];
        if ($includeLong) {
            $valueJoins[] = " LEFT JOIN {$database}.`hilos_change_log_value` v"
                . ' ON v.`change_id` = ch.`id` AND v.`created_at` = ch.`created_at`';
        }
        $oldValue = $includeLong ? "CASE WHEN ch.`kind` = 'long' THEN v.`old_value` ELSE ch.`old_value` END" : 'ch.`old_value`';
        $newValue = $includeLong ? "CASE WHEN ch.`kind` = 'long' THEN v.`new_value` ELSE ch.`new_value` END" : 'ch.`new_value`';
        Database::sql(
            "SELECT ch.`log_id`, f.`name`, ch.`kind`, ch.`old_present`, ch.`new_present`,"
            . " {$oldValue} AS old_value, {$newValue} AS new_value"
            . " FROM {$database}.`hilos_change_log` l"
            . " JOIN {$database}.`hilos_change_log_change` ch"
            . ' ON ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at`'
            . " JOIN {$database}.`hilos_change_log_field` f ON f.`id` = ch.`field_id`"
            . implode('', $valueJoins)
            . " WHERE l.`id` IN ({$placeholders}) ORDER BY ch.`log_id`, ch.`id`",
            $ids,
        );
        $changes = [];
        foreach (Database::rows() as $row) {
            $longOmitted = !$includeLong && $row['kind'] === 'long';
            $changes[(int)$row['log_id']][] = new ChangeLogFieldChange(
                (string)$row['name'],
                (string)$row['kind'],
                (bool)$row['old_present'],
                (bool)$row['new_present'],
                $longOmitted || $row['old_value'] === null ? null : (string)$row['old_value'],
                $longOmitted || $row['new_value'] === null ? null : (string)$row['new_value'],
                $longOmitted,
            );
        }
        return $changes;
    }

    /**
     * @param mixed $value JSON_ARRAY text or masked text from anonymized restore
     * @return ?list<int|string|null> Decoded key, or null when it is masked or malformed
     */
    private static function recordKey(mixed $value): ?array
    {
        $decoded = json_decode((string)$value, true, flags: JSON_BIGINT_AS_STRING);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }
        foreach ($decoded as &$part) {
            if (is_float($part)) {
                $part = (string)$part;
            } elseif (is_bool($part)) {
                $part = (int)$part;
            } elseif (!is_int($part) && !is_string($part) && $part !== null) {
                return null;
            }
        }
        unset($part);
        return $decoded;
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
