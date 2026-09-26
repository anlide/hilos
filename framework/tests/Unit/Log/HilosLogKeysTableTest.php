<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogKeysTableRow;
use PHPUnit\Framework\TestCase;

/**
 * The stream list the by-key screen is built on (HIL-385).
 *
 * A row is one key ON ONE NODE, and everything here follows from that: the row key has to tell one
 * `worker-0.log` on two machines apart, and the filters have to narrow a cluster-wide list rather
 * than one node's.
 *
 * Two decisions of the leaf are held here as well. Every one of the three stream classes is listed
 * and counted — the daemon's own streams among them (HIL-931), so the class filter's All is the sum
 * of the other three and the pager is drawn from the same set the list shows. And a
 * stream whose measuring window has not filled yet carries an unknown growth, which the screen
 * draws as a dash and a descending sort has to put at the BOTTOM — the opposite of what an
 * in-memory ordering does with a null.
 */
final class HilosLogKeysTableTest extends TestCase
{
    /** Fixed instant every fixture picture is measured at (Unix seconds, mid-January 2027). */
    private const int NOW = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        ClusterLogIndexMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterLogIndexMirror::forgetPicture();

        parent::tearDown();
    }

    /**
     * No picture has arrived, so there is nothing to list — and nothing invented to stand in for it.
     */
    public function testAMirrorThatHasHeardNothingListsNoStreams(): void
    {
        $snapshot = new HilosLogKeysTable()->getPage(new TableQueryDTO());

        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $snapshot->totalCount);
    }

    /**
     * The same stream name on two nodes is two files on two machines, so it is two rows — and the
     * row keys have to differ, or a window would hold one of them and drop the other.
     */
    public function testOneStreamNameOnTwoNodesIsTwoRowsWithDifferentKeys(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log')]),
        );

        $rows = $this->rows(new TableQueryDTO());

        $this->assertSame(['node-1', 'node-2'], array_map(static fn($row): ?string => $row->node, $rows));
        $this->assertSame(
            ['node-1:worker-0.log', 'node-2:worker-0.log'],
            array_map(static fn($row): string => $row->rowKey, $rows),
        );
    }

    /**
     * The daemon's own streams are where the errors that bring anyone to this section land, so they
     * are a row like any other: listed, and counted in the total the pager is drawn from.
     */
    public function testTheDaemonsOwnStreamsAreListedAndCounted(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('daemon.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('daemon-error.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('agent-hilos_logs.log', class: LogKeySummary::CLASS_AGENT),
        ]));

        $snapshot = new HilosLogKeysTable()->getPage(new TableQueryDTO());

        $this->assertSame(3, $snapshot->totalCount);
        $this->assertSame(
            ['agent-hilos_logs.log', 'daemon-error.log', 'daemon.log'],
            array_map(static fn($row): string => $row->key, $this->rows(new TableQueryDTO())),
        );
    }

    /**
     * The two figures an operator reads come straight off the summary the node reported: how many
     * archived batches the stream occurs in, and what it weighs across all of them and the live file.
     */
    public function testTheBatchCountAndTheWeightComeFromTheReportedSummary(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('worker-0.log', batchTimestamps: [self::NOW - 200, self::NOW - 100], totalBytes: 4_096),
        ]));

        $row = $this->rows(new TableQueryDTO())[0];

        $this->assertSame(2, $row->batchCount);
        $this->assertSame(4_096, $row->bytes);
        $this->assertTrue($row->live);
    }

    /**
     * A stream that is only in the archive is opened on its newest batch, so the row carries which
     * one that is; a stream that has never been rotated has no batch to name.
     */
    public function testTheRowNamesTheNewestBatchTheStreamIsIn(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('worker-0.log', live: false, batchTimestamps: [self::NOW - 200, self::NOW - 100]),
            $this->summary('worker-1.log', live: true),
        ]));

        $rows = $this->rows(new TableQueryDTO());

        $this->assertSame(self::NOW - 100, $rows[0]->lastBatchAt);
        $this->assertNull($rows[1]->lastBatchAt);
    }

    /**
     * An unmeasured growth is a dash and not a zero, and a descending sort has to leave it at the
     * bottom: the column is opened to see what grows fastest, and the rows nothing is known about
     * are the last thing that answers it.
     */
    public function testAnUnknownGrowthIsDrawnAsNothingAndSortsToTheBottom(): void
    {
        $this->picture($this->node(
            'node-1',
            [$this->summary('agent-a.log'), $this->summary('agent-b.log'), $this->summary('agent-c.log')],
            growthBytesPerDay: ['agent-a.log' => 100, 'agent-b.log' => null, 'agent-c.log' => 500],
        ));

        $rows = $this->rows(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogKeysTableRow::growthPerDay, TableConstants::ORDER_DESC)),
        ));

        $this->assertSame(
            ['agent-c.log', 'agent-a.log', 'agent-b.log'],
            array_map(static fn($row): string => $row->key, $rows),
        );
        $this->assertSame([500, 100, null], array_map(static fn($row): ?int => $row->growthPerDay, $rows));
    }

    public function testTheNodeFilterNarrowsToOneMachinesStreams(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );

        $rows = $this->rows(new TableQueryDTO(filter: [HilosLogKeysTable::FILTER_NODE => 'node-2']));

        $this->assertCount(2, $rows);
        $this->assertSame(['node-2', 'node-2'], array_map(static fn($row): ?string => $row->node, $rows));
    }

    /**
     * The answer about one row is the window's own set: narrowed by the filter, then searched.
     */
    public function testAStreamOfTheFilteredAndSearchedSetIsInIt(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );
        $table = new HilosLogKeysTable();

        $query = $table->scopeSearch(new TableQueryDTO(search: 'worker-1', filter: [HilosLogKeysTable::FILTER_NODE => 'node-2']));

        $this->assertTrue($table->containsRow('node-2:worker-1.log', $query));
    }

    /**
     * A stream the filter leaves out and one the search leaves out are both outside the set.
     */
    public function testAStreamOutsideTheFilterOrTheSearchIsNotInTheSet(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );
        $table = new HilosLogKeysTable();

        $query = $table->scopeSearch(new TableQueryDTO(search: 'worker-1', filter: [HilosLogKeysTable::FILTER_NODE => 'node-2']));

        $this->assertFalse($table->containsRow('node-1:worker-1.log', $query));
        $this->assertFalse($table->containsRow('node-2:worker-0.log', $query));
    }

    public function testTheClassFilterNarrowsToOneKindOfStream(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('agent-hilos_logs.log', class: LogKeySummary::CLASS_AGENT),
            $this->summary('daemon.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('worker-0.log', class: LogKeySummary::CLASS_WORKER),
            $this->summary('worker-monopolistic-chat.log', class: LogKeySummary::CLASS_WORKER),
        ]));

        $workers = $this->rows(new TableQueryDTO(
            filter: [HilosLogKeysTable::FILTER_CLASS => LogKeySummary::CLASS_WORKER],
        ));

        $this->assertSame(
            ['worker-0.log', 'worker-monopolistic-chat.log'],
            array_map(static fn($row): string => $row->key, $workers),
        );

        $daemon = $this->rows(new TableQueryDTO(
            filter: [HilosLogKeysTable::FILTER_CLASS => LogKeySummary::CLASS_DAEMON],
        ));

        $this->assertSame(['daemon.log'], array_map(static fn($row): string => $row->key, $daemon));
    }

    /**
     * The search answers the two names on the screen — the stream and the node.
     */
    public function testTheSearchFindsAStreamByItsNameAndByItsNode(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('agent-hilos_logs.log')]),
            $this->node('node-2', [$this->summary('worker-0.log')]),
        );

        $byKey = $this->rows(new TableQueryDTO(search: 'hilos_logs'));
        $this->assertSame(['agent-hilos_logs.log'], array_map(static fn($row): string => $row->key, $byKey));

        $byNode = $this->rows(new TableQueryDTO(search: 'node-2'));
        $this->assertSame(['worker-0.log'], array_map(static fn($row): string => $row->key, $byNode));
    }

    /**
     * A weight is not a name, and a term short enough to appear in one must not drag every stream
     * into the answer.
     */
    public function testTheSearchDoesNotAnswerToAByteWeight(): void
    {
        $this->picture($this->node('node-1', [$this->summary('worker-0.log', totalBytes: 7_777)]));

        $this->assertSame([], $this->rows(new TableQueryDTO(search: '7777')));
    }

    /**
     * A star makes the term a mask of the whole file name (HIL-1099): it finds the streams whose
     * names have that form, and a piece of a name with no star still finds what it did.
     */
    public function testAStarredTermFindsTheStreamsWhoseWholeNameHasThatForm(): void
    {
        $this->picture($this->node('node-1', $this->mixedStreams()));

        $this->assertSame(['daemon-error-raw.log', 'daemon-raw.log'], $this->keysFound('*-raw.log'));
        $this->assertSame(['agent-hilos_logs.log', 'agent-hilos_users.log'], $this->keysFound('agent-*.log'));
        $this->assertSame(['daemon-error-raw.log', 'daemon-raw.log'], $this->keysFound('raw'));
        // The mask holds the end of the name as well as its start: `raw` sits inside both raw
        // stream names and ends neither of them.
        $this->assertSame([], $this->keysFound('*raw'));
    }

    /**
     * The node is searched as a piece of its name whatever the term: only the stream name is a mask.
     */
    public function testAStarredTermIsNoMaskOfTheNode(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log')]),
        );

        $this->assertSame([], $this->keysFound('node-*'));
    }

    /**
     * The counts beside the options and the answer about one row come out of the same masked search
     * the window runs, so they agree with it.
     */
    public function testTheCountsAndTheRowQuestionFollowTheMask(): void
    {
        $this->picture(
            $this->node('node-1', $this->mixedStreams()),
            $this->node('node-2', [$this->summary('daemon-raw.log', class: LogKeySummary::CLASS_DAEMON)]),
        );
        $table = new HilosLogKeysTable();
        $search = '*-raw.log';

        $facets = $table->facetCounts(
            $table->scopeSearch(new TableQueryDTO(search: $search)),
            [
                HilosLogKeysTable::FILTER_NODE => ['node-1', 'node-2'],
                HilosLogKeysTable::FILTER_CLASS => [LogKeySummary::CLASS_DAEMON, LogKeySummary::CLASS_AGENT],
            ],
        );

        $node = $facets[HilosLogKeysTable::FILTER_NODE];
        $this->assertSame($this->totalOf($search), $node[TableConstants::FACET_KEY_ANY]->count);
        $this->assertSame(
            $this->totalOf($search, [HilosLogKeysTable::FILTER_NODE => 'node-1']),
            $node[TableConstants::FACET_KEY_OPTIONS]['node-1']->count,
        );
        $this->assertSame(
            $this->totalOf($search, [HilosLogKeysTable::FILTER_NODE => 'node-2']),
            $node[TableConstants::FACET_KEY_OPTIONS]['node-2']->count,
        );
        $class = $facets[HilosLogKeysTable::FILTER_CLASS];
        $this->assertSame(3, $class[TableConstants::FACET_KEY_OPTIONS][LogKeySummary::CLASS_DAEMON]->count);
        $this->assertSame(0, $class[TableConstants::FACET_KEY_OPTIONS][LogKeySummary::CLASS_AGENT]->count);

        $query = $table->scopeSearch(new TableQueryDTO(search: $search));
        $this->assertTrue($table->containsRow('node-2:daemon-raw.log', $query));
        $this->assertFalse($table->containsRow('node-1:daemon.log', $query));
    }

    /**
     * The number beside an option is the total its window would show once picked: the node counts are
     * taken under the chosen class, and the class counts on every class the search left.
     */
    public function testTheOptionCountsAreTheTotalsTheirWindowsWouldShow(): void
    {
        $this->picture(
            $this->node('node-1', [
                $this->summary('api.log'),
                $this->summary('worker-0.log', LogKeySummary::CLASS_WORKER),
                $this->summary('daemon.log', LogKeySummary::CLASS_DAEMON),
            ]),
            $this->node('node-2', [$this->summary('api.log')]),
        );
        $table = new HilosLogKeysTable();

        $facets = $table->facetCounts(
            $table->scopeSearch(new TableQueryDTO(
                search: 'node-1',
                filter: [HilosLogKeysTable::FILTER_CLASS => LogKeySummary::CLASS_AGENT],
            )),
            [
                HilosLogKeysTable::FILTER_NODE => ['node-1', 'node-2'],
                HilosLogKeysTable::FILTER_CLASS => [
                    LogKeySummary::CLASS_DAEMON,
                    LogKeySummary::CLASS_AGENT,
                    LogKeySummary::CLASS_WORKER,
                ],
                'growth' => ['fast'],
            ],
        );

        $this->assertSame([HilosLogKeysTable::FILTER_NODE, HilosLogKeysTable::FILTER_CLASS], array_keys($facets));
        $node = $facets[HilosLogKeysTable::FILTER_NODE];
        $this->assertSame(1, $node[TableConstants::FACET_KEY_ANY]->count);
        $this->assertSame(1, $node[TableConstants::FACET_KEY_OPTIONS]['node-1']->count);
        $this->assertSame(0, $node[TableConstants::FACET_KEY_OPTIONS]['node-2']->count);
        $class = $facets[HilosLogKeysTable::FILTER_CLASS];
        $this->assertSame(3, $class[TableConstants::FACET_KEY_ANY]->count);
        $this->assertTrue($class[TableConstants::FACET_KEY_ANY]->exact);
        $this->assertSame(1, $class[TableConstants::FACET_KEY_OPTIONS][LogKeySummary::CLASS_DAEMON]->count);
        $this->assertSame(1, $class[TableConstants::FACET_KEY_OPTIONS][LogKeySummary::CLASS_AGENT]->count);
        $this->assertSame(1, $class[TableConstants::FACET_KEY_OPTIONS][LogKeySummary::CLASS_WORKER]->count);
    }

    /**
     * The streams of one node of every class, the daemon's raw ones among them.
     *
     * @return list<LogKeySummary> Summaries the node reports
     */
    private function mixedStreams(): array
    {
        return [
            $this->summary('daemon.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('daemon-raw.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('daemon-error.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('daemon-error-raw.log', class: LogKeySummary::CLASS_DAEMON),
            $this->summary('agent-hilos_logs.log'),
            $this->summary('agent-hilos_users.log'),
            $this->summary('worker-0.log', class: LogKeySummary::CLASS_WORKER),
            $this->summary('worker-monopolistic-chat.log', class: LogKeySummary::CLASS_WORKER),
        ];
    }

    /**
     * Searches the whole list by one term and reads the stream names the window holds.
     *
     * @param string $search Term the window carries
     * @return list<string> Stream names found, in the window's order
     */
    private function keysFound(string $search): array
    {
        return array_map(static fn($row): string => $row->key, $this->rows(new TableQueryDTO(search: $search)));
    }

    /**
     * Reads the total a window searched by one term and narrowed by filters would show.
     *
     * @param string $search Term the window carries
     * @param array<string, mixed> $filter Filters the window is narrowed by
     * @return int Total count of that window
     */
    private function totalOf(string $search, array $filter = []): int
    {
        return new HilosLogKeysTable()->getPage(new TableQueryDTO(search: $search, filter: $filter))->totalCount;
    }

    /**
     * Runs one window and hands back its typed rows.
     *
     * @param TableQueryDTO $query Window query
     * @return list<HilosLogKeysTableRow> Rows of that window
     */
    private function rows(TableQueryDTO $query): array
    {
        /** @var list<HilosLogKeysTableRow> $rows */
        $rows = new HilosLogKeysTable()->getPage($query)->rows;

        return $rows;
    }

    /**
     * Files a whole cluster picture into the mirror the table reads.
     *
     * @param ClusterLogNodeSlot ...$slots Node slots the picture is made of
     */
    private function picture(ClusterLogNodeSlot ...$slots): void
    {
        ClusterLogIndexMirror::applyPortion(
            ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true),
        );
    }

    /**
     * Builds one node's slot out of the stream summaries that node reported.
     *
     * @param string $nodeId Node the slot belongs to
     * @param list<LogKeySummary> $keys Streams the node has, as it reports them
     * @param array<string, ?int> $growthBytesPerDay Key → bytes over the last day, null while the window fills
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function node(string $nodeId, array $keys, array $growthBytesPerDay = []): ClusterLogNodeSlot
    {
        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: true,
                sampledAt: self::NOW,
                batches: [],
                keys: $keys,
                workers: [],
                growthBytesPerDay: $growthBytesPerDay,
            ),
            receivedAt: self::NOW,
        );
    }

    /**
     * Builds one stream summary the way a node reports it.
     *
     * @param string $key File basename of the stream
     * @param string $class Stream class
     * @param bool $live Whether the stream is still being written
     * @param list<int> $batchTimestamps Batches the stream occurs in
     * @param int $totalBytes Weight across the live file and every batch
     * @return LogKeySummary Summary as the node's walk produced it
     */
    private function summary(
        string $key,
        string $class = LogKeySummary::CLASS_AGENT,
        bool $live = true,
        array $batchTimestamps = [],
        int $totalBytes = 1_024,
    ): LogKeySummary {
        return new LogKeySummary(
            key: $key,
            class: $class,
            live: $live,
            batchTimestamps: $batchTimestamps,
            totalBytes: $totalBytes,
        );
    }
}
