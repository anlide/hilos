<?php

declare(strict_types=1);

namespace Hilos\Tables\Communications;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;

/**
 * Framework delivery-logs table: the admin journal of channel deliveries (HIL-201).
 *
 * The one table in the notifications subsystem that grows without bound, so it is
 * NOT held in runtime: it implements {@see ViewportTable} and serves each window
 * straight from SQL. {@see query()} runs a windowed SELECT over
 * hilos_notification_delivery joined to hilos_notification (for the recipient, type,
 * and title), with a matching COUNT for the total — the window placed by its anchor on
 * the server, no RT projection. The consequence, taken deliberately, is that the journal has no
 * live per-row deltas: {@see buildMutationForSourceEvent()} returns null and the
 * frontend refreshes by re-requesting the window.
 *
 * The channel/status/period filters and the type/recipient search ride the open
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

    /**
     * The delivery journal has no live per-row source; a window refresh is a re-query.
     *
     * @param SourceChange $change Source change (ignored)
     * @return ?TableRowMutationDTO Always null — no source-driven deltas
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
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
     * Serves one window of the journal from SQL: the joined page plus the total count.
     *
     * The window is placed by the same keyset condition the ORM path uses, so a deep page of
     * this unbounded journal costs what a shallow one does. A jump to a numbered page still
     * skips rows, counted from whichever end of the set is nearer, and the far half comes back
     * turned over - which is why the rows are put back in the journal's own order below.
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

        $join = '`' . self::DELIVERY_TABLE . '` ' . self::DELIVERY_ALIAS
            . ' LEFT JOIN `' . self::NOTIFICATION_TABLE . '` n ON n.' . EntityNotification::id
            . ' = nd.' . EntityNotificationDelivery::notification_id;

        $capped = TableConstants::COUNT_CEILING + 1;
        $countSql = 'SELECT COUNT(*) AS cnt FROM (SELECT 1 FROM ' . $join . $where . " LIMIT {$capped}) AS `capped`";
        $counted = (int) (Database::sql($countSql, $params)->firstRow()['cnt'] ?? 0);
        $totalExact = $counted <= TableConstants::COUNT_CEILING;
        $totalCount = $totalExact ? $counted : TableConstants::COUNT_CEILING;

        $orderColumns = $this->orderColumns($query);
        $plan = TableWindowPlan::forQuery($query->withLimit($limit), $orderColumns, $totalCount, $totalExact);
        if ($plan === null) {
            return new TableSnapshotDTO(rows: [], totalCount: $totalCount, totalExact: $totalExact, limit: $limit);
        }

        $keyset = $plan->keyset;
        if ($keyset !== null) {
            $condition = $keyset->toSql(self::DELIVERY_TABLE, self::DELIVERY_ALIAS);
            $where = $where === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
            $params = array_merge($params, $keyset->getParams());
        }

        $sql = 'SELECT'
            . ' nd.' . EntityNotificationDelivery::id . ' AS id,'
            . ' nd.' . EntityNotificationDelivery::created_at . ' AS created_at,'
            . ' nd.' . EntityNotificationDelivery::channel . ' AS channel,'
            . ' nd.' . EntityNotificationDelivery::status . ' AS status,'
            . ' nd.' . EntityNotificationDelivery::attempts . ' AS attempts,'
            . ' nd.' . EntityNotificationDelivery::delivered_at . ' AS delivered_at,'
            . ' nd.' . EntityNotificationDelivery::last_error . ' AS last_error,'
            . ' n.' . EntityNotification::user_id . ' AS user_id,'
            . ' n.' . EntityNotification::type . ' AS notification_type,'
            . ' n.' . EntityNotification::title . ' AS notification_title'
            . ' FROM ' . $join
            . $where
            . self::renderOrderBy($plan->orderBy)
            . " LIMIT {$plan->limit} OFFSET {$plan->offset}";

        $rows = Database::sql($sql, $params)->rows();
        if ($plan->reversed) {
            $rows = array_reverse($rows);
        }

        $anchorColumns = array_keys($orderColumns);

        return new TableSnapshotDTO(
            rows: array_map(fn(array $row): HilosNotificationDeliveryTableRow => $this->rowFromSql($row), $rows),
            totalCount: $totalCount,
            totalExact: $totalExact,
            limit: $limit,
            firstAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[0], $anchorColumns),
            lastAnchor: $rows === [] ? null : TableAnchorDTO::fromRow($rows[count($rows) - 1], $anchorColumns),
        );
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
     * created_at period range, and a type/title/recipient search, each contributing
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

        $search = $query->search !== null ? trim($query->search) : null;
        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $searchConditions = [
                'n.' . EntityNotification::type . ' LIKE ?',
                'n.' . EntityNotification::title . ' LIKE ?',
            ];
            $params[] = $like;
            $params[] = $like;
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
