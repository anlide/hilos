<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\ChangeLog\Section\ChangeLogPerson;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt;
use Hilos\Database\ChangeLog\Section\ChangeLogReceiptHeader;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedRecord;
use Hilos\Database\ChangeLog\Section\ChangeLogTouchedTable;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseBinding;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\ChangeLogReceipts as EntityChangeLogReceipts;
use Hilos\Database\Entity\Item\ChangeLogChange as EntityChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogTable as EntityChangeLogTable;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\ChangeLogEntries as ViewChangeLogEntries;
use Hilos\Hilos;

/**
 * @extends Objects<ObjectChangeLogReceipt>
 * @method ObjectChangeLogReceipt|null current()
 * @method ObjectChangeLogReceipt|null first()
 * @method ObjectChangeLogReceipt|null last()
 * @method ObjectChangeLogReceipt|null get(int|string $key)
 * @method ObjectChangeLogReceipt|null offsetGet(mixed $offset)
 */
class ChangeLogReceipts extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogReceipt::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogReceipts::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogReceipts;
    public const bool CACHE_ROWS = false;

    private const string DATABASE_REQUIRED_MESSAGE = 'Journal section reads require a database-backed collection';

    /**
     * UNION, full-key navigation and summaries cannot be expressed by scalar key/page reads.
     * Every query uses the journal Entities' shared binding; no snapshot enters the ORM map.
     *
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ?ChangeLogFeedAnchor $anchor Complete time, kind and row key, or an edge
     * @param TableAnchorDirection $direction Side of the anchor in newest-first order
     * @param int $limit Requested rows, capped at the section window limit
     * @param int $skip Nonnegative edge offset, at most the table count ceiling
     * @return ChangeLogReadCollection<ChangeLogFeedItem> Dense rows in canonical newest-first order
     * @throws InvalidArgumentException When a skip is used with an anchor
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function feedWindow(
        ChangeLogFeedFilter $filter,
        ?ChangeLogFeedAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): ChangeLogReadCollection {
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Change log skip requires an edge');
        }
        $skip = max(0, $skip);
        $limit = max(0, min(ViewChangeLogEntries::MAX_WINDOW_ROWS, $limit));
        if ($skip > TableConstants::COUNT_CEILING || $limit === 0) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $params = [];
        $sources = $this->feedSources($filter, $params);
        $anchorClause = self::feedAnchorClause($anchor, $direction, $params);
        $order = $direction === TableAnchorDirection::After
            ? 'created_at DESC, kind ASC, id DESC' : 'created_at ASC, kind DESC, id ASC';
        $rows = Database::sql(
            "SELECT feed.`created_at`, feed.`kind`, feed.`id` FROM ({$sources}) feed"
            . " WHERE 1 = 1{$anchorClause} ORDER BY {$order} LIMIT {$limit} OFFSET {$skip}",
            $params,
            index: $binding->connectionIndex,
        )->rows();
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
        $receipts = $this->sectionReceiptsByIds($receiptIds);
        $entries = Hilos::$db->changeLogEntries->sectionEntriesByIds($entryIds, false);
        $items = [];
        foreach ($rows as $row) {
            $kind = ChangeLogFeedItemKind::from((string)$row['kind']);
            $id = (int)$row['id'];
            $items[] = new ChangeLogFeedItem(
                $kind,
                self::moment($row['created_at']),
                $kind === ChangeLogFeedItemKind::RECEIPT ? $receipts->get($id) : null,
                $kind === ChangeLogFeedItemKind::ENTRY ? $entries->get($id) : null,
            );
        }
        return ChangeLogReadCollection::fromArray($items);
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Matching rows, capped at the clamped ceiling plus one without hydrating entries
     * @throws DatabaseException When journal or person SQL fails
     */
    public function feedCount(ChangeLogFeedFilter $filter, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->boundedFeedCount($filter, null, $ceiling);
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ChangeLogFeedAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Strictly preceding rows in newest-first order, capped at the ceiling plus one
     * @throws DatabaseException When journal or person SQL fails
     */
    public function feedCountBefore(
        ChangeLogFeedFilter $filter,
        ChangeLogFeedAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedFeedCount($filter, $anchor, $ceiling);
    }

    /**
     * @param int $id Receipt row number
     * @return ?ChangeLogReceipt Full summary, or null for a missing or empty receipt
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionReceipt(int $id): ?ChangeLogReceipt
    {
        $receipt = $this->sectionReceiptsByIds([$id])->get($id);
        return $receipt?->entryCount > 0 ? $receipt : null;
    }

    /**
     * Summaries aggregate all entries in SQL for finite receipt ids, independently of feed filters.
     * Scalar page reads cannot supply these groups; trigger-written data is never retained here.
     *
     * @param list<int> $ids Selected receipt ids, read in bounded batches and deduplicated
     * @return ChangeLogReadCollection<ChangeLogReceipt> Full summaries keyed by receipt id, omitting missing ids
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionReceiptsByIds(array $ids): ChangeLogReadCollection
    {
        if ($ids === []) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $entries = $binding->getSqlTableName(EntityChangeLogEntry::_table);
        $tables = $binding->getSqlTableName(EntityChangeLogTable::_table);
        $receipts = [];
        foreach (array_chunk(array_values(array_unique($ids)), ViewChangeLogEntries::MAX_WINDOW_ROWS) as $batch) {
            $headers = $this->sectionHeadersByIds($batch);
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $rows = Database::sql(
                'SELECT l.`receipt_id`, l.`table_id`, t.`name`, COUNT(*) AS records, MIN(l.`id`) AS first_id'
                . " FROM {$entries} l JOIN {$tables} t ON t.`id` = l.`table_id`"
                . " WHERE l.`receipt_id` IN ({$placeholders})"
                . ' GROUP BY l.`receipt_id`, l.`table_id`, t.`name` ORDER BY l.`receipt_id`, first_id',
                $batch,
                index: $binding->connectionIndex,
            )->rows();
            $groups = [];
            $singleIds = [];
            foreach ($rows as $row) {
                $groups[(int)$row['receipt_id']][] = $row;
                if ((int)$row['records'] === 1) {
                    $singleIds[] = (int)$row['first_id'];
                }
            }
            $singles = $this->singleRecordsByIds($singleIds);
            foreach ($headers as $id => $header) {
                $entryCount = 0;
                $touched = [];
                foreach ($groups[$id] ?? [] as $group) {
                    $records = (int)$group['records'];
                    $entryCount += $records;
                    $touched[] = new ChangeLogTouchedTable(
                        (string)$group['name'],
                        $records,
                        $records === 1 ? $singles->get((int)$group['first_id']) : null,
                    );
                }
                $receipts[$id] = new ChangeLogReceipt(
                    $id,
                    $header->createdAt,
                    $header->actor,
                    $header->subject,
                    $header->sessionId,
                    $header->channel,
                    $header->action,
                    $header->agent,
                    $header->source,
                    $entryCount,
                    $touched,
                );
            }
        }
        return ChangeLogReadCollection::fromArray($receipts);
    }

    /**
     * @param list<int> $ids Selected receipt ids, read in bounded batches and deduplicated
     * @return ChangeLogReadCollection<ChangeLogReceiptHeader> Attribution keyed by receipt id without entry summaries
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionHeadersByIds(array $ids): ChangeLogReadCollection
    {
        if ($ids === []) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $table = $binding->getSqlTableName($this->getTableName());
        $headers = [];
        foreach (array_chunk(array_values(array_unique($ids)), ViewChangeLogEntries::MAX_WINDOW_ROWS) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $rows = Database::sql(
                'SELECT r.`id`, r.`created_at`, r.`actor_user_id`, r.`subject_user_id`, r.`session_id`,'
                . " r.`channel`, r.`action`, r.`agent`, r.`source` FROM {$table} r WHERE r.`id` IN ({$placeholders})",
                $batch,
                index: $binding->connectionIndex,
            )->rows();
            $personIds = [];
            foreach ($rows as $row) {
                foreach (['actor_user_id', 'subject_user_id'] as $field) {
                    if ($row[$field] !== null) {
                        $personIds[(int)$row[$field]] = true;
                    }
                }
            }
            $people = $this->peopleByIds(array_keys($personIds));
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $actorId = $row['actor_user_id'] === null ? null : (int)$row['actor_user_id'];
                $subjectId = $row['subject_user_id'] === null ? null : (int)$row['subject_user_id'];
                $headers[$id] = new ChangeLogReceiptHeader(
                    $id,
                    self::moment($row['created_at']),
                    $actorId === null ? null : $people->get($actorId),
                    $subjectId === null ? null : $people->get($subjectId),
                    $row['session_id'] === null ? null : (int)$row['session_id'],
                    (string)$row['channel'],
                    (string)$row['action'],
                    $row['agent'] === null ? null : (string)$row['agent'],
                    $row['source'] === null ? null : (string)$row['source'],
                );
            }
        }
        return ChangeLogReadCollection::fromArray($headers);
    }

    /**
     * Inserts one receipt and returns the object built from the stored row.
     *
     * The insert is not the generic entity save: a migration receipt omits open_handovers, and an
     * insert whose outcome is ambiguous is never sent again. The handover column is written only
     * when the caller retains a local attempt. Membership is the ordinary collection door.
     *
     * The mounted changeLogReceipts collection is the door. The statement uses that collection's
     * entity binding and its row connection, which for the journal is the primary connection.
     *
     * @param JournalReceiptData $data Attribution stored on the receipt
     * @param bool $retainLocal Whether the insert keeps one local handover
     * @return ObjectChangeLogReceipt Receipt built from the stored row
     * @throws DatabaseException When the binding, insert, or stored-row read fails
     * @throws CreateNotAllowedException When no truth source in this process may add a receipt
     * @throws WriteNotAllowedException When the receipt table is not application-writable
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    public function insertReceipt(JournalReceiptData $data, bool $retainLocal): ObjectChangeLogReceipt
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException('Journal receipt insert requires a database-backed collection');
        Database::assertCanWrite($binding->connectionIndex);
        $objectClass = static::OBJECT_CLASS;
        DbWriteGuard::guardCreate(static::COLLECTION_KEY, static fn(): array => $objectClass::create()->touchedSetKeys());

        $entityClass = static::entityClass();
        $table = $binding->getSqlTableName($entityClass::_table);
        $columns = '`' . $entityClass::actor_user_id . '`, `' . $entityClass::subject_user_id . '`, `'
            . $entityClass::session_id . '`, `' . $entityClass::channel . '`, `' . $entityClass::action . '`, `'
            . $entityClass::agent . '`, `' . $entityClass::source . '`, `' . $entityClass::created_at . '`';
        $values = '?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6)';
        if ($retainLocal) {
            $columns .= ', `' . $entityClass::open_handovers . '`';
            $values .= ', 1';
        }
        if (!Database::isConnected($binding->connectionIndex)) {
            Database::sql('SELECT 1', index: $binding->connectionIndex);
        }
        try {
            Database::sql(
                "INSERT INTO {$table} ({$columns}) VALUES ({$values})",
                [
                    $data->actorUserId,
                    $data->subjectUserId,
                    $data->sessionId,
                    $data->channel,
                    $data->action,
                    $data->agent,
                    $data->source,
                ],
                tryReconnect: false,
                index: $binding->connectionIndex,
            );
        } catch (DatabaseException $failure) {
            try {
                // A lost INSERT response is ambiguous. Never replay it; drop a dead link
                // so the next receipt can open a clean one instead of retrying the write.
                Database::setJournalReceiptId(JournalReceiptScope::currentId());
            } catch (DatabaseException) {
                // Keep the INSERT failure; setJournalReceiptId has discarded the link.
            }
            throw $failure;
        }

        $receiptId = Database::lastInsertId($binding->connectionIndex);
        $row = $this->readInsertedReceipt(
            $binding,
            $entityClass,
            $receiptId,
            $objectClass::columnProjection($retainLocal),
        );
        if (!$retainLocal) {
            $row[$entityClass::open_handovers] = 0;
        }
        $receipt = $objectClass::create();
        $receipt->acceptStoredInsert($row);
        $this[$receipt->getIdString()] = $receipt;

        return $receipt;
    }

    /**
     * Writes only the agent, without reading or retaining a receipt snapshot.
     * Missing and unchanged rows announce nothing; the previous agent is unknown.
     *
     * @param int $receiptId Global receipt row id
     * @param string $writerAgentId Agent that wrote the journaled business row
     * @throws DatabaseException When the binding or update fails or a foreign transaction forbids the write
     * @throws WriteNotAllowedException When no truth source in this process may update receipts
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function recordWriter(int $receiptId, string $writerAgentId): void
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException('Journal receipt writer requires a database-backed collection');
        Database::assertCanWrite($binding->connectionIndex);
        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY, TruthSourceOperation::Update);

        $entityClass = static::entityClass();
        $table = $binding->getSqlTableName($entityClass::_table);
        Database::sql(
            "UPDATE {$table} SET `" . $entityClass::agent . '` = ? WHERE `' . $entityClass::id . '` = ?'
            . ' AND NOT (`' . $entityClass::agent . '` <=> ?)',
            [$writerAgentId, $receiptId, $writerAgentId],
            tryReconnect: false,
            index: $binding->connectionIndex,
        );
        if (Database::affectedRows($binding->connectionIndex) < 1) {
            return;
        }

        $row = [$entityClass::agent => $writerAgentId];
        $currentReceiptId = JournalReceiptScope::currentId();
        Hilos::$sr?->queueDbSyncSignal(
            SignalConstants::DB_SYNC_UPDATED,
            new DbSyncUpdatedSignalData(
                static::COLLECTION_KEY,
                (string)$receiptId,
                $row,
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
                previous: [],
                receiptId: $currentReceiptId,
                writerAgentId: $currentReceiptId === null ? null : ExecutionContext::currentAgentId(),
            ),
            connectionIndex: $binding->connectionIndex,
        );
        SourceChangeBus::publish(SourceChange::dbUpdated(
            static::COLLECTION_KEY,
            (string)$receiptId,
            $row,
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
            previous: [],
        ), connectionIndex: $binding->connectionIndex);
    }

    /**
     * Reads the inserted receipt by id on the same binding connection. Does not repeat the insert.
     *
     * @param DatabaseBinding $binding Receipt binding the insert used
     * @param class-string $entityClass Entity class of this collection
     * @param int $receiptId Auto-increment id returned for the insert
     * @param string $projection Quoted core columns, without a column the insert did not write
     * @return array<string, mixed> Stored core columns
     * @throws DatabaseException When the stored row is not exactly one
     */
    private function readInsertedReceipt(
        DatabaseBinding $binding,
        string $entityClass,
        int $receiptId,
        string $projection,
    ): array {
        $table = $binding->getSqlTableName($entityClass::_table);
        $rows = Database::sql(
            'SELECT ' . $projection . ' FROM ' . $table . ' WHERE `' . $entityClass::id . '` = ?',
            [$receiptId],
            index: $binding->connectionIndex,
        )->rows();
        if (count($rows) !== 1) {
            throw new DatabaseException('Journal receipt insert did not return the stored row');
        }

        return $rows[0];
    }

    /**
     * @param ChangeLogFeedFilter $filter Source filters
     * @param list<mixed> $params SQL boundary parameters in UNION source order
     * @return string Receipt and optional bare-entry SQL sources
     * @throws DatabaseException When the binding or current name projection cannot be read
     */
    private function feedSources(ChangeLogFeedFilter $filter, array &$params): string
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $receipts = $binding->getSqlTableName($this->getTableName());
        $entries = $binding->getSqlTableName(EntityChangeLogEntry::_table);
        $tables = $binding->getSqlTableName(EntityChangeLogTable::_table);
        $receiptEntries = "SELECT 1 FROM {$entries} l";
        if ($filter->table !== null) {
            $receiptEntries .= " JOIN {$tables} t ON t.`id` = l.`table_id`";
        }
        $receiptEntries .= ' WHERE l.`receipt_id` = r.`id`';
        $params = [];
        if ($filter->table !== null) {
            $receiptEntries .= ' AND t.`name` = ?';
            $params[] = $filter->table;
        }
        $receiptWhere = ["EXISTS ({$receiptEntries})"];
        if ($filter->who !== null) {
            $receiptWhere[] = self::personCondition($filter->who, $params);
        }
        if ($filter->channel !== null) {
            $receiptWhere[] = 'r.`channel` = ?';
            $params[] = $filter->channel;
        }
        self::appendPeriod('r', $filter->since, $filter->until, $receiptWhere, $params);
        $receiptSource = "SELECT r.`created_at`, 'receipt' AS `kind`, r.`id` FROM {$receipts} r WHERE "
            . implode(' AND ', $receiptWhere);
        if ($filter->who !== null || $filter->channel !== null) {
            return $receiptSource;
        }
        $entrySource = "SELECT l.`created_at`, 'entry' AS `kind`, l.`id` FROM {$entries} l";
        $entryWhere = ['l.`receipt_id` IS NULL'];
        if ($filter->table !== null) {
            $entrySource .= " JOIN {$tables} t ON t.`id` = l.`table_id`";
            $entryWhere[] = 't.`name` = ?';
            $params[] = $filter->table;
        }
        self::appendPeriod('l', $filter->since, $filter->until, $entryWhere, $params);
        return $receiptSource . ' UNION ALL ' . $entrySource . ' WHERE ' . implode(' AND ', $entryWhere);
    }

    /**
     * @param ?ChangeLogFeedAnchor $anchor Complete feed key
     * @param TableAnchorDirection $direction Side of the key in newest-first order
     * @param list<mixed> $params Appended SQL boundary parameters
     * @return string SQL predicate including its leading AND, or empty at an edge
     */
    private static function feedAnchorClause(
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
     * @param ?ChangeLogFeedAnchor $anchor Count strictly preceding rows when supplied
     * @param int $ceiling Exact-count ceiling
     * @return int SQL count capped at the clamped ceiling plus one
     * @throws DatabaseException When journal or person SQL fails
     */
    private function boundedFeedCount(ChangeLogFeedFilter $filter, ?ChangeLogFeedAnchor $anchor, int $ceiling): int
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $params = [];
        $sources = $this->feedSources($filter, $params);
        $anchorClause = self::feedAnchorClause($anchor, TableAnchorDirection::Before, $params);
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        return (int)Database::sql(
            "SELECT COUNT(*) AS total_count FROM (SELECT 1 FROM ({$sources}) feed"
            . " WHERE 1 = 1{$anchorClause} LIMIT {$limit}) capped",
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
     * @param list<int> $ids Person numbers from a finite receipt batch
     * @return ChangeLogReadCollection<ChangeLogPerson> Current names or deleted-person labels keyed by id
     * @throws DatabaseException When the fresh user name projection fails
     */
    private function peopleByIds(array $ids): ChangeLogReadCollection
    {
        $names = Hilos::$db->users->namesByIds($ids);
        $people = [];
        foreach ($ids as $id) {
            $people[$id] = new ChangeLogPerson($id, $names[$id] ?? 'Deleted user #' . $id, !isset($names[$id]));
        }
        return ChangeLogReadCollection::fromArray($people);
    }

    /**
     * @param list<int> $ids Single-record group entry numbers, read in bounded batches
     * @return ChangeLogReadCollection<ChangeLogTouchedRecord> Complete single-record summaries keyed by entry id
     * @throws DatabaseException When journal SQL fails
     */
    private function singleRecordsByIds(array $ids): ChangeLogReadCollection
    {
        if ($ids === []) {
            return ChangeLogReadCollection::fromArray([]);
        }
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $entries = $binding->getSqlTableName(EntityChangeLogEntry::_table);
        $changes = $binding->getSqlTableName(EntityChangeLogChange::_table);
        $singles = [];
        foreach (array_chunk(array_values(array_unique($ids)), ViewChangeLogEntries::MAX_WINDOW_ROWS) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $rows = Database::sql(
                'SELECT l.`id`, l.`record_key`, l.`mutation_type`,'
                . " (SELECT COUNT(*) FROM {$changes} ch"
                . ' WHERE ch.`log_id` = l.`id` AND ch.`created_at` = l.`created_at`) AS changed_fields'
                . " FROM {$entries} l WHERE l.`id` IN ({$placeholders})",
                $batch,
                index: $binding->connectionIndex,
            )->rows();
            foreach ($rows as $row) {
                $singles[(int)$row['id']] = new ChangeLogTouchedRecord(
                    self::recordKey($row['record_key']),
                    (string)$row['mutation_type'],
                    (int)$row['changed_fields'],
                );
            }
        }
        return ChangeLogReadCollection::fromArray($singles);
    }

    /**
     * @param string $alias Journal SQL source alias
     * @param ?DateTimeImmutable $since Inclusive lower bound
     * @param ?DateTimeImmutable $until Exclusive upper bound
     * @param list<string> $where Appended SQL predicates
     * @param list<mixed> $params Appended SQL boundary parameters
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
     * @param DateTimeImmutable $moment Moment in any time zone
     * @return string UTC DATETIME(6)
     */
    private static function sqlMoment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(TimeConstants::UTC_TIME_ZONE))->format('Y-m-d H:i:s.u');
    }

    /**
     * @param string $value Non-null journal DATETIME(6)
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
}
