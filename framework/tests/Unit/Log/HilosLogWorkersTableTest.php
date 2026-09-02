<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogWorkerSummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Logs\HilosLogWorkersTableRow;
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

        $this->assertSame(self::NOW - 100, $rows[0]->lastBatchAt);
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
            sort: new TableSortDTO(HilosLogWorkersTableRow::bytes, TableConstants::ORDER_DESC),
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
