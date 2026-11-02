<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Logs\HilosLogWorkersTableRow;
use Hilos\Tests\Unit\Fixtures\LogsRuntimeFixture;
use PHPUnit\Framework\TestCase;

/**
 * The worker-stream list the by-worker screen is built on (HIL-386).
 *
 * A row is one worker stream ON ONE NODE, and everything here follows from that: the row key has to
 * tell one `worker-0.log` on two machines apart, and the filters have to narrow a cluster-wide list
 * rather than one node's.
 *
 * The screen exists for one distinction the by-key screen drops — monopolistic worker or ordinary
 * one — so the rows are taken from the mirror's `workers` branch and not from its `keys` one. That
 * choice is what keeps the agent and daemon streams out of the window altogether: they are not in
 * the branch at all, so they take no place in the total count either.
 */
final class HilosLogWorkersTableTest extends TestCase
{
    use LogsRuntimeFixture;

    /** Fixed instant every fixture picture is measured at (Unix seconds, mid-January 2027). */
    private const int NOW = 1_800_000_000;

    public function testRegistryNamesDoNotChangeNodeFiltersOrRowKeys(): void
    {
        $this->publishLogNode('node-1', 'Archive host', online: false);
        $this->picture($this->node('node-1', [$this->summary('worker-0.log')]), $this->node('node-2', [$this->summary('worker-0.log')]));
        $rows = $this->rows(new TableQueryDTO());
        $this->assertSame(['Archive host', null], array_map(static fn ($row): ?string => $row->nodeName, $rows));
        $this->assertSame($rows[0]->toArray(), HilosLogWorkersTableRow::fromArray($rows[0]->toArray())->toArray());
        $filtered = $this->rows(new TableQueryDTO(filter: [HilosLogWorkersTable::FILTER_NODE => 'node-1']));
        $this->assertCount(1, $filtered);
        $this->assertSame('node-1', $filtered[0]->node);
        $this->assertSame('Archive host', $filtered[0]->nodeName);
        $before = $filtered[0]->toArray();

        $this->publishLogNode('node-1', 'Renamed host', online: false);
        $after = $this->rows(new TableQueryDTO(filter: [HilosLogWorkersTable::FILTER_NODE => 'node-1']))[0]->toArray();
        $before['nodeName'] = 'Renamed host';
        $this->assertSame($before, $after);
    }

