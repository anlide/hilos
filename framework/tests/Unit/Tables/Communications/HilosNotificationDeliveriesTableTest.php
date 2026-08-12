<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-logs table's SQL window assembly (HIL-201).
 *
 * Exercises the pure WHERE/ORDER BY builders (no database): the channel/status/period
 * filters and the type/recipient search each contribute a bound `?` placeholder, an
 * invalid status is ignored, a numeric search also matches the recipient id, and the
 * ORDER BY follows the column the table's own map allowed, defaulting to newest first.
 */
final class HilosNotificationDeliveriesTableTest extends TestCase
{
    public function testNoFiltersYieldEmptyWhere(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO());

        self::assertSame('', $where);
        self::assertSame([], $params);
    }

    public function testChannelStatusAndPeriodFiltersBindInOrder(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(
            filter: [
                HilosNotificationDeliveriesTable::FILTER_CHANNEL => 'email',
                HilosNotificationDeliveriesTable::FILTER_STATUS => 'failed',
                HilosNotificationDeliveriesTable::FILTER_FROM => '2026-07-01 00:00:00',
                HilosNotificationDeliveriesTable::FILTER_TO => '2026-07-28 23:59:59',
            ],
        ));

        self::assertSame(
            ' WHERE nd.channel = ? AND nd.status = ? AND nd.created_at >= ? AND nd.created_at <= ?',
            $where,
        );
        self::assertSame(['email', 'failed', '2026-07-01 00:00:00', '2026-07-28 23:59:59'], $params);
    }

    public function testDateOnlyToBoundIsWidenedToEndOfDay(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(
            filter: [
                HilosNotificationDeliveriesTable::FILTER_FROM => '2026-07-01',
                HilosNotificationDeliveriesTable::FILTER_TO => '2026-07-28',
            ],
        ));

        self::assertSame(
            ' WHERE nd.created_at >= ? AND nd.created_at <= ?',
            $where,
        );
        // The bare `to` date is widened so the whole final day is included; `from` at
        // midnight already covers its whole day, so it is left as-is.
        self::assertSame(['2026-07-01', '2026-07-28 23:59:59'], $params);
    }

    public function testDatetimeToBoundIsLeftUntouched(): void
    {
        [, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(
            filter: [HilosNotificationDeliveriesTable::FILTER_TO => '2026-07-28 12:30:00'],
        ));

        self::assertSame(['2026-07-28 12:30:00'], $params);
    }

    public function testInvalidStatusFilterIsIgnored(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(
            filter: [HilosNotificationDeliveriesTable::FILTER_STATUS => 'bogus'],
        ));

        self::assertSame('', $where);
        self::assertSame([], $params);
    }

    public function testTextSearchMatchesTypeAndTitle(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(search: 'welcome'));

        self::assertSame(' WHERE (n.type LIKE ? OR n.title LIKE ?)', $where);
        self::assertSame(['%welcome%', '%welcome%'], $params);
    }

    public function testNumericSearchAlsoMatchesRecipientId(): void
    {
        [$where, $params] = $this->table()->exposedBuildWhere(new TableQueryDTO(search: '42'));

        self::assertSame(' WHERE (n.type LIKE ? OR n.title LIKE ? OR n.user_id = ?)', $where);
        self::assertSame(['%42%', '%42%', 42], $params);
    }

    public function testOrderByDefaultsToNewestFirst(): void
    {
        self::assertSame(
            ' ORDER BY nd.created_at DESC, nd.id DESC',
            $this->table()->exposedBuildOrderBy(new TableQueryDTO()),
        );
    }

    public function testOrderByUsesTheAllowedColumnAndTheRequestedDirection(): void
    {
        $table = $this->table();

        self::assertSame(
            ' ORDER BY nd.attempts ASC, nd.id DESC',
            $table->exposedBuildOrderBy(new TableQueryDTO(
                sort: $this->resolvedSort($table, new TableSortDTO('attempts', TableConstants::ORDER_ASC)),
            )),
        );
    }

    public function testOrderByRejectsUnknownSortField(): void
    {
        $table = $this->table();

        ob_start();
        $sort = $this->resolvedSort($table, new TableSortDTO('note` DESC, (SELECT 1)'));
        ob_end_clean();

        self::assertNull($sort);
        self::assertSame(
            ' ORDER BY nd.created_at DESC, nd.id DESC',
            $table->exposedBuildOrderBy(new TableQueryDTO(sort: $sort)),
        );
    }

    public function testALeftJoinMissCarriesNullRatherThanAnEmptyTitle(): void
    {
        $row = $this->table()->exposedRowFromSql([
            'id' => 7,
            'created_at' => '2026-08-09 10:00:00',
            'channel' => 'email',
            'status' => 'failed',
            'attempts' => 1,
            'delivered_at' => null,
            'last_error' => null,
            'user_id' => null,
        ]);

        // The notification was removed by retention: no type, no title, and nobody to label.
        self::assertNull($row->notificationType);
        self::assertNull($row->notificationTitle);
        self::assertNull($row->userLabel);
        self::assertSame('failed', $row->status);
    }

    /**
     * Runs a requested sort through the table's own map, the way getPage() does before the query.
     *
     * @param HilosNotificationDeliveriesTable $table Table whose map decides
     * @param TableSortDTO $sort Sort as the window requested it
     * @return ?TableSortDTO Sort carrying its allowed column, or null when the table does not sort by it
     */
    private function resolvedSort(HilosNotificationDeliveriesTable $table, TableSortDTO $sort): ?TableSortDTO
    {
        return TableSortWhitelist::resolve($sort, $table->exposedSortableFields(), $table::class);
    }

    /**
     * Builds a table subclass that exposes the protected SQL builders for testing.
     *
     * @return HilosNotificationDeliveriesTable&object{exposedBuildWhere: callable, exposedBuildOrderBy:
     *     callable, exposedSortableFields: callable, exposedRowFromSql: callable} Table with exposed builders
     */
    private function table(): HilosNotificationDeliveriesTable
    {
        return new class extends HilosNotificationDeliveriesTable {
            /**
             * @param TableQueryDTO $query Window query
             * @return array{0: string, 1: list<mixed>} WHERE clause and its params
             */
            public function exposedBuildWhere(TableQueryDTO $query): array
            {
                return $this->buildWhere($query);
            }

            /**
             * @param TableQueryDTO $query Window query
             * @return string ORDER BY clause
             */
            public function exposedBuildOrderBy(TableQueryDTO $query): string
            {
                return $this->buildOrderBy($query);
            }

            /**
             * @return array<string, string> Sort fields the journal declares
             */
            public function exposedSortableFields(): array
            {
                return $this->sortableFields();
            }

            /**
             * @param array<string, mixed> $row Joined SQL row
             * @return HilosNotificationDeliveryTableRow Projected delivery row
             */
            public function exposedRowFromSql(array $row): HilosNotificationDeliveryTableRow
            {
                return $this->rowFromSql($row);
            }
        };
    }
}
