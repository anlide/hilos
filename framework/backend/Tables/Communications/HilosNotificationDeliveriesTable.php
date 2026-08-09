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
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
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
 * and title), with a matching COUNT for the total — LIMIT/OFFSET on the server, no
 * RT projection. The consequence, taken deliberately, is that the journal has no
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

    /** Hard window cap applied when a caller asks for an unbounded snapshot of this unbounded table. */
    private const int DEFAULT_LIMIT = 50;

    /**
     * Sortable columns exposed to the viewport, mapped to their qualified SQL columns.
     *
     * Whitelisted so a client-supplied sort field can never reach the ORDER BY as raw SQL.
     *
     * @var array<string, string>
     */
    private const array SORTABLE = [
        HilosNotificationDeliveryTableRow::createdAt => 'nd.' . EntityNotificationDelivery::created_at,
        HilosNotificationDeliveryTableRow::channel => 'nd.' . EntityNotificationDelivery::channel,
        HilosNotificationDeliveryTableRow::status => 'nd.' . EntityNotificationDelivery::status,
        HilosNotificationDeliveryTableRow::attempts => 'nd.' . EntityNotificationDelivery::attempts,
        HilosNotificationDeliveryTableRow::deliveredAt => 'nd.' . EntityNotificationDelivery::delivered_at,
    ];

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
     * Serves one window of the journal from SQL: the joined page plus the total count.
     *
     * @param TableQueryDTO $query Window query (search, filters, sort, offset, limit)
     * @return TableSnapshotDTO Window snapshot with typed rows and the total count
     * @throws DatabaseException When the windowed query or count fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        [$where, $params] = $this->buildWhere($query);
        $orderBy = $this->buildOrderBy($query);
        $limit = $query->limit === TableConstants::NO_LIMIT ? self::DEFAULT_LIMIT : $query->limit;
        $offset = max(0, $query->offset);

        $join = '`' . self::DELIVERY_TABLE . '` nd'
            . ' LEFT JOIN `' . self::NOTIFICATION_TABLE . '` n ON n.' . EntityNotification::id
            . ' = nd.' . EntityNotificationDelivery::notification_id;

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
            . $orderBy
            . " LIMIT {$limit} OFFSET {$offset}";

        $rows = Database::sql($sql, $params)->rows();

        $countSql = 'SELECT COUNT(*) AS cnt FROM ' . $join . $where;
        $totalCount = (int) (Database::sql($countSql, $params)->firstRow()['cnt'] ?? 0);

        return new TableSnapshotDTO(
            rows: array_map(fn(array $row): HilosNotificationDeliveryTableRow => $this->rowFromSql($row), $rows),
            totalCount: $totalCount,
            offset: $offset,
            limit: $limit,
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
     * Builds the ORDER BY clause, whitelisting the sort field and defaulting to newest first.
     *
     * @param TableQueryDTO $query Window query
     * @return string The `ORDER BY ...` clause
     */
    protected function buildOrderBy(TableQueryDTO $query): string
    {
        $sort = $query->sort;
        $column = $sort === null ? null : (self::SORTABLE[$sort->field] ?? null);
        if ($column === null) {
            return ' ORDER BY nd.' . EntityNotificationDelivery::created_at . ' ' . SqlSortDirection::DESC
                . ', nd.' . EntityNotificationDelivery::id . ' ' . SqlSortDirection::DESC;
        }

        $direction = $sort->direction === TableConstants::ORDER_ASC
            ? SqlSortDirection::ASC
            : SqlSortDirection::DESC;

        return ' ORDER BY ' . $column . ' ' . $direction
            . ', nd.' . EntityNotificationDelivery::id . ' ' . SqlSortDirection::DESC;
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