    public function testNodeSortUsesNamesAndThenIdsBeforeCuttingTheWindow(): void
    {
        $this->publishLogNode('node-z', 'Alpha');
        $this->publishLogNode('node-a', 'Zulu');
        $this->publishLogNode('node-2', 'Same');
        $this->publishLogNode('node-10', 'Same');
        $this->picture(
            $this->node('node-a', [$this->summary('worker-0.log')]),
            $this->node('node-z', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log')]),
            $this->node('node-10', [$this->summary('worker-0.log')]),
        );
        $table = new HilosLogWorkersTable();
        $sort = TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node, TableConstants::ORDER_ASC));
        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2));
        $this->assertSame(['node-z', 'node-10'], array_map(static fn ($row): string => $row->node, $first->rows));
        $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $first->lastAnchor));
        $this->assertSame(['node-2', 'node-a'], array_map(static fn ($row): string => $row->node, $next->rows));
        $this->assertSame(2, $next->rowsBefore);
        $this->assertSame(4, $next->totalCount);
        $descending = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node, TableConstants::ORDER_DESC)),
            limit: 4,
        ));
        $this->assertSame(
            ['node-a', 'node-2', 'node-10', 'node-z'],
            array_map(static fn ($row): string => $row->node, $descending->rows),
        );
    }

    public function testNodeAnchorSurvivesRemovalInEitherSortDirection(): void
    {
        $this->publishLogNode('a', 'Alpha');
        $this->publishLogNode('b', 'Beta');
        $this->publishLogNode('c', 'Gamma');
        $table = new HilosLogWorkersTable();
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            $this->picture(
                $this->node('a', [$this->summary('worker-0.log')]),
                $this->node('b', [$this->summary('worker-0.log')]),
                $this->node('c', [$this->summary('worker-0.log')]),
            );
            $sort = TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node, $direction));
            $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1));
            $middle = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1, anchor: $first->lastAnchor));
            self::assertSame('b', $middle->rows[0]->node);
            self::assertSame([
                'nodeName' => 'Beta', 'node' => 'b', 'rowKey' => $middle->rows[0]->rowKey,
            ], $middle->lastAnchor->values);
            self::assertSame($middle->lastAnchor->values,
                $table->anchorForRow($middle->rows[0], new TableQueryDTO(sort: $sort))->values);

            $this->picture($this->node('a', [$this->summary('worker-0.log')]), $this->node('c', [$this->summary('worker-0.log')]));
            $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1, anchor: $middle->lastAnchor));
            $previous = $table->getPage(new TableQueryDTO(
                sort: $sort, limit: 1, anchor: $middle->firstAnchor, anchorDirection: TableAnchorDirection::Before,
            ));
            self::assertSame($direction === TableConstants::ORDER_ASC ? 'c' : 'a', $next->rows[0]->node);
            self::assertSame($direction === TableConstants::ORDER_ASC ? 'a' : 'c', $previous->rows[0]->node);
            self::assertSame(1, $next->rowsBefore);
            self::assertSame(0, $previous->rowsBefore);
            self::assertSame($middle->lastAnchor, $next->frame->before);
            self::assertGreaterThan(0, $table->placeRowAgainst($next->rows[0], $middle->lastAnchor, new TableQueryDTO(sort: $sort)));
            self::assertLessThan(0, $table->placeRowAgainst($previous->rows[0], $middle->firstAnchor, new TableQueryDTO(sort: $sort)));
        }
    }

    public function testNameOnlyChangeHasANewPlaceAtTheSameRowKeyAndWindowAddress(): void
    {
        $this->publishLogNode('a', 'Alpha');
        $this->publishLogNode('b', 'Beta');
        $this->publishLogNode('c', 'Gamma');
        $this->picture(
            $this->node('a', [$this->summary('worker-0.log')]),
            $this->node('b', [$this->summary('worker-0.log')]),
            $this->node('c', [$this->summary('worker-0.log')]),
        );
        $table = new HilosLogWorkersTable();
        $sort = TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node));
        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1));
        $held = new TableQueryDTO(sort: $sort, limit: 1, anchor: $first->lastAnchor);
        $before = $table->getPage($held);
        $this->publishLogNode('b', 'Zulu');
        $after = $table->getPage($held);
        self::assertSame('c', $after->rows[0]->node);
        self::assertSame($held->anchor, $after->frame->before);
        $renamed = $table->getPage(new TableQueryDTO(sort: $sort, filter: ['node' => 'b']))->rows[0];
        self::assertSame($before->rows[0]->rowKey, $renamed->rowKey);
        self::assertSame('Beta', $before->lastAnchor->values['nodeName']);
        self::assertSame('Zulu', $table->anchorForRow($renamed, $held)->values['nodeName']);
        self::assertGreaterThan(0, $table->placeRowAgainst($renamed, $before->lastAnchor, $held));
    }

    public function testNameIdAndRowKeyTiesHaveTheSameWindowAndPlacementOrder(): void
    {
        foreach (['node', 'node-1', 'node-10', 'node-2'] as $id) {
            $this->publishLogNode($id, 'Same');
        }
        $this->picture(
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
            $this->node('node-10', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
            $this->node('node-1', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
            $this->node('node', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );
        $table = new HilosLogWorkersTable();
        $keys = ['node:worker-0.log', 'node:worker-1.log', 'node-1:worker-0.log', 'node-1:worker-1.log',
            'node-10:worker-0.log', 'node-10:worker-1.log', 'node-2:worker-0.log', 'node-2:worker-1.log'];
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            $sort = TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node, $direction));
            $snapshot = $table->getPage(new TableQueryDTO(sort: $sort));
            self::assertSame($direction === TableConstants::ORDER_ASC ? $keys : array_reverse($keys),
                array_column($snapshot->rows, 'rowKey'));
            foreach ($snapshot->rows as $index => $row) {
                $query = new TableQueryDTO(sort: $sort, limit: 1, pageIndex: $index);
                $page = $table->getPage($query);
                self::assertSame($row->rowKey, $page->rows[0]->rowKey);
                self::assertSame($page->lastAnchor->values, $table->anchorForRow($row, $query)->values);
                self::assertSame(0, $table->placeRowAgainst($row, $page->lastAnchor, $query));
                if (isset($snapshot->rows[$index + 1])) {
                    self::assertGreaterThan(0, $table->placeRowAgainst($snapshot->rows[$index + 1], $page->lastAnchor, $query));
                }
            }
        }
        $query = new TableQueryDTO(
            search: 'node-1', sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node)),
            limit: 1, filter: ['node' => 'node-1'], pageIndex: 1,
            shownFields: array_keys($snapshot->rows[0]->toArray()),
        );
        $filtered = $table->getPage($query);
        self::assertSame($keys[3], $filtered->rows[0]->rowKey);
        self::assertSame(2, $filtered->totalCount);
        self::assertSame(1, $filtered->rowsBefore);
        self::assertSame(1, $filtered->limit);
        self::assertSame([], $table->getPage(new TableQueryDTO(search: 'absent-stream', sort: $query->sort))->rows);
    }

    public function testUnknownNameKeepsNullOnWireAndUsesIdForLogOrdering(): void
    {
        $this->publishLogNode('z', 'Zulu');
        $this->picture($this->node('z', [$this->summary('worker-0.log')]), $this->node('A-unknown', [$this->summary('worker-0.log')]));
        $table = new HilosLogWorkersTable();
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            $query = new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::node, $direction)));
            $snapshot = $table->getPage($query);
            self::assertSame($direction === TableConstants::ORDER_ASC ? ['A-unknown', 'z'] : ['z', 'A-unknown'],
                array_column($snapshot->rows, 'node'));
            $unknown = $snapshot->rows[$direction === TableConstants::ORDER_ASC ? 0 : 1];
            self::assertNull($unknown->nodeName);
            $anchor = $table->anchorForRow($unknown, $query);
            self::assertArrayHasKey('nodeName', $anchor->values);
            self::assertNull($anchor->values['nodeName']);
            self::assertSame(0, $table->placeRowAgainst($unknown, $anchor, $query));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mountLogsRuntime();
        ClusterLogIndexMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterLogIndexMirror::forgetPicture();

        $this->restoreLogsRuntime();
        parent::tearDown();
    }

    /**
     * No picture has arrived, so there is nothing to list — and nothing invented to stand in for it.
     */
    public function testAMirrorThatHasHeardNothingListsNoStreams(): void
    {
        $snapshot = new HilosLogWorkersTable()->getPage(new TableQueryDTO());

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
     * The distinction the screen was opened for: the monopolistic worker and the ordinary ones are
     * told apart by one field, which the badge, the filter and the wire all read the same way.
     */
    public function testTheMonopolisticWorkerIsToldApartFromTheOrdinaryOnes(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('worker-0.log'),
            $this->summary('worker-monopolistic-truth.log', monopolistic: true),
        ]));

        $rows = $this->rows(new TableQueryDTO());

        $this->assertSame(
            [HilosLogWorkersTable::TYPE_REGULAR, HilosLogWorkersTable::TYPE_MONOPOLISTIC],
            array_map(static fn($row): string => $row->type, $rows),
        );
    }

    public function testStandaloneRowUsesItsEffectiveNodeIdInTheKey(): void
    {
        $this->picture($this->node('standalone', [$this->summary('worker-0.log')]));

        $row = $this->rows(new TableQueryDTO())[0];
        $this->assertSame('standalone', $row->node);
        $this->assertSame('standalone:worker-0.log', $row->rowKey);
    }

    /**
     * The screen is about workers by definition, and the agent and daemon streams are not in the
     * branch it reads. Left in the count they would make the pager promise a page that holds nothing.
     */
    public function testAgentAndDaemonStreamsAreNeitherListedNorCounted(): void
    {
        $this->picture($this->node(
            'node-1',
            [$this->summary('worker-0.log')],
            keys: [
                new LogKeySummary('daemon.log', LogKeySummary::CLASS_DAEMON, true, [], 1_024),
                new LogKeySummary('agent-hilos_logs.log', LogKeySummary::CLASS_AGENT, true, [], 1_024),
                new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], 1_024),
            ],
        ));

        $snapshot = new HilosLogWorkersTable()->getPage(new TableQueryDTO());

        $this->assertSame(1, $snapshot->totalCount);
        $this->assertSame(
            ['worker-0.log'],
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

        $this->assertSame(1_799_999_900_000, $rows[0]->lastBatchAt);
        $this->assertSame(1_799_999_900_000, $rows[0]->toArray()['lastBatchAt']);
        $this->assertNull($rows[1]->toArray()['lastBatchAt']);
        $this->assertNull($rows[1]->lastBatchAt);
    }

    /**
     * The screen answers "who took the space", so the weight orders by itself in both directions —
     * there is no unknown among these four fields to work around.
     */
    public function testTheWeightOrdersTheWindowByItself(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('worker-0.log', totalBytes: 100),
            $this->summary('worker-1.log', totalBytes: 900),
            $this->summary('worker-2.log', totalBytes: 500),
        ]));

        $rows = $this->rows(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogWorkersTableRow::bytes, TableConstants::ORDER_DESC)),
        ));

        $this->assertSame([900, 500, 100], array_map(static fn($row): int => $row->bytes, $rows));
    }

    public function testTheNodeFilterNarrowsToOneMachinesStreams(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );

        $rows = $this->rows(new TableQueryDTO(filter: [HilosLogWorkersTable::FILTER_NODE => 'node-2']));

        $this->assertCount(2, $rows);
        $this->assertSame(['node-2', 'node-2'], array_map(static fn($row): ?string => $row->node, $rows));
    }

    public function testAStreamOfTheFilteredSetIsInIt(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );

        $query = new TableQueryDTO(filter: [HilosLogWorkersTable::FILTER_NODE => 'node-2']);

        $this->assertTrue(new HilosLogWorkersTable()->containsRow('node-2:worker-1.log', $query));
    }

    public function testAStreamTheFilterLeavesOutIsNotInTheSet(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log')]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-1.log')]),
        );

        $query = new TableQueryDTO(filter: [HilosLogWorkersTable::FILTER_NODE => 'node-2']);

        $this->assertFalse(new HilosLogWorkersTable()->containsRow('node-1:worker-0.log', $query));
    }

    /**
     * The panel offers two buttons, so only one value narrows; anything else is "all", the way an
     * unknown state reads on the rotation history.
     */
    public function testOnlyTheMonopolisticValueNarrowsTheTypeFilter(): void
    {
        $this->picture($this->node('node-1', [
            $this->summary('worker-0.log'),
            $this->summary('worker-monopolistic-truth.log', monopolistic: true),
        ]));

        $monopolistic = $this->rows(new TableQueryDTO(
            filter: [HilosLogWorkersTable::FILTER_TYPE => HilosLogWorkersTable::TYPE_MONOPOLISTIC],
        ));
        $this->assertSame(
            ['worker-monopolistic-truth.log'],
            array_map(static fn($row): string => $row->key, $monopolistic),
        );

        $regular = $this->rows(new TableQueryDTO(
            filter: [HilosLogWorkersTable::FILTER_TYPE => HilosLogWorkersTable::TYPE_REGULAR],
        ));
        $this->assertCount(2, $regular);
    }

    /**
     * The search answers the two names on the screen — the stream and the node.
     */
    public function testTheSearchFindsAStreamByItsNameAndByItsNode(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-monopolistic-truth.log', monopolistic: true)]),
            $this->node('node-2', [$this->summary('worker-0.log')]),
        );

        $byKey = $this->rows(new TableQueryDTO(search: 'monopolistic'));
        $this->assertSame(
            ['worker-monopolistic-truth.log'],
            array_map(static fn($row): string => $row->key, $byKey),
        );

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
     * The window of monopolistic streams on node 2 is empty, and the type counts still say how many
     * streams node 2 has: a filter's own choice never zeroes the options it offers.
     */
    public function testAFiltersOwnChoiceDoesNotZeroItsOptions(): void
    {
        $this->picture(
            $this->node('node-1', [$this->summary('worker-0.log'), $this->summary('worker-1.log', monopolistic: true)]),
            $this->node('node-2', [$this->summary('worker-0.log'), $this->summary('worker-2.log')]),
        );

        $facets = new HilosLogWorkersTable()->facetCounts(
            new TableQueryDTO(filter: [
                HilosLogWorkersTable::FILTER_NODE => 'node-2',
                HilosLogWorkersTable::FILTER_TYPE => HilosLogWorkersTable::TYPE_MONOPOLISTIC,
            ]),
            [
                HilosLogWorkersTable::FILTER_NODE => ['node-1', 'node-2', 'node-3'],
                HilosLogWorkersTable::FILTER_TYPE => [HilosLogWorkersTable::TYPE_REGULAR, HilosLogWorkersTable::TYPE_MONOPOLISTIC],
            ],
        );

        $node = $facets[HilosLogWorkersTable::FILTER_NODE];
        $this->assertSame(1, $node[TableConstants::FACET_KEY_ANY]->count);
        $this->assertSame(1, $node[TableConstants::FACET_KEY_OPTIONS]['node-1']->count);
        $this->assertSame(0, $node[TableConstants::FACET_KEY_OPTIONS]['node-2']->count);
        $this->assertSame(0, $node[TableConstants::FACET_KEY_OPTIONS]['node-3']->count);
        $type = $facets[HilosLogWorkersTable::FILTER_TYPE];
        $this->assertSame(2, $type[TableConstants::FACET_KEY_ANY]->count);
        // The regular value narrows nothing, so its count is the whole node's, the window it would show.
        $this->assertSame(2, $type[TableConstants::FACET_KEY_OPTIONS][HilosLogWorkersTable::TYPE_REGULAR]->count);
        $this->assertSame(0, $type[TableConstants::FACET_KEY_OPTIONS][HilosLogWorkersTable::TYPE_MONOPOLISTIC]->count);
    }

    /**
     * Runs one window and hands back its typed rows.
     *
     * @param TableQueryDTO $query Window query
     * @return list<HilosLogWorkersTableRow> Rows of that window
     */
    private function rows(TableQueryDTO $query): array
    {
        /** @var list<HilosLogWorkersTableRow> $rows */
        $rows = new HilosLogWorkersTable()->getPage($query)->rows;

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
     * Builds one node's slot out of the worker summaries that node reported.
     *
     * @param string $nodeId Node the slot belongs to
     * @param list<LogWorkerSummary> $workers Worker streams the node has, as it reports them
     * @param list<LogKeySummary> $keys Folded key list of the same node, which this screen does not read
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function node(string $nodeId, array $workers, array $keys = []): ClusterLogNodeSlot
    {
        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: true,
                sampledAt: self::NOW,
                batches: [],
                keys: $keys,
                workers: $workers,
                growthBytesPerDay: [],
            ),
            receivedAt: self::NOW,
        );
    }

    /**
     * Builds one worker summary the way a node reports it.
     *
     * @param string $key File basename of the stream
     * @param bool $monopolistic Whether the stream belongs to the monopolistic worker
     * @param bool $live Whether the stream is still being written
     * @param list<int> $batchTimestamps Batches the stream occurs in
     * @param int $totalBytes Weight across the live file and every batch
     * @return LogWorkerSummary Summary as the node's walk produced it
     */
    private function summary(
        string $key,
        bool $monopolistic = false,
        bool $live = true,
        array $batchTimestamps = [],
        int $totalBytes = 1_024,
    ): LogWorkerSummary {
        return new LogWorkerSummary(
            key: $key,
            monopolistic: $monopolistic,
            live: $live,
            batchTimestamps: $batchTimestamps,
            totalBytes: $totalBytes,
        );
    }
}
