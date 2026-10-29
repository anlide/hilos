<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\ClusterContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogNodesTableRow;
use PHPUnit\Framework\TestCase;

/** Tests the common node projection and the bounded window built from the cluster mirror. */
final class HilosLogNodesTableTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private ?ClusterContext $previousCluster = null;
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousClusterEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        $this->previousClusterEnabled = getenv('CLUSTER_ENABLED');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        putenv('CLUSTER_ENABLED=true');
        ClusterLogIndexMirror::forgetPicture();
    }

    protected function tearDown(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        putenv($this->previousClusterEnabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $this->previousClusterEnabled);
        parent::tearDown();
    }

    public function testUnknownAndEmptyPicturesHaveNoInventedNodeRows(): void
    {
        $table = new HilosLogNodesTable();
        $snapshot = $table->getPage(new TableQueryDTO(limit: 25));
        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $snapshot->totalCount);
        $this->assertTrue($snapshot->totalExact);

        $this->picture();
        $snapshot = $table->getPage(new TableQueryDTO(limit: 25));
        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $snapshot->totalCount);
        $this->assertTrue($snapshot->totalExact);
    }

    public function testStandaloneHasNoNodeTableEvenWhenTheMirrorHasANamedNode(): void
    {
        putenv('CLUSTER_ENABLED=false');
        $this->picture(self::node('standalone'));

        $snapshot = new HilosLogNodesTable()->getPage(new TableQueryDTO(limit: 25));

        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $snapshot->totalCount);
    }

    public function testTwoNodesHaveDistinctKeysAndKeepTheMirrorsStableOrder(): void
    {
        $this->picture(self::node('node-z'), self::node('node-a'));
        $table = new HilosLogNodesTable();

        $snapshot = $table->getPage(new TableQueryDTO());

        $this->assertSame(['node-a', 'node-z'], array_map(static fn($row): string => $row->getRowKey(), $snapshot->rows));
        $this->assertSame(2, $snapshot->totalCount);
        $this->assertSame('nodeId', HilosLogNodesTableRow::keyField());
        $this->assertSame([
            ['field' => 'nodeId', 'direction' => 'asc'],
        ], $table->defaultSort()?->toArray());
    }

    public function testTheDefaultOrderMatchesTheMirrorForCaseAndNumericPartsOfNodeNames(): void
    {
        $this->picture(self::node('node-2'), self::node('node-10'), self::node('node-a'), self::node('Node-a'));
        $table = new HilosLogNodesTable();

        $ascending = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 25));
        $this->assertSame(
            ['Node-a', 'node-10', 'node-2', 'node-a'],
            array_map(static fn($row): string => $row->getRowKey(), $ascending->rows),
        );

        $descending = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogNodesTableRow::nodeId, TableConstants::ORDER_DESC)),
            limit: 25,
        ));
        $this->assertSame(
            ['node-a', 'node-2', 'node-10', 'Node-a'],
            array_map(static fn($row): string => $row->getRowKey(), $descending->rows),
        );
    }

    public function testWindowsHonorTheLimitAnchorsAndNumberedPagesWithAnExactCount(): void
    {
        $slots = [];
        for ($number = 30; $number >= 1; $number--) {
            $slots[] = self::node(sprintf('node-%02d', $number));
        }
        $this->picture(...$slots);
        $table = new HilosLogNodesTable();
        $this->assertSame(25, $table->windowSize());

        $first = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));
        $this->assertCount(25, $first->rows);
        $this->assertSame(30, $first->totalCount);
        $this->assertTrue($first->totalExact);
        $this->assertSame(0, $first->rowsBefore);
        $this->assertSame('node-01', $first->rows[0]->getRowKey());
        $this->assertSame('node-25', $first->rows[24]->getRowKey());
        $this->assertSame(['nodeId' => 'node-25'], $first->lastAnchor?->toArray());

        $next = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 25, anchor: $first->lastAnchor));
        $this->assertSame(
            ['node-26', 'node-27', 'node-28', 'node-29', 'node-30'],
            array_map(static fn($row): string => $row->getRowKey(), $next->rows),
        );
        $this->assertSame(30, $next->totalCount);
        $this->assertSame(25, $next->rowsBefore);

        $previous = $table->getPage(new TableQueryDTO(
            sort: $table->defaultSort(),
            limit: 25,
            anchor: $next->firstAnchor,
            anchorDirection: TableAnchorDirection::Before,
        ));
        $this->assertEquals($first->rows, $previous->rows);

        $jump = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: 10, pageIndex: 2));
        $this->assertCount(10, $jump->rows);
        $this->assertSame('node-21', $jump->rows[0]->getRowKey());
        $this->assertSame(20, $jump->rowsBefore);
        $this->assertSame(30, $jump->totalCount);
    }

    public function testAnUndeclaredSortCannotReorderTheNodeSet(): void
    {
        $this->picture(self::node('node-z'), self::node('node-a'));

        $snapshot = new HilosLogNodesTable()->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosLogNodesTableRow::liveBytes, TableConstants::ORDER_DESC)),
            limit: 1,
        ));

        $this->assertSame('node-a', $snapshot->rows[0]->getRowKey());
        $this->assertSame(2, $snapshot->totalCount);
    }

    public function testUnreadableNodesKeepTheirIdentityAndNoMeasurements(): void
    {
        $this->picture(self::node(
            'node-unreadable',
            available: false,
            batches: [self::batch(self::NOW, takenAt: null)],
            keys: [new LogKeySummary('daemon.log', LogKeySummary::CLASS_DAEMON, true, [], 100)],
            growth: ['daemon.log' => 10],
            due: [self::NOW],
        ));

        $row = new HilosLogNodesTable()->getPage(new TableQueryDTO(limit: 25))->rows[0];

        $this->assertSame([
            'nodeId' => 'node-unreadable',
            'available' => false,
            'lastRotationAt' => null,
            'liveBytes' => null,
            'archiveBytes' => null,
            'growthBytesPerDay' => null,
            'batchesDueForTakeout' => null,
        ], $row->toArray());
    }

    public function testTheSharedProjectionCountsEveryClassAndOnlyExistingUntakenDueBatches(): void
    {
        $row = HilosLogNodesTableRow::fromNode(self::node(
            'node-a',
            batches: [self::batch(self::NOW), self::batch(self::NOW - 100, takenAt: 0)],
            keys: [
                new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], 100),
                new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], 200),
                new LogKeySummary('worker-monopolistic-0.log', LogKeySummary::CLASS_WORKER, true, [], 300),
                new LogKeySummary('daemon.log', LogKeySummary::CLASS_DAEMON, true, [], 400),
            ],
            growth: ['agent-a.log' => 20, 'worker-0.log' => null, 'worker-monopolistic-0.log' => 30, 'daemon.log' => 0],
            due: [self::NOW - 100, self::NOW, self::NOW + 100],
        ));

        $this->assertSame(200, $row->archiveBytes, 'Two batches, each holding 10 + 20 + 30 + 40 bytes');
        $this->assertSame(800, $row->liveBytes, 'Every key total less the whole archive');
        $this->assertSame(50, $row->growthBytesPerDay);
        $this->assertSame(1, $row->batchesDueForTakeout, 'The confirmed and nonexistent batches do not count');
        $this->assertSame(1_800_000_000_000, $row->lastRotationAt, 'Newest batch, in epoch milliseconds');
    }

    public function testGrowthStaysUnknownUntilAnyWindowFillsAndMeasuredZeroRemainsZero(): void
    {
        $unknown = HilosLogNodesTableRow::fromNode(self::node('node-a', growth: ['agent-a.log' => null]));
        $this->assertNull($unknown->growthBytesPerDay);
        $this->assertNull($unknown->lastRotationAt);
        $this->assertSame(0, $unknown->liveBytes);
        $this->assertSame(0, $unknown->archiveBytes);
        $this->assertSame(0, $unknown->batchesDueForTakeout);

        $measured = HilosLogNodesTableRow::fromNode(self::node('node-a', growth: ['agent-a.log' => null, 'daemon.log' => 0]));
        $this->assertSame(0, $measured->growthBytesPerDay);
    }

    public function testTheWireRowStaysInlineAndEveryFieldIsNotPersonal(): void
    {
        $table = new HilosLogNodesTable();
        $row = HilosLogNodesTableRow::fromNode(self::node('node-a', batches: [self::batch(0)]));
        $this->assertSame(0, $row->lastRotationAt, 'Epoch zero is a known instant');
        $this->assertEquals($row, HilosLogNodesTableRow::fromArray($row->toArray()));
        $wire = $table->browserRow($row);
        $this->assertSame('node-a', $wire[BrowserPageSignalData::rowKey]);
        $this->assertSame(['node' => $row->toArray()], $wire[BrowserPageSignalData::sources]);
        $this->assertArrayNotHasKey('id', $wire[BrowserPageSignalData::sources]['node']);
        $this->assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
        foreach ($table->wireFields() as $origin) {
            $this->assertEquals(WireField::notPersonal(), $origin);
        }
    }

    public function testSearchIsNotOffered(): void
    {
        $this->expectException(TableSearchNotSupportedException::class);

        new HilosLogNodesTable()->getPage(new TableQueryDTO(search: 'node', limit: 25));
    }

    /** @param ClusterLogNodeSlot ...$slots Whole cluster picture */
    private function picture(ClusterLogNodeSlot ...$slots): void
    {
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true));
    }

    /**
     * @param string $nodeId Node identity
     * @param bool $available Whether the log store was readable
     * @param list<LogBatchSummary> $batches Reported batches
     * @param list<LogKeySummary> $keys Reported stream totals
     * @param array<string, ?int> $growth Daily growth by stream
     * @param list<int> $due Recommended batch timestamps
     * @return ClusterLogNodeSlot Reported node slot
     */
    private static function node(
        string $nodeId,
        bool $available = true,
        array $batches = [],
        array $keys = [],
        array $growth = [],
        array $due = [],
    ): ClusterLogNodeSlot {
        return new ClusterLogNodeSlot(
            $nodeId,
            new NodeLogIndex($nodeId, $available, self::NOW, $batches, $keys, [], $growth, dueBatchTimestamps: $due),
            self::NOW,
        );
    }

    /**
     * @param int $timestamp Rotation instant in epoch seconds
     * @param ?int $takenAt Confirmation instant, or null while awaiting takeout
     * @return LogBatchSummary Batch containing all four stream classes
     */
    private static function batch(int $timestamp, ?int $takenAt = null): LogBatchSummary
    {
        return new LogBatchSummary($timestamp, 1, 10, 1, 20, 1, 30, 1, 40, $takenAt);
    }
}
