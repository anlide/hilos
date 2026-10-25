<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\ChangeLogEntries as EntityChangeLogEntries;
use Hilos\Database\Entity\Item\ChangeLogChange as EntityChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogField as EntityChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use Hilos\Database\Entity\Item\ChangeLogTable as EntityChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogValue as EntityChangeLogValue;
use Hilos\Database\Object\Item\ChangeLogEntry as ObjectChangeLogEntry;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\ChangeLogEntries as ViewChangeLogEntries;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends Objects<ObjectChangeLogEntry>
 * @method ObjectChangeLogEntry|null current()
 * @method ObjectChangeLogEntry|null first()
 * @method ObjectChangeLogEntry|null last()
 * @method ObjectChangeLogEntry|null get(int|string $key)
 * @method ObjectChangeLogEntry|null offsetGet(mixed $offset)
 */
class ChangeLogEntries extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogEntry::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogEntries::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogEntries;
    public const bool CACHE_ROWS = false;

    private const string DATABASE_REQUIRED_MESSAGE = 'Journal section reads require a database-backed collection';
    private const string LONG_VALUE_KIND = 'long';
    private const array INTEGER_KEY_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /**
     * @return ?DateTimeImmutable UTC source time of the lowest journal id, or null for an empty journal
     * @throws DatabaseException When the collection is not database-backed or SQL or moment decoding fails
     */
    public function oldestCreatedAt(): ?DateTimeImmutable
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $row = Database::sql(
            "SELECT `created_at` FROM {$table} ORDER BY `id` ASC LIMIT 1",
            index: $binding->connectionIndex,
        )->row();
        return $row === null ? null : self::moment($row['created_at']);
    }

    /**
     * @param int $receiptId Receipt whose journal rows are read
     * @param ?int $afterEntryId Last row of the preceding portion; must belong to this receipt
     * @param int $limit Requested rows, capped at the section window limit
     * @return ChangeLogReadCollection<ChangeLogEntry> Dense full entries ordered by source time and id; empty for an invalid cursor
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function receiptEntries(int $receiptId, ?int $afterEntryId, int $limit): ChangeLogReadCollection
    {
        $limit = max(0, min(ViewChangeLogEntries::MAX_WINDOW_ROWS, $limit));
        if ($limit === 0) {
            return ChangeLogReadCollection::fromArray([]);
        }

        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $cursor = '';
        $params = [$receiptId];
        if ($afterEntryId !== null) {
            $row = Database::sql(
                "SELECT `created_at` FROM {$table} WHERE `id` = ? AND `receipt_id` = ? LIMIT 1",
                [$afterEntryId, $receiptId],
                index: $binding->connectionIndex,
            )->row();
            if ($row === null) {
                return ChangeLogReadCollection::fromArray([]);
            }
            $cursor = ' AND (`created_at` > ? OR (`created_at` = ? AND `id` > ?))';
            array_push($params, $row['created_at'], $row['created_at'], $afterEntryId);
        }
        $rows = Database::sql(
            "SELECT `id` FROM {$table} WHERE `receipt_id` = ?{$cursor} ORDER BY `created_at` ASC, `id` ASC LIMIT {$limit}",
            $params,
            index: $binding->connectionIndex,
        )->rows();
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $entries = $this->sectionEntriesByIds($ids, true);
        $orderedEntries = [];
        foreach ($ids as $id) {
            $entry = $entries->get($id);
            if ($entry !== null) {
                $orderedEntries[] = $entry;
            }
        }
        return ChangeLogReadCollection::fromArray($orderedEntries);
    }

    /**
     * @param int $entryId Journal row number
     * @return ?ChangeLogEntry Complete entry with long values, or null when absent
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function sectionEntry(int $entryId): ?ChangeLogEntry
    {
        return $this->sectionEntriesByIds([$entryId], true)->get($entryId);
    }

    /**
     * The finite selection is read in bounded batches and never retained in the ORM map.
     *
     * @param list<int> $ids Selected journal row numbers, omitting absent ids and deduplicating repeats
     * @param bool $includeLong Whether to fetch long-value bodies
     * @return ChangeLogReadCollection<ChangeLogEntry> Fresh entries keyed by journal row number
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function sectionEntriesByIds(array $ids, bool $includeLong): ChangeLogReadCollection
    {
        if ($ids === []) {
            return ChangeLogReadCollection::fromArray([]);
        }

        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $tables = $binding->getSqlTableName(EntityChangeLogTable::_table);
        $entries = [];
        foreach (array_chunk(array_values(array_unique($ids)), ViewChangeLogEntries::MAX_WINDOW_ROWS) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $rows = Database::sql(
                'SELECT l.`id`, l.`created_at`, l.`receipt_id`, t.`name`, l.`record_key`, l.`mutation_type`'
                . " FROM {$table} l JOIN {$tables} t ON t.`id` = l.`table_id` WHERE l.`id` IN ({$placeholders})",
                $batch,
                index: $binding->connectionIndex,
            )->rows();
            $changes = $this->changesByEntryIds($batch, $includeLong);
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $entries[$id] = new ChangeLogEntry(
                    $id,
                    self::moment($row['created_at']),
                    $row['receipt_id'] === null ? null : (int)$row['receipt_id'],
                    (string)$row['name'],
                    self::recordKey($row['record_key']),
                    (string)$row['mutation_type'],
                    $changes->get($id)?->toArray() ?? [],
                );
            }
        }
        return ChangeLogReadCollection::fromArray($entries);
    }

    /**
     * Selection uses bounded SQL; hydration reuses the finite entry and receipt snapshots.
     *
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param bool $newestFirst Whether canonical order is descending source time and id
     * @param ?ChangeLogHistoryAnchor $anchor Complete row key, or an edge
     * @param TableAnchorDirection $direction Side of the anchor in canonical order
     * @param int $limit Requested rows, capped at the section window limit
     * @param int $skip Edge offset, clamped to nonnegative and bounded by the count ceiling
     * @return ChangeLogReadCollection<ChangeLogHistoryRow> Dense rows in canonical order, omitting vanished entries
     * @throws InvalidArgumentException When the record key is invalid or a skip is used with an anchor
     * @throws HilosException When journal, dictionary, schema or person reads or moment decoding fail
     */
    public function historyWindow(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ?ChangeLogHistoryAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): ChangeLogReadCollection {
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Change log skip requires an edge');
        }
        $skip = max(0, $skip);
        if ($skip > TableConstants::COUNT_CEILING) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $tableId = Hilos::$db->changeLogTables->sectionIdByName($table);
        $limit = max(0, min(ViewChangeLogEntries::MAX_WINDOW_ROWS, $limit));
        if ($tableId === null || $limit === 0) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $entriesTable = $binding->getSqlTableName($this->getTableName());
        $params = [];
        $where = $this->historyWhere($tableId, $table, $filter, $params);
        $cursor = self::historyAnchorClause($anchor, $newestFirst, $direction, $params);
        $descending = $newestFirst === ($direction === TableAnchorDirection::After);
        $order = $descending ? 'DESC' : 'ASC';
        $selected = Database::sql(
            "SELECT l.`id` FROM {$entriesTable} l"
            . " WHERE {$where}{$cursor} ORDER BY l.`created_at` {$order}, l.`id` {$order} LIMIT {$limit} OFFSET {$skip}",
            $params,
            index: $binding->connectionIndex,
        )->rows();
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $selected);
        if ($direction === TableAnchorDirection::Before) {
            $ids = array_reverse($ids);
        }
        $entries = $this->sectionEntriesByIds($ids, false);
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
            if ($entry !== null) {
                $rows[] = new ChangeLogHistoryRow(
                    $entry,
                    $entry->receiptId === null ? null : $headers->get($entry->receiptId),
                );
            }
        }
        return ChangeLogReadCollection::fromArray($rows);
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Matching rows, capped at the clamped ceiling plus one without entry hydration
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal, dictionary, schema or person reads fail
     */
    public function historyCount(
        string $table,
        ChangeLogHistoryFilter $filter,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        $tableId = Hilos::$db->changeLogTables->sectionIdByName($table);
        return $tableId === null ? 0 : $this->boundedHistoryCount($tableId, $table, $filter, null, true, $ceiling);
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param bool $newestFirst Chosen canonical order
     * @param ChangeLogHistoryAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Strictly preceding rows, capped at the clamped ceiling plus one without entry hydration
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal, dictionary, schema or person reads fail
     */
    public function historyCountBefore(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ChangeLogHistoryAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        $tableId = Hilos::$db->changeLogTables->sectionIdByName($table);
        return $tableId === null ? 0
            : $this->boundedHistoryCount($tableId, $table, $filter, $anchor, $newestFirst, $ceiling);
    }

    /**
     * The selected entry ids are bounded; their fields are read in full, without a field-count cap.
     * Journal Entities share this collection's binding; joins retain both parts of partitioned row keys.
     *
     * @param list<int> $ids Entry numbers in one bounded batch
     * @param bool $includeLong Whether to join long-value bodies
     * @return ChangeLogReadCollection<ChangeLogReadCollection<ChangeLogFieldChange>> Ordered fields grouped by entry id
     * @throws DatabaseException When journal SQL fails
     */
    private function changesByEntryIds(array $ids, bool $includeLong): ChangeLogReadCollection
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $changesTable = $binding->getSqlTableName(EntityChangeLogChange::_table);
        $fields = $binding->getSqlTableName(EntityChangeLogField::_table);
        $valueJoin = '';
        if ($includeLong) {
            $values = $binding->getSqlTableName(EntityChangeLogValue::_table);
            $valueJoin = " LEFT JOIN {$values} v ON v.`change_id` = ch.`id` AND v.`created_at` = ch.`created_at`";
        }
        $oldValue = $includeLong ? "CASE WHEN ch.`kind` = 'long' THEN v.`old_value` ELSE ch.`old_value` END" : 'ch.`old_value`';
        $newValue = $includeLong ? "CASE WHEN ch.`kind` = 'long' THEN v.`new_value` ELSE ch.`new_value` END" : 'ch.`new_value`';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = Database::sql(
            'SELECT ch.`log_id`, f.`name`, ch.`kind`, ch.`old_present`, ch.`new_present`,'
            . " {$oldValue} AS old_value, {$newValue} AS new_value FROM {$table} l"
            . " JOIN {$changesTable} ch ON ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at`"
            . " JOIN {$fields} f ON f.`id` = ch.`field_id`{$valueJoin}"
            . " WHERE l.`id` IN ({$placeholders}) ORDER BY ch.`log_id`, ch.`id`",
            $ids,
            index: $binding->connectionIndex,
        )->rows();
        $changes = [];
        foreach ($rows as $row) {
            $longOmitted = !$includeLong && $row['kind'] === self::LONG_VALUE_KIND;
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
        $groups = [];
        foreach ($changes as $id => $fieldsForEntry) {
            $groups[$id] = ChangeLogReadCollection::fromArray($fieldsForEntry);
        }
        return ChangeLogReadCollection::fromArray($groups);
    }

    /**
     * @param int $tableId Journal dictionary id
     * @param string $table Live table name for primary-key type validation
     * @param ChangeLogHistoryFilter $filter Selected history subset
     * @param list<mixed> $params Appended SQL parameters
     * @return string AND-joined SQL predicate for journal alias l
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws DatabaseException When the binding, live schema or person projection cannot be read
     */
    private function historyWhere(int $tableId, string $table, ChangeLogHistoryFilter $filter, array &$params): string
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $where = ['l.`table_id` = ?'];
        $params[] = $tableId;
        if ($filter->recordKey !== null) {
            $previous = Database::getCurrentIndex();
            try {
                $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
            } finally {
                Database::useConnection($previous);
            }
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
            $changes = $binding->getSqlTableName(EntityChangeLogChange::_table);
            $fields = $binding->getSqlTableName(EntityChangeLogField::_table);
            $where[] = "l.`mutation_type` = 'update' AND EXISTS (SELECT 1"
                . " FROM {$changes} ch"
                . " JOIN {$fields} f ON f.`id` = ch.`field_id`"
                . ' WHERE ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at` AND f.`name` = ?)';
            $params[] = $filter->field;
        }
        if ($filter->who !== null) {
            $receipts = $binding->getSqlTableName(EntityChangeLogReceipt::_table);
            $whoCondition = self::personCondition($filter->who, $params);
            $where[] = "EXISTS (SELECT 1 FROM {$receipts} r"
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
    private static function historyAnchorClause(
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
     * @throws DatabaseException When journal, live schema or person SQL fails
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
        $cursor = self::historyAnchorClause($anchor, $newestFirst, TableAnchorDirection::Before, $params);
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $entries = $binding->getSqlTableName($this->getTableName());
        return (int)Database::sql(
            "SELECT COUNT(*) AS total_count FROM (SELECT 1 FROM {$entries} l"
            . " WHERE {$where}{$cursor} LIMIT {$limit}) capped",
            $params,
            index: $binding->connectionIndex,
        )->field('total_count');
    }

    /**
     * @param string $who Trimmed soft person number or current name substring
     * @param list<mixed> $params Appended SQL boundary parameters
     * @return string Predicate over receipt alias r, never a join across database bindings
     * @throws DatabaseException When current matching person ids cannot be read
     */
    private static function personCondition(string $who, array &$params): string
    {
        if (preg_match('/\A#?[0-9]+\z/D', $who) === 1) {
            $digits = ltrim($who, '#0');
            $id = $digits === '' ? 0 : filter_var($digits, FILTER_VALIDATE_INT);
            if ($id === false) {
                return '0 = 1';
            }
            array_push($params, $id, $id);
            return '(r.`actor_user_id` = ? OR r.`subject_user_id` = ?)';
        }
        $ids = Hilos::$db->users->idsMatchingName($who);
        if ($ids === []) {
            return '0 = 1';
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids, ...$ids);
        return "(r.`actor_user_id` IN ({$placeholders}) OR r.`subject_user_id` IN ({$placeholders}))";
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
     * @param string $alias SQL source alias selected by this collection
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
        return $moment->setTimezone(new DateTimeZone(TimeConstants::UTC_TIME_ZONE))->format('Y-m-d H:i:s.u');
    }

    /**
     * @param mixed $value Stored JSON_ARRAY text or an anonymized key
     * @return ?list<int|string|null> Decoded key, or null for masked or malformed storage
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
     * @param string $value Non-null DATETIME(6) returned by the journal
     * @return DateTimeImmutable UTC source moment with microseconds intact
     * @throws DatabaseException When SQL returned a malformed moment
     */
    private static function moment(string $value): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone(TimeConstants::UTC_TIME_ZONE));
        if ($moment === false) {
            throw new DatabaseException('Journal returned an invalid DATETIME(6)');
        }
        return $moment;
    }
}
