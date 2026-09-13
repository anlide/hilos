<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use PHPUnit\Framework\TestCase;

/**
 * Counts beside the options of a filter: which sets are counted, and which are not (HIL-240).
 *
 * The table counting the set is faked here by a filter over a handful of rows, because what the
 * tally owns is the choice of sets and not the counting: every option against the set with its own
 * filter swapped for that option and every other filter standing, the "any" count against the set
 * with the filter lifted, and nothing at all for a list too long to count.
 */
final class TableFacetTallyTest extends TestCase
{
    /** Filter key of the channel filter the fixture rows carry. */
    private const string CHANNEL = 'channel';

    /** Filter key of the status filter the fixture rows carry. */
    private const string STATUS = 'status';

    /** @var list<TableQueryDTO> Sets the fake table was asked to count, in the order it was asked */
    private array $counted = [];

    /**
     * Picking a status must not collapse the channel counts to zero: a channel is counted against the
     * set the status narrowed, with the channel filter itself swapped for that channel.
     */
    public function testAnOptionIsCountedWithoutItsOwnFilterAndWithEveryOther(): void
    {
        $facets = TableFacetTally::forFilters(
            new TableQueryDTO(filter: [self::CHANNEL => 'sms', self::STATUS => 'failed']),
            [self::CHANNEL => ['email', 'sms', 'push'], self::STATUS => ['failed', 'sent']],
            $this->countRows(...),
        );

        self::assertSame(
            ['any' => 3, 'email' => 1, 'sms' => 2, 'push' => 0],
            self::counts($facets[self::CHANNEL]),
        );
        self::assertSame(
            ['any' => 2, 'failed' => 2, 'sent' => 0],
            self::counts($facets[self::STATUS]),
        );
    }

    /**
     * An option nobody in the set carries answers zero and keeps its place: "this would leave nothing"
     * is exactly what the number is shown for.
     */
    public function testAnOptionTheSetDoesNotCarryAnswersZeroRatherThanDisappearing(): void
    {
        $facets = TableFacetTally::forFilters(
            new TableQueryDTO(),
            [self::CHANNEL => ['telegram']],
            $this->countRows(...),
        );

        self::assertArrayHasKey('telegram', $facets[self::CHANNEL][TableConstants::FACET_KEY_OPTIONS]);
        self::assertSame(0, $facets[self::CHANNEL][TableConstants::FACET_KEY_OPTIONS]['telegram']->count);
        self::assertTrue($facets[self::CHANNEL][TableConstants::FACET_KEY_OPTIONS]['telegram']->exact);
    }

    /**
     * A list longer than the limit is neither counted nor answered, so a dropdown of a hundred options
     * does not order a hundred counts on every change of the set.
     */
    public function testAListLongerThanTheLimitIsNotCountedAtAll(): void
    {
        $tooMany = array_map(
            static fn(int $index): string => "channel-{$index}",
            range(0, TableConstants::FACET_OPTION_LIMIT),
        );

        $facets = TableFacetTally::forFilters(
            new TableQueryDTO(),
            [self::CHANNEL => $tooMany, self::STATUS => ['failed']],
            $this->countRows(...),
        );

        self::assertSame([self::STATUS], array_keys($facets));
        self::assertCount(2, $this->counted);
    }

    /**
     * What is counted is a set, so the order, the size and the address of the window never reach the
     * table - while the search and the fields it was scoped to do, since they narrow the set.
     */
    public function testTheTableIsHandedTheSetWithoutTheWindow(): void
    {
        TableFacetTally::forFilters(
            new TableQueryDTO(
                search: 'mail',
                sort: TableSortOrderDTO::of(new TableSortDTO(self::CHANNEL, TableConstants::ORDER_DESC)),
                limit: 25,
                filter: [self::STATUS => 'failed'],
                anchor: new TableAnchorDTO([self::CHANNEL => 'email']),
                pageIndex: 3,
                searchableFields: [self::CHANNEL => self::CHANNEL],
            ),
            [self::CHANNEL => ['email']],
            $this->countRows(...),
        );

        foreach ($this->counted as $set) {
            self::assertNull($set->sort);
            self::assertSame(TableConstants::NO_LIMIT, $set->limit);
            self::assertNull($set->anchor);
            self::assertNull($set->pageIndex);
            self::assertSame('mail', $set->search);
            self::assertSame([self::CHANNEL => self::CHANNEL], $set->searchableFields);
        }
        self::assertSame([self::STATUS => 'failed'], $this->counted[0]->filter);
        self::assertSame([self::STATUS => 'failed', self::CHANNEL => 'email'], $this->counted[1]->filter);
    }

    /**
     * The key of an option is its value as the client writes it, so a flag is keyed by a word and not
     * by the `1` and the empty string PHP would make of it.
     */
    public function testAFlagOptionIsKeyedByTheWordTheClientWrites(): void
    {
        $facets = TableFacetTally::forFilters(
            new TableQueryDTO(),
            [self::STATUS => [true, false, 7]],
            $this->countRows(...),
        );

        self::assertSame(
            ['true', 'false', 7],
            array_keys($facets[self::STATUS][TableConstants::FACET_KEY_OPTIONS]),
        );
    }

    /**
     * A table that never heard of the counts says so, and says it with null rather than an empty map.
     */
    public function testATableWithoutAnImplementationCannotCount(): void
    {
        $table = new class extends TableDefinition {
            protected function query(TableQueryDTO $query): TableSnapshotDTO
            {
                return new TableSnapshotDTO(rows: [], totalCount: 0, totalExact: true, limit: $query->limit);
            }
        };

        self::assertNull($table->facetCounts(new TableQueryDTO(), [self::CHANNEL => ['email']]));
    }

    /**
     * Counts the fixture rows a set's filters narrow to, the way a table counts its own set.
     *
     * @param TableQueryDTO $set Set to count
     * @return TableFacetCountDTO Rows of the fixture in that set
     */
    private function countRows(TableQueryDTO $set): TableFacetCountDTO
    {
        $this->counted[] = $set;
        $rows = [
            [self::CHANNEL => 'email', self::STATUS => 'failed'],
            [self::CHANNEL => 'email', self::STATUS => 'sent'],
            [self::CHANNEL => 'sms', self::STATUS => 'failed'],
            [self::CHANNEL => 'sms', self::STATUS => 'failed'],
            [self::CHANNEL => 'push', self::STATUS => 'sent'],
        ];

        $matching = array_filter(
            $rows,
            static fn(array $row): bool => array_all(
                $set->filter,
                static fn(mixed $value, string $key): bool => $row[$key] === $value,
            ),
        );

        return new TableFacetCountDTO(count($matching), true);
    }

    /**
     * Flattens one filter's counts into option => number, "any" first.
     *
     * @param array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>} $facet Counts of one filter
     * @return array<array-key, int> Numbers by option
     */
    private static function counts(array $facet): array
    {
        return [TableConstants::FACET_KEY_ANY => $facet[TableConstants::FACET_KEY_ANY]->count]
            + array_map(
                static fn(TableFacetCountDTO $count): int => $count->count,
                $facet[TableConstants::FACET_KEY_OPTIONS],
            );
    }
}
