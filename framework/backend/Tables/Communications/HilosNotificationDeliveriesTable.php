<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Framework delivery-logs table: the admin journal of channel deliveries (HIL-201).
 *
 * The one table in the notifications subsystem that grows without bound, so it is
 * NOT held in runtime: it implements {@see ViewportTable} and serves each window
 * straight from SQL. {@see query()} runs a windowed SELECT over
 * hilos_notification_delivery joined to hilos_notification (for the recipient, type,
 * and title), with a matching COUNT for the total — the window placed by its anchor on
 * the server, no RT projection. The journal is live all the same (HIL-1049): the window comes
 * from SQL, and every change after it arrives as a mutation built from the db_sync_* frames
 * of the notificationDeliveries collection ({@see buildMutationForSourceEvent()}), the row
 * read again by its id. The places of the window are written in the columns of the source
 * ({@see anchorForRow()}, {@see placeRowAgainst()}), the way {@see query()} writes its boundaries.
 *
 * The channel/status/period filters and the declared search ride the open
 * viewport filter map ({@see TableQueryDTO::$filter}); a preset channel filter is
 * how the per-channel route opens the otherwise cross-cutting journal.
 *
 * A project activates the table by registering it under a table key and binding
 * that key to the deliveries page in {@see Hilos::PAGE_TABLES}. A project that
 * can resolve recipient display names subclasses and overrides {@see resolveUserLabel()};
 * the framework has no concrete user table, so the default label is null.
 */
class HilosNotificationDeliveriesTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosNotificationDeliveries';

    /**
     * Declares the journal's source: the notificationDeliveries collection, keyed by the delivery id.
     *
     * This declaration is what makes the page showing the journal a reader of notificationDeliveries
     * in the topology (HIL-376 / HIL-750), and only therefore does the master address the journal's
     * db_sync_* frames to the worker serving that page (HIL-717). It projects no rows - no FIELDS, no
     * COMPUTED: the window and its mutations are built by the table itself.
     */
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::DB_DELIVERIES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::DB_DELIVERIES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => EntityNotificationDelivery::id,
            ],
        ],
    ];

    /** Wire slot the row payload rides under; must match the frontend delivery slot. */
    private const string ROW_SLOT = 'delivery';

    /** Filter-map key: narrow the journal to one channel (the per-channel route preset). */
    public const string FILTER_CHANNEL = 'channel';

    /** Filter-map key: narrow to one delivery status (pending/sent/failed). */
    public const string FILTER_STATUS = 'status';

    /** Filter-map key: inclusive lower bound on created_at (SQL datetime). */
    public const string FILTER_FROM = 'from';

    /** Filter-map key: inclusive upper bound on created_at (date or SQL datetime; a bare date covers the whole day). */
    public const string FILTER_TO = 'to';

    /** Source the journal's rows come from: the delivery collection of the framework database. */
    private const array DB_DELIVERIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::notificationDeliveries,
    ];

    /** Delivery table alias in the windowed SQL. */
    private const string DELIVERY_TABLE = 'hilos_notification_delivery';

    /** Notification table alias in the windowed SQL. */
    private const string NOTIFICATION_TABLE = 'hilos_notification';

    /** Alias the delivery table carries in the windowed SQL, where two tables are joined. */
    private const string DELIVERY_ALIAS = 'nd';

    /** Delivery column each sortable wire field orders by, unaliased. */
    private const array SORT_COLUMNS = [
        HilosNotificationDeliveryTableRow::createdAt => EntityNotificationDelivery::created_at,
        HilosNotificationDeliveryTableRow::channel => EntityNotificationDelivery::channel,
        HilosNotificationDeliveryTableRow::status => EntityNotificationDelivery::status,
        HilosNotificationDeliveryTableRow::attempts => EntityNotificationDelivery::attempts,
        HilosNotificationDeliveryTableRow::deliveredAt => EntityNotificationDelivery::delivered_at,
    ];

    /** Order the journal falls back to when the window asked for none: newest first, the id settling it. */
    private const array DEFAULT_ORDER = [
        EntityNotificationDelivery::created_at => SqlSortDirection::DESC,
        EntityNotificationDelivery::id => SqlSortDirection::DESC,
    ];

    /** Hard window cap applied when a caller asks for an unbounded snapshot of this unbounded table. */
    private const int DEFAULT_LIMIT = 50;

    /** Filters whose options the journal counts: the two that offer a list, a period having no options to count. */
    private const array FACETED_FILTERS = [self::FILTER_CHANNEL, self::FILTER_STATUS];

    /** Row source every read of the journal runs over: each delivery with the notification it carried. */
    private const string JOIN = '`' . self::DELIVERY_TABLE . '` ' . self::DELIVERY_ALIAS
        . ' LEFT JOIN `' . self::NOTIFICATION_TABLE . '` n ON n.' . EntityNotification::id
        . ' = nd.' . EntityNotificationDelivery::notification_id;

    /** Columns every row of the journal is read with, the window's and the one row a mutation reads again. */
    private const string SELECT_COLUMNS = ' nd.' . EntityNotificationDelivery::id . ' AS id,'
        . ' nd.' . EntityNotificationDelivery::created_at . ' AS created_at,'
        . ' nd.' . EntityNotificationDelivery::channel . ' AS channel,'
        . ' nd.' . EntityNotificationDelivery::status . ' AS status,'
        . ' nd.' . EntityNotificationDelivery::attempts . ' AS attempts,'
        . ' nd.' . EntityNotificationDelivery::delivered_at . ' AS delivered_at,'
        . ' nd.' . EntityNotificationDelivery::last_error . ' AS last_error,'
        . ' n.' . EntityNotification::user_id . ' AS user_id,'
        . ' n.' . EntityNotification::type . ' AS notification_type,'
        . ' n.' . EntityNotification::title . ' AS notification_title';

    /**
     * Declares how many rows the first window of the delivery journal carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 25;
    }

    /**
     * Declares the order the first window of the delivery journal runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by createdAt descending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosNotificationDeliveryTableRow::createdAt, TableConstants::ORDER_DESC));
    }

    /**
     * Builds a journal row mutation from a change of the notificationDeliveries collection.
     *
     * A created or updated delivery is read again by its id, over the same join the window is
     * served by: the diff of an update carries only the delivery columns that moved, and the row
     * of a creation is the delivery alone, without the title, type and recipient the join brings.
     * A delivery gone between the write and this read has nothing to show and answers null; a
     * deletion needs no row and is not read at all.
     *
     * A clear answers null too: nothing hands it to a viewport table, so there is no window here
     * that could receive it.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Journal row mutation, or null when the change does not reach this table
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the row query fails
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->kind !== SourceChange::KIND_DB || $change->sourceKey !== HilosDbContext::notificationDeliveries) {
            return null;
        }

        $deliveryId = (int) $change->sourceId;
        if ($deliveryId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $deliveryId);
        }
        if ($change->mutationType !== TableMutationType::Create && $change->mutationType !== TableMutationType::Update) {
            return null;
        }

        $row = $this->readRow($deliveryId);

        return $row === null ? null : $this->mutation($change->mutationType, $deliveryId, $row);
    }

    /**
     * Serializes one delivery row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Delivery table row from this table's window
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Counts the options of the channel filter and the status filter against the journal.
     *
     * Each set is counted by {@see countSet()}, which writes its condition with the same
     * {@see buildWhere()} a window is served by, so the number beside an option is the total the
     * window would report once that option is picked - stopped at the same ceiling.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts of the
     *     channel and status options that were asked about
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When a count query fails
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            $this->countSet(...),
        );
    }

    /**
     * Answers whether one delivery is in the set a window of the journal shows.
     *
     * The set is written by the same {@see buildWhere()} a window is served by - channel, status,
     * period and search - and the one delivery is added to it as a key condition, the way a keyset
     * is added to a window. A table narrowing its set in its own SQL cannot hand the question to
     * the ORM helper, which would answer about a wider set than the window shows; this is that
     * table, so it asks its own join.
     *
     * @param string|int $rowKey Delivery id to look for
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @return ?bool Whether the delivery is in the set; this table always knows
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the lookup query fails
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        [$where, $params] = $this->buildWhere($query);
        $condition = 'nd.' . EntityNotificationDelivery::id . ' = ?';
        $where = $where === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
        $params[] = $rowKey;

        return $this->existsInSet($where, $params);
    }

    /**
     * Names the place one delivery sits at, in the columns of the source.
     *
     * The places of the journal are written in the columns of hilos_notification_delivery,
     * because that is how {@see query()} writes the boundaries of a window and how the keyset of
     * the next window reads them. The row names the same place in the fields of its payload, so
     * the row and the boundary live under different names, and it is the table that brings them
     * together ({@see ViewportTable::anchorForRow()}, HIL-787): one key space for every place of a
     * subscription - the boundaries of the snapshot, the places of its rows, and the frame around
     * the window (HIL-1037).
     *
     * @param AbstractTableRow $row Delivery row to name the place of
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?TableAnchorDTO Place in the columns of the source, or null when the window asked for no
     *     order, orders by a field without a column, or the row carries no value for it
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        if ($query->sort === null) {
            return null;
        }

        $fields = $row->toArray();
        $values = [];
        foreach (InMemoryTableFilter::anchorFields($query->sort, HilosNotificationDeliveryTableRow::keyField()) as $field) {
            $column = self::placeColumn($field);
            if ($column === null || !array_key_exists($field, $fields)) {
                return null;
            }
            $values[$column] = $fields[$field];
        }

        return new TableAnchorDTO($values);
    }

    /**
     * Places one delivery against a boundary written in the columns of the source.
     *
     * The boundary is read back onto the fields of the row and the two are compared by the one
     * comparator every window uses, so the journal places its rows by the rule every other table
     * places them by, only reading its own names first ({@see anchorForRow()} says why they are
     * its own). The id of the boundary is made an integer when it is one: the database may hand
     * it over as text, and {@see InMemoryTableFilter::compare()} settles a tie between two keys
     * as numbers only when both are integers - as text, delivery 10 would stand above delivery 9.
     *
     * @param AbstractTableRow $row Delivery row to place
     * @param TableAnchorDTO $anchor Boundary in the columns of the source
     * @param TableQueryDTO $query Window query whose sort names the order the place is read in
     * @return ?int Negative above the anchor, zero at it, positive below it, or null when the window
     *     asked for no order, orders by a field without a column, or the anchor lacks the column
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        if ($query->sort === null) {
            return null;
        }

        $keyField = HilosNotificationDeliveryTableRow::keyField();
        $against = [];
        foreach (InMemoryTableFilter::anchorFields($query->sort, $keyField) as $field) {
            $column = self::placeColumn($field);
            if ($column === null || !array_key_exists($column, $anchor->values)) {
                return null;
            }
            $value = $anchor->values[$column];
            $against[$field] = $field === $keyField && is_string($value) && ctype_digit($value) ? (int) $value : $value;
        }

        return InMemoryTableFilter::compare($row->toArray(), $against, $query->sort, $keyField);
    }

    /**
     * Declares the journal's sortable columns, qualified with the delivery alias of its own SQL.
     *
     * @return array<string, string> Wire row fields mapped to the columns they order by
     */
    protected function sortableFields(): array
    {
        return array_map(
            static fn(string $column): string => self::DELIVERY_ALIAS . '.' . $column,
            self::SORT_COLUMNS,
        );
    }

    /**
     * Declares what a delivery row is searched by: the notification behind it and the error it left.
     *
     * These rows are read by a hand-written join of two tables, so each column is qualified with
     * the alias it belongs to - the notification's own fields under `n`, the delivery's under `nd`.
     *
     * The recipient is searched too and is not declared here: it is matched by identity when the
     * term is a number, and a declaration says only which fields are read, not how. It moves in
     * when the declaration learns to carry the second question.
     *
     * @return array<string, string> Searched fields mapped to their qualified columns
     */
    protected function searchableFields(): array
    {
        return [
            HilosNotificationDeliveryTableRow::notificationType => 'n.' . EntityNotification::type,
            HilosNotificationDeliveryTableRow::notificationTitle => 'n.' . EntityNotification::title,
            HilosNotificationDeliveryTableRow::lastError => 'nd.' . EntityNotificationDelivery::last_error,
        ];
    }

    /**
     * Serves one window of the journal from SQL: the joined page plus the total count.
     *
     * The window is placed by the same keyset condition the ORM path uses, so a deep page of
     * this unbounded journal costs what a shallow one does. A jump to a numbered page still
     * skips rows, counted from whichever end of the set is nearer, and the far half comes back
     * turned over - which is why the rows are put back in the journal's own order below. The same
     * cut takes off the rows framing the window, which leave only as the snapshot's frame.
     *
     * The count stops at {@see TableConstants::COUNT_CEILING} for the same reason the ORM path
     * does: this journal is the one table here with no bound at all, so counting it whole is the
     * one thing in serving a window whose cost grows without end.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, size, address)
     * @return TableSnapshotDTO Window snapshot with typed rows, the count with its exactness, and the boundaries
     * @throws DatabaseException When the windowed query or count fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        [$where, $params] = $this->buildWhere($query);
        $limit = $query->limit === TableConstants::NO_LIMIT ? self::DEFAULT_LIMIT : $query->limit;

        $counted = TableFacetTally::cappedSqlCount(self::JOIN, $where, $params);
        $totalCount = $counted->count;
        $totalExact = $counted->exact;

        $orderColumns = $this->orderColumns($query);
        $plan = TableWindowPlan::forQuery($query->withLimit($limit), $orderColumns, $totalCount, $totalExact);
        if ($plan === null) {
            return new TableSnapshotDTO(rows: [], totalCount: $totalCount, totalExact: $totalExact, limit: $limit);
        }

        $anchorColumns = array_keys($orderColumns);
        $keyset = $plan->keyset;
        if ($keyset !== null) {
            $condition = $keyset->toSql(self::DELIVERY_TABLE, self::DELIVERY_ALIAS);
            $where = $where === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
            $params = array_merge($params, $keyset->getParams());
        }

        $sql = 'SELECT' . self::SELECT_COLUMNS
            . ' FROM ' . self::JOIN
            . $where
            . self::renderOrderBy($plan->orderBy)
            . " LIMIT {$plan->limit} OFFSET {$plan->offset}";

        [$rows, $frame] = $plan->cut(
            Database::sql($sql, $params)->rows(),
            static fn(array $row): TableAnchorDTO => TableAnchorDTO::fromRow($row, $anchorColumns),
        );
        $rows = array_values($rows);

        return new TableSnapshotDTO(
            rows: array_map(fn(array $row): HilosNotificationDeliveryTableRow => $this->rowFromSql($row), $rows),
            totalCount: $totalCount,
            totalExact: $totalExact,
            limit: $limit,
            firstAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[0], $anchorColumns),
            lastAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[count($rows) - 1], $anchorColumns),
            frame: $frame,
        );
    }

    /**
     * Counts one set of the journal up to the ceiling, the count a window of that set would report.
     *
     * @param TableQueryDTO $query Query whose search and filters describe the set
     * @return TableFacetCountDTO Deliveries in the set, or the ceiling with the word that it stopped there
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the count query fails
     */
    protected function countSet(TableQueryDTO $query): TableFacetCountDTO
    {
        [$where, $params] = $this->buildWhere($query);

        return TableFacetTally::cappedSqlCount(self::JOIN, $where, $params);
    }

    /**
     * Looks one row of the journal up under a condition, reading no more than the first match.
     *
     * The one place the membership question reaches the database, kept apart for the reason
     * {@see countSet()} is: a test replaces it and reads the condition the table wrote.
     *
     * @param string $where The ` WHERE ...` clause of the set with the key condition in it
     * @param list<mixed> $params Parameters bound to the placeholders of the clause, in order
     * @return bool Whether a row answers the condition
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the lookup query fails
     */
    protected function existsInSet(string $where, array $params): bool
    {
        return Database::sql('SELECT 1 FROM ' . self::JOIN . $where . ' LIMIT 1', $params)->firstRow() !== null;
    }

    /**
     * Reads one row of the journal by its delivery id, over the join a window is served by.
     *
     * The one place a mutation reaches the database, kept apart for the reason
     * {@see existsInSet()} is: a test replaces it and reads which delivery was asked for.
     *
     * @param int $deliveryId Delivery id to read
     * @return ?HilosNotificationDeliveryTableRow Journal row, or null when the delivery is gone
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When the row query fails
     */
    protected function readRow(int $deliveryId): ?HilosNotificationDeliveryTableRow
    {
        $row = Database::sql(
            'SELECT' . self::SELECT_COLUMNS . ' FROM ' . self::JOIN . ' WHERE nd.' . EntityNotificationDelivery::id . ' = ? LIMIT 1',
            [$deliveryId],
        )->firstRow();

        return $row === null ? null : $this->rowFromSql($row);
    }

    /**
     * Configures the row shape used by the delivery-logs table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosNotificationDeliveryTableRow::class);
    }

    /**
     * Resolves a recipient's display label for the journal, unnamed by default.
     *
     * The framework owns no concrete user table, so it cannot name a recipient; a
     * project with a user model subclasses this table and overrides this seam to
     * return a display name. The journal still shows the recipient's user id either way.
     *
     * @param int $userId Recipient user id
     * @return ?string Display label, or null when the project resolves none
     */
    protected function resolveUserLabel(int $userId): ?string
    {
        return null;
    }

    /**
     * Builds the WHERE clause and its bound parameters from the query filters.
     *
     * Pure string/parameter assembly (no I/O): a channel/status equality, a
     * created_at period range, and the search over the declared fields, each contributing
     * a `?` placeholder so values are always bound, never inlined.
     *
     * @param TableQueryDTO $query Window query
     * @return array{0: string, 1: list<mixed>} The `WHERE ...` clause (empty string when no filters) and its ordered params
     */
    protected function buildWhere(TableQueryDTO $query): array
    {
        $conditions = [];
        $params = [];

        $channel = $this->filterString($query, self::FILTER_CHANNEL);
        if ($channel !== null) {
            $conditions[] = 'nd.' . EntityNotificationDelivery::channel . ' = ?';
            $params[] = $channel;
        }

        $status = $this->filterString($query, self::FILTER_STATUS);
        if ($status !== null && DeliveryStatus::isValid($status)) {
            $conditions[] = 'nd.' . EntityNotificationDelivery::status . ' = ?';
            $params[] = $status;
        }

        $from = $this->filterString($query, self::FILTER_FROM);
        if ($from !== null) {
            $conditions[] = 'nd.' . EntityNotificationDelivery::created_at . ' >= ?';
            $params[] = $from;
        }

        $to = $this->filterString($query, self::FILTER_TO);
        if ($to !== null) {
            $conditions[] = 'nd.' . EntityNotificationDelivery::created_at . ' <= ?';
            $params[] = $this->endOfDayBound($to);
        }

        $search = TableSearchTerm::normalize($query->search);
        if ($search !== null && $query->searchableFields !== []) {
            $pattern = TableSearchTerm::likePattern($search);
            $searchConditions = [];
            foreach ($query->searchableFields as $column) {
                $searchConditions[] = $column . ' ' . TableSearchTerm::LIKE_COMPARISON;
                $params[] = $pattern;
            }
            // The recipient answers to a number and not to a piece of text, which is a second way
            // of searching a column and not a second column: the declaration above carries which
            // fields are read, and this stays written out until it can carry the how as well.
            if (ctype_digit($search)) {
                $searchConditions[] = 'n.' . EntityNotification::user_id . ' = ?';
                $params[] = (int) $search;
            }
            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        if ($conditions === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Builds the ORDER BY clause the window runs by.
     *
     * @param TableQueryDTO $query Window query
     * @return string The `ORDER BY ...` clause
     */
    protected function buildOrderBy(TableQueryDTO $query): string
    {
        return self::renderOrderBy($this->orderColumns($query));
    }

    /**
     * Reads the window's ordering as the delivery columns it runs by, defaulting to newest first.
     *
     * The order arrives resolved: {@see TableDefinition::getPage()} has held it against
     * {@see sortableFields()} and either attached the column each component may order by or
     * dropped the whole of it, so an order whose components carry no column is a window that
     * asked for none.
     *
     * The delivery id settles the order in the direction its last component runs, so one index
     * over the columns serves either direction by being scanned backwards. Fixing the id to
     * descending would instead ask the server for two columns running opposite ways, which no
     * single index answers. The same total key is what the window's anchor is read against.
     *
     * The columns are unaliased here, because this map is read twice - once to write the clause,
     * where the join needs the alias, and once to place the anchor, where the alias is the
     * builder's own argument.
     *
     * @param TableQueryDTO $query Window query
     * @return array<string, string> Delivery column => SqlSortDirection, the id settling the order
     */
    protected function orderColumns(TableQueryDTO $query): array
    {
        $order = $query->sort;
        if ($order === null) {
            return self::DEFAULT_ORDER;
        }

        $orderColumns = [];
        foreach ($order->components as $component) {
            $column = $component->column === null ? null : self::SORT_COLUMNS[$component->field] ?? null;
            if ($column === null) {
                return self::DEFAULT_ORDER;
            }

            $orderColumns[$column] = $component->direction === TableConstants::ORDER_ASC
                ? SqlSortDirection::ASC
                : SqlSortDirection::DESC;
        }

        $tieBreakerDirection = $order->last()->direction === TableConstants::ORDER_ASC
            ? SqlSortDirection::ASC
            : SqlSortDirection::DESC;
        $orderColumns[EntityNotificationDelivery::id] ??= $tieBreakerDirection;

        return $orderColumns;
    }

    /**
     * Writes an ordering out as the clause the joined SQL takes, every column on the delivery alias.
     *
     * @param array<string, string> $orderColumns Delivery column => SqlSortDirection
     * @return string The `ORDER BY ...` clause
     */
    private static function renderOrderBy(array $orderColumns): string
    {
        $parts = [];
        foreach ($orderColumns as $column => $direction) {
            $parts[] = self::DELIVERY_ALIAS . '.' . $column . ' ' . $direction;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Names the column of the source one field of a delivery row is placed by.
     *
     * The one map of sortable fields to columns serves here too, with the row key added as the
     * delivery id; a second inventory of columns would be a second description of one order.
     *
     * @param string $field Field of the delivery row
     * @return ?string Unaliased delivery column, or null when the field has none
     */
    private static function placeColumn(string $field): ?string
    {
        return self::SORT_COLUMNS[$field]
            ?? ($field === HilosNotificationDeliveryTableRow::keyField() ? EntityNotificationDelivery::id : null);
    }

    /**
     * Widens a date-only upper bound to the end of that day so the whole day is included.
     *
     * The frontend period picker emits a bare `YYYY-MM-DD`, which MySQL reads as that
     * day's midnight (`00:00:00`); a raw `created_at <= '2026-07-28'` would then drop
     * every delivery made during the selected day. A date-only bound is widened to
     * `... 23:59:59` (the schema stores whole-second datetimes); a value that already
     * carries a time component is passed through untouched.
     *
     * @param string $to Upper-bound filter value (bare date or SQL datetime)
     * @return string Datetime upper bound safe for an inclusive `<=` comparison
     */
    private function endOfDayBound(string $to): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to . ' 23:59:59' : $to;
    }

    /**
     * Reads one string filter value from the open filter map.
     *
     * @param TableQueryDTO $query Window query
     * @param string $key Filter key
     * @return ?string Trimmed string value, or null when the window filters on nothing here
     */
    private function filterString(TableQueryDTO $query, string $key): ?string
    {
        $value = $query->filter[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Projects one joined SQL row into a typed delivery table row.
     *
     * @param array<string, mixed> $row Joined SQL row (delivery columns + notification type/title/user_id)
     * @return HilosNotificationDeliveryTableRow Delivery table row
     */
    protected function rowFromSql(array $row): HilosNotificationDeliveryTableRow
    {
        $userId = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;

        return new HilosNotificationDeliveryTableRow(
            rowKey: (int) ($row['id'] ?? 0),
            // external-boundary: created_at is NOT NULL, so the driver always hands its stored value over
            createdAt: (string) ($row['created_at'] ?? ''),
            // external-boundary: channel is NOT NULL, so the driver always hands its stored value over
            channel: (string) ($row['channel'] ?? ''),
            // external-boundary: status is NOT NULL, so the driver always hands its stored value over
            status: (string) ($row['status'] ?? ''),
            attempts: (int) ($row['attempts'] ?? 0),
            deliveredAt: $row['delivered_at'] !== null ? (string) $row['delivered_at'] : null,
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            userId: $userId,
            userLabel: $userId !== null ? $this->resolveUserLabel($userId) : null,
            // A LEFT JOIN miss is a notification the retention job already removed, not an empty title.
            notificationType: isset($row['notification_type']) ? (string) $row['notification_type'] : null,
            notificationTitle: isset($row['notification_title']) ? (string) $row['notification_title'] : null,
        );
    }
}
