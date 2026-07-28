<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-logs table's SQL window assembly (HIL-201).
 *
 * Exercises the pure WHERE/ORDER BY builders (no database): the channel/status/period
 * filters and the type/recipient search each contribute a bound `?` placeholder, an
 * invalid status is ignored, a numeric search also matches the recipient id, and the
 * sort field is whitelisted with a newest-first default.
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

    public function testOrderByWhitelistsSortFieldAndDirection(): void
    {
        self::assertSame(
            ' ORDER BY nd.attempts ASC, nd.id DESC',
            $this->table()->exposedBuildOrderBy(new TableQueryDTO(
                orderBy: 'attempts',
                orderDirection: TableConstants::ORDER_ASC,
            )),
        );
    }

    public function testOrderByRejectsUnknownSortField(): void
    {
        self::assertSame(
            ' ORDER BY nd.created_at DESC, nd.id DESC',
            $this->table()->exposedBuildOrderBy(new TableQueryDTO(orderBy: 'note; DROP TABLE')),
        );
    }

    /**
     * Builds a table subclass that exposes the protected SQL builders for testing.
     *
     * @return HilosNotificationDeliveriesTable&object{exposedBuildWhere: callable, exposedBuildOrderBy: callable} Table with exposed builders
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
        };
    }
}
