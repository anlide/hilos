<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\ChangeLogEntries as EntityChangeLogEntries;
use Hilos\Database\Entity\Item\ChangeLogChange as EntityChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogField as EntityChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogTable as EntityChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogValue as EntityChangeLogValue;
use Hilos\Database\Object\Item\ChangeLogEntry as ObjectChangeLogEntry;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\ChangeLogEntries as ViewChangeLogEntries;

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
