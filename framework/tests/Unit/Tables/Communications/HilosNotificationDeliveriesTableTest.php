<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableSortWhitelist;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery-logs table's SQL window assembly (HIL-201).
 *
 * Exercises the pure WHERE/ORDER BY builders (no database): the channel/status/period
 * filters and the declared search each contribute a bound `?` placeholder, an invalid
 * status is ignored, the search runs over the fields the table declares and escapes the
 * wildcards a reader types, a numeric search also matches the recipient id, and the
 * ORDER BY follows the column the table's own map allowed, defaulting to newest first.
 *
 * The live half (HIL-1049): a change of the notificationDeliveries collection becomes a mutation
 * carrying the row read again by its id, and the places of the journal are named and compared in
 * the columns of the source, the key space its window boundaries are written in.
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

    public function testTextSearchRunsOverTheDeclaredFields(): void
    {
        $table = $this->table();

        [$where, $params] = $table->exposedBuildWhere($table->scopeSearch(new TableQueryDTO(search: 'welcome')));

        $like = TableSearchTerm::LIKE_COMPARISON;
        self::assertSame(" WHERE (n.type {$like} OR n.title {$like} OR nd.last_error {$like})", $where);
        self::assertSame(['%welcome%', '%welcome%', '%welcome%'], $params);
    }

    public function testNumericSearchAlsoMatchesRecipientId(): void
    {
        $table = $this->table();

        [$where, $params] = $table->exposedBuildWhere($table->scopeSearch(new TableQueryDTO(search: '42')));

        $like = TableSearchTerm::LIKE_COMPARISON;
        self::assertSame(" WHERE (n.type {$like} OR n.title {$like} OR nd.last_error {$like} OR n.user_id = ?)", $where);
        self::assertSame(['%42%', '%42%', '%42%', 42], $params);
    }

    public function testATypedWildcardIsSearchedForLiterally(): void
    {
        $table = $this->table();

        [, $params] = $table->exposedBuildWhere($table->scopeSearch(new TableQueryDTO(search: ' 50% _off ')));

        // The edges are trimmed, the inner space is kept, and both wildcards are escaped: this
        // finds the row whose text really says "50% _off" instead of matching nearly everything.
        self::assertSame(['%50!% !_off%', '%50!% !_off%', '%50!% !_off%'], $params);
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
            ' ORDER BY nd.attempts ASC, nd.id ASC',
            $table->exposedBuildOrderBy(new TableQueryDTO(
                sort: $this->resolvedOrder($table, new TableSortDTO('attempts', TableConstants::ORDER_ASC)),
            )),
        );
    }

    public function testOrderByRejectsUnknownSortField(): void
    {
        $table = $this->table();

        ob_start();
        $sort = $this->resolvedOrder($table, new TableSortDTO('note` DESC, (SELECT 1)'));
        ob_end_clean();

        self::assertNull($sort);
        self::assertSame(
            ' ORDER BY nd.created_at DESC, nd.id DESC',
            $table->exposedBuildOrderBy(new TableQueryDTO(sort: $sort)),
        );
    }

    public function testOrderByRunsEveryComponentAndSettlesOnTheLastOnesDirection(): void
    {
        $table = $this->table();

        self::assertSame(
            ' ORDER BY nd.channel ASC, nd.attempts ASC, nd.id ASC',
            $table->exposedBuildOrderBy(new TableQueryDTO(
                sort: $this->resolvedOrder(
                    $table,
                    new TableSortDTO('channel', TableConstants::ORDER_ASC),
                    new TableSortDTO('attempts', TableConstants::ORDER_ASC),
                ),
            )),
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
     * Each option is counted under the journal's own condition with its filter swapped for that option
     * and the others standing, and "any" with the filter lifted; a period offers no options to count.
     */
    public function testAnOptionIsCountedWithItsOwnFilterSwappedAndTheOthersStanding(): void
    {
        $table = $this->table();

        $facets = $table->facetCounts(
            new TableQueryDTO(filter: [
                HilosNotificationDeliveriesTable::FILTER_CHANNEL => 'email',
                HilosNotificationDeliveriesTable::FILTER_STATUS => 'failed',
            ]),
            [
                HilosNotificationDeliveriesTable::FILTER_CHANNEL => ['email', 'sms'],
                HilosNotificationDeliveriesTable::FILTER_FROM => ['2026-07-01'],
            ],
        );

        self::assertSame([HilosNotificationDeliveriesTable::FILTER_CHANNEL], array_keys($facets));
        self::assertSame(
            [
                [' WHERE nd.status = ?', ['failed']],
                [' WHERE nd.channel = ? AND nd.status = ?', ['email', 'failed']],
                [' WHERE nd.channel = ? AND nd.status = ?', ['sms', 'failed']],
            ],
            $table->countedWheres,
        );
    }

    /**
     * With nothing narrowing the journal, the one delivery is the whole condition.
     */
    public function testAMembershipQuestionOverTheWholeJournalAsksOnlyForTheKey(): void
    {
        $table = $this->table();

        self::assertTrue($table->containsRow(42, new TableQueryDTO()));
        self::assertSame([[' WHERE nd.id = ?', [42]]], $table->lookedUpWheres);
    }

    /**
     * The set is written by the window's own condition, and the key is added to it last.
     */
    public function testAMembershipQuestionAddsTheKeyToTheWindowsOwnCondition(): void
    {
        $table = $this->table();

        $table->containsRow('42', new TableQueryDTO(filter: [
            HilosNotificationDeliveriesTable::FILTER_CHANNEL => 'email',
            HilosNotificationDeliveriesTable::FILTER_STATUS => 'failed',
            HilosNotificationDeliveriesTable::FILTER_FROM => '2026-07-01 00:00:00',
        ]));

        self::assertSame(
            [[
                ' WHERE nd.channel = ? AND nd.status = ? AND nd.created_at >= ? AND nd.id = ?',
                ['email', 'failed', '2026-07-01 00:00:00', '42'],
            ]],
            $table->lookedUpWheres,
        );
    }

    /**
     * A created or updated delivery is read again by its own id and travels with the mutation of the same type.
     */
    public function testACreatedOrUpdatedDeliveryIsReadAgainByItsId(): void
    {
        foreach ([
            TableMutationType::Create->value => SourceChange::dbCreated(HilosDbContext::notificationDeliveries, '57', []),
            TableMutationType::Update->value => SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '57', ['status' => 'sent']),
        ] as $type => $change) {
            $table = $this->table();
            $table->storedRow = self::deliveryRow(57, '2026-09-23 10:00:00');

            $mutation = $table->buildMutationForSourceEvent($change);

            self::assertNotNull($mutation);
            self::assertSame($type, $mutation->type->value);
            self::assertSame(57, $mutation->rowKey);
            self::assertSame($table->storedRow, $mutation->row);
            self::assertSame([57], $table->readIds);
        }
    }

    /**
     * A deletion needs no row, so nothing is read for it.
     */
    public function testADeletedDeliveryIsRemovedWithoutAReading(): void
    {
        $table = $this->table();

        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(HilosDbContext::notificationDeliveries, '57'));

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Delete, $mutation->type);
        self::assertSame(57, $mutation->rowKey);
        self::assertNull($mutation->row);
        self::assertSame([], $table->readIds);
    }

    /**
     * A delivery gone between the write and the reading has nothing to show.
     */
    public function testADeliveryGoneBeforeTheReadingBuildsNoMutation(): void
    {
        $table = $this->table();

        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '57', [])));
        self::assertSame([57], $table->readIds);
    }

    /**
     * Another collection, a runtime change, a clear and an id that names no delivery reach nothing, and nothing is read.
     */
    public function testAChangeThatIsNotADeliveryIsIgnoredWithoutAReading(): void
    {
        foreach ([
            SourceChange::dbUpdated(HilosDbContext::notifications, '57', []),
            new SourceChange(SourceChange::KIND_RT, HilosDbContext::notificationDeliveries, '57', TableMutationType::Update),
            SourceChange::dbCleared(HilosDbContext::notificationDeliveries),
            SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '', []),
            SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '0', []),
        ] as $change) {
            $table = $this->table();
            $table->storedRow = self::deliveryRow(57, '2026-09-23 10:00:00');

            self::assertNull($table->buildMutationForSourceEvent($change));
            self::assertSame([], $table->readIds);
        }
    }

    /**
     * A delivery names its place in the columns the window's boundaries are written in.
     */
    public function testARowNamesItsPlaceInTheColumnsOfTheSource(): void
    {
        $table = $this->table();
        $query = new TableQueryDTO(sort: $this->resolvedOrder(
            $table,
            new TableSortDTO(HilosNotificationDeliveryTableRow::createdAt, TableConstants::ORDER_DESC),
        ));

        $anchor = $table->anchorForRow(self::deliveryRow(57, '2026-09-23 10:00:00'), $query);

        self::assertNotNull($anchor);
        self::assertSame(['created_at' => '2026-09-23 10:00:00', 'id' => 57], $anchor->values);
    }

    /**
     * A window that asked for no order has no place to name.
     */
    public function testARowOfAWindowWithNoOrderHasNoPlace(): void
    {
        self::assertNull($this->table()->anchorForRow(self::deliveryRow(57, '2026-09-23 10:00:00'), new TableQueryDTO()));
    }

    /**
     * The id of a boundary the database handed over as text is compared as the number it is: delivery 10 at
     * the same moment stands above delivery 9 newest first, where text would put "10" below "9".
     */
    public function testARowIsPlacedAgainstABoundaryWhoseIdCameAsText(): void
    {
        $table = $this->table();
        $query = new TableQueryDTO(sort: $this->resolvedOrder(
            $table,
            new TableSortDTO(HilosNotificationDeliveryTableRow::createdAt, TableConstants::ORDER_DESC),
        ));

        $place = $table->placeRowAgainst(
            self::deliveryRow(10, '2026-09-23 10:00:00'),
            new TableAnchorDTO(['created_at' => '2026-09-23 10:00:00', 'id' => '9']),
            $query,
        );

        self::assertNotNull($place);
        self::assertLessThan(0, $place);
    }

    /**
     * A boundary without the column the order needs, an order by a field without a column, and a window with no
     * order all leave the table unable to say.
     */
    public function testAPlaceTheTableCannotReadIsNotGuessed(): void
    {
        $table = $this->table();
        $row = self::deliveryRow(10, '2026-09-23 10:00:00');
        $byCreatedAt = new TableQueryDTO(sort: TableSortOrderDTO::of(
            new TableSortDTO(HilosNotificationDeliveryTableRow::createdAt, TableConstants::ORDER_DESC),
        ));
        $byTitle = new TableQueryDTO(sort: TableSortOrderDTO::of(
            new TableSortDTO(HilosNotificationDeliveryTableRow::notificationTitle, TableConstants::ORDER_ASC),
        ));
        $boundary = new TableAnchorDTO(['created_at' => '2026-09-23 10:00:00', 'id' => 9]);

        self::assertNull($table->placeRowAgainst($row, new TableAnchorDTO(['id' => 9]), $byCreatedAt));
        self::assertNull($table->placeRowAgainst($row, new TableAnchorDTO(['createdAt' => '2026-09-23 10:00:00', 'rowKey' => 9]), $byCreatedAt));
        self::assertNull($table->placeRowAgainst($row, $boundary, $byTitle));
        self::assertNull($table->placeRowAgainst($row, $boundary, new TableQueryDTO()));
    }

    /**
     * Builds one delivery row of the journal.
     *
     * @param int $id Delivery id
     * @param string $createdAt Moment the delivery was created
     * @param string $status Delivery status
     * @return HilosNotificationDeliveryTableRow Delivery row
     */
    private static function deliveryRow(int $id, string $createdAt, string $status = 'pending'): HilosNotificationDeliveryTableRow
    {
        return new HilosNotificationDeliveryTableRow(
            rowKey: $id,
            createdAt: $createdAt,
            channel: 'email',
            status: $status,
            attempts: 0,
            deliveredAt: null,
            lastError: null,
            userId: 3,
            userLabel: null,
            notificationType: 'welcome',
            notificationTitle: 'Welcome',
        );
    }

    /**
     * Runs a requested order through the table's own map, the way getPage() does before the query.
     *
     * @param HilosNotificationDeliveriesTable $table Table whose map decides
     * @param TableSortDTO ...$components Components of the order as the window requested it
     * @return ?TableSortOrderDTO Order carrying its allowed columns, or null when the table does not
     *     sort by one of its fields
     */
    private function resolvedOrder(HilosNotificationDeliveriesTable $table, TableSortDTO ...$components): ?TableSortOrderDTO
    {
        return TableSortWhitelist::resolve(
            TableSortOrderDTO::of(...$components),
            $table->exposedSortableFields(),
            $table::class,
        );
    }

    /**
     * Builds a table subclass that exposes the protected SQL builders for testing.
     *
     * @return HilosNotificationDeliveriesTable&object{exposedBuildWhere: callable, exposedBuildOrderBy:
     *     callable, exposedSortableFields: callable, exposedRowFromSql: callable, countedWheres: list<array{0: string, 1: list<mixed>}>,
     *     lookedUpWheres: list<array{0: string, 1: list<mixed>}>, readIds: list<int>, storedRow: ?HilosNotificationDeliveryTableRow}
     *     Table with exposed builders
     */
    private function table(): HilosNotificationDeliveriesTable
    {
        return new class extends HilosNotificationDeliveriesTable {
            /** @var list<array{0: string, 1: list<mixed>}> WHERE clauses of the sets the journal was asked to count */
            public array $countedWheres = [];

            /** @var list<array{0: string, 1: list<mixed>}> WHERE clauses one delivery was looked up under */
            public array $lookedUpWheres = [];

            /** @var list<int> Delivery ids a row was read again by */
            public array $readIds = [];

            /** Row the database holds for any id asked, or null when it holds none. */
            public ?HilosNotificationDeliveryTableRow $storedRow = null;

            /**
             * Records the condition a set is counted under instead of running it.
             *
             * @param TableQueryDTO $query Query whose search and filters describe the set
             * @return TableFacetCountDTO Count standing in for the database's
             */
            protected function countSet(TableQueryDTO $query): TableFacetCountDTO
            {
                $this->countedWheres[] = $this->buildWhere($query);

                return new TableFacetCountDTO(0, true);
            }

            /**
             * Records the condition a delivery is looked up under instead of running it.
             *
             * @param string $where The ` WHERE ...` clause of the set with the key condition in it
             * @param list<mixed> $params Parameters bound to the placeholders of the clause, in order
             * @return bool Answer standing in for the database's
             */
            protected function existsInSet(string $where, array $params): bool
            {
                $this->lookedUpWheres[] = [$where, $params];

                return true;
            }

            /**
             * Records which delivery was read again instead of reading it.
             *
             * @param int $deliveryId Delivery id to read
             * @return ?HilosNotificationDeliveryTableRow Row standing in for the database's
             */
            protected function readRow(int $deliveryId): ?HilosNotificationDeliveryTableRow
            {
                $this->readIds[] = $deliveryId;

                return $this->storedRow;
            }

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
