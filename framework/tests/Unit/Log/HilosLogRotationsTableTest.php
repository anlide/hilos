<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogRotationsTableRow;
use PHPUnit\Framework\TestCase;

/**
 * The rotation history the archive screen is built on (HIL-387).
 *
 * A row is one batch ON ONE NODE, and the two things that follow from that are what these cases
 * hold. The row key has to tell one rotation moment on two nodes apart, or the window would treat
 * them as one row and show whichever arrived last. And the retention verdict has to be judged over
 * one node's archive at a time: `keepBatches` means "the newest N of THIS directory", so a cluster
 * list would spend the whole protection on N batches in total and recommend carrying off a
 * neighbour's freshest batch.
 *
 * The same "one node at a time" runs through what HIL-483 adds. The confirmation that a batch was
 * carried off arrives WITH that batch and overrules the verdict judged here, and the absolute
 * address a row offers is built from the reporting node's own log root - this page worker knows
 * one log directory, and it is not necessarily the one the batch is lying in.
 *
 * The policy is read through the settings resolver, which falls back to the environment when no
 * settings layer is initialized - which is what these cases configure.
 */
final class HilosLogRotationsTableTest extends TestCase
{
    /** Fixed instant every fixture batch is placed relative to (Unix seconds, mid-January 2027). */
    private const int NOW = 1_800_000_000;

    /** One day in seconds, the spacing of the fixture batches. */
    private const int DAY = 86_400;

    protected function setUp(): void
    {
        parent::setUp();
        ClusterLogIndexMirror::forgetPicture();
        $this->retention(keepBatches: 0, maxAgeSeconds: 0);
    }

    protected function tearDown(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name);

        parent::tearDown();
    }

    /**
     * No picture has arrived, so there is nothing to list - and nothing invented to stand in for it.
     */
    public function testAMirrorThatHasHeardNothingListsNoBatches(): void
    {
        $snapshot = new HilosLogRotationsTable()->getPage(new TableQueryDTO());

        $this->assertSame([], $snapshot->rows);
        $this->assertSame(0, $snapshot->totalCount);
    }

    /**
     * The same rotation moment on two nodes is two archives on two machines, so it is two rows -
     * and the row keys have to differ, or a window would hold one of them and drop the other.
     */
    public function testOneRotationMomentOnTwoNodesIsTwoRowsWithDifferentKeys(): void
    {
        $this->picture(
            $this->node('node-1', [self::NOW - self::DAY]),
            $this->node('node-2', [self::NOW - self::DAY]),
        );

        $rows = $this->rows(new TableQueryDTO());

        $this->assertSame(['node-1', 'node-2'], array_map(static fn($row): ?string => $row->node, $rows));
        $this->assertSame(
            ['node-1:' . (self::NOW - self::DAY), 'node-2:' . (self::NOW - self::DAY)],
            array_map(static fn($row): string => $row->rowKey, $rows),
        );
    }

    /**
     * Newest first without being asked, because that is the question the screen is opened with.
     */
    public function testTheDefaultOrderIsNewestBatchFirst(): void
    {
        $this->picture($this->node('node-1', [self::NOW - 3 * self::DAY, self::NOW - self::DAY, self::NOW]));

        $rows = $this->rows(new TableQueryDTO());

        $this->assertSame(
            [self::NOW, self::NOW - self::DAY, self::NOW - 3 * self::DAY],
            array_map(static fn($row): int => $row->batchAt, $rows),
        );
    }

    /**
     * The verdict is judged per node, and this is the case that tells the two readings apart: over
     * one cluster list the newest two batches are both the neighbour's, and all three of node-1's
     * would be recommended for carrying off though node-1 has kept nothing at all.
     */
    public function testTheRetentionVerdictIsJudgedOverOneNodesArchiveAtATime(): void
    {
        $this->retention(keepBatches: 2, maxAgeSeconds: 0);
        $this->picture(
            $this->node('node-1', [self::NOW - 10 * self::DAY, self::NOW - 9 * self::DAY, self::NOW - 8 * self::DAY]),
            $this->node('node-2', [self::NOW - self::DAY, self::NOW]),
        );

        $due = array_values(array_filter(
            $this->rows(new TableQueryDTO()),
            static fn($row): bool => $row->retentionState === HilosLogRotationsTable::STATE_DUE,
        ));

        $this->assertCount(1, $due);
        $this->assertSame('node-1', $due[0]->node);
        $this->assertSame(self::NOW - 10 * self::DAY, $due[0]->batchAt);
    }

    /**
     * An unreadable setting leaves the policy inert (HIL-682), and inert has to read as "nothing is
     * recommended" rather than as "everything is": a zero threshold here removes a constraint.
     */
    public function testAnInertPolicyRecommendsNothing(): void
    {
        $this->retention(keepBatches: 0, maxAgeSeconds: 0);
        $this->picture($this->node('node-1', [self::NOW - 400 * self::DAY, self::NOW - 300 * self::DAY]));

        $states = array_map(static fn($row): string => $row->retentionState, $this->rows(new TableQueryDTO()));

        $this->assertSame(
            [HilosLogRotationsTable::STATE_KEPT, HilosLogRotationsTable::STATE_KEPT],
            $states,
        );
    }

    /**
     * The operator's word beats the rule's reading, and this is the case the two disagree on: the
     * batch is inside what the policy protects, and it was carried off all the same. Judging it
     * kept would offer to protect a directory that is not there any more.
     */
    public function testAConfirmedBatchStaysTakenEvenWhereTheRuleWouldProtectIt(): void
    {
        $this->retention(keepBatches: 5, maxAgeSeconds: 0);
        $this->picture($this->node('node-1', [self::NOW - self::DAY, self::NOW], confirmed: [self::NOW - self::DAY]));

        $states = array_map(static fn($row): string => $row->retentionState, $this->rows(new TableQueryDTO()));

        $this->assertSame(
            [HilosLogRotationsTable::STATE_KEPT, HilosLogRotationsTable::STATE_TAKEN],
            $states,
        );
    }

    /**
     * The list of what still has to be carried off is the one the screen's filter serves, so a
     * batch that has been carried off has to leave it - otherwise the operator is asked twice.
     */
    public function testAConfirmedBatchLeavesTheListOfWhatIsStillRecommended(): void
    {
        $this->retention(keepBatches: 1, maxAgeSeconds: 0);
        $this->picture($this->node(
            'node-1',
            [self::NOW - 2 * self::DAY, self::NOW - self::DAY, self::NOW],
            confirmed: [self::NOW - 2 * self::DAY],
        ));

        $rows = $this->rows(new TableQueryDTO(
            filter: [HilosLogRotationsTable::FILTER_STATE => HilosLogRotationsTable::STATE_DUE],
        ));

        $this->assertSame([self::NOW - self::DAY], array_map(static fn($row): int => $row->batchAt, $rows));
    }

    /**
     * The address an operator copies from belongs to the machine holding the batch, so each row
     * carries its OWN node's root: this page worker knows one log directory, and it is not
     * necessarily any of theirs.
     */
    public function testTheAbsolutePathIsBuiltFromTheReportingNodesOwnLogRoot(): void
    {
        $this->picture(
            $this->node('node-1', [self::NOW], logDirectory: '/var/log/hilos'),
            $this->node('node-2', [self::NOW], logDirectory: '/srv/hilos/log/'),
        );

        $directory = date(LogRotationConstants::TIMESTAMP_FORMAT, self::NOW);

        $this->assertSame(
            [
                '/var/log/hilos/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/' . $directory . '/',
                '/srv/hilos/log/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/' . $directory . '/',
            ],
            array_map(static fn($row): ?string => $row->absolutePath, $this->rows(new TableQueryDTO())),
        );
    }

    /**
     * A node that named no root has no address to give, and the row says so with nothing rather
     * than with an empty string that reads on screen as a path to the filesystem root.
     */
    public function testABatchFromANodeThatNamedNoLogRootCarriesNoAbsolutePath(): void
    {
        $this->picture($this->node('node-1', [self::NOW]));

        $row = $this->rows(new TableQueryDTO())[0];

        $this->assertNull($row->absolutePath);
        $this->assertSame(
            LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
                . '/' . date(LogRotationConstants::TIMESTAMP_FORMAT, self::NOW) . '/',
            $row->path,
            'The relative name is the node\'s own and survives a missing root',
        );
    }

    public function testTheNodeFilterNarrowsToOneArchive(): void
    {
        $this->picture(
            $this->node('node-1', [self::NOW - self::DAY]),
            $this->node('node-2', [self::NOW - self::DAY, self::NOW]),
        );

        $rows = $this->rows(new TableQueryDTO(filter: [HilosLogRotationsTable::FILTER_NODE => 'node-2']));

        $this->assertCount(2, $rows);
        $this->assertSame(['node-2', 'node-2'], array_map(static fn($row): ?string => $row->node, $rows));
    }

    public function testTheStateFilterNarrowsToWhatIsRecommendedForCarryingOff(): void
    {
        $this->retention(keepBatches: 1, maxAgeSeconds: 0);
        $this->picture($this->node('node-1', [self::NOW - 2 * self::DAY, self::NOW - self::DAY, self::NOW]));

        $rows = $this->rows(new TableQueryDTO(
            filter: [HilosLogRotationsTable::FILTER_STATE => HilosLogRotationsTable::STATE_DUE],
        ));

        $this->assertSame(
            [self::NOW - self::DAY, self::NOW - 2 * self::DAY],
            array_map(static fn($row): int => $row->batchAt, $rows),
        );
    }

    /**
     * The search answers the two names on the screen - the batch and the node - and the batch's
     * name is the archive directory, which is its date.
     */
    public function testTheSearchFindsABatchByItsDateAndByItsNode(): void
    {
        $this->picture(
            $this->node('node-1', [self::NOW - 40 * self::DAY]),
            $this->node('node-2', [self::NOW]),
        );

        $byNode = $this->rows(new TableQueryDTO(search: 'node-2'));
        $this->assertSame(['node-2'], array_map(static fn($row): ?string => $row->node, $byNode));

        $byDate = $this->rows(new TableQueryDTO(search: date(LogRotationConstants::TIMESTAMP_FORMAT, self::NOW)));
        $this->assertSame([self::NOW], array_map(static fn($row): int => $row->batchAt, $byDate));
    }

    /**
     * A weight is not a name, and a term short enough to appear in one must not drag every batch
     * into the answer.
     */
    public function testTheSearchDoesNotAnswerToAByteWeight(): void
    {
        $this->picture($this->node('node-1', [self::NOW], bytesPerBatch: 7_777));

        $this->assertSame([], $this->rows(new TableQueryDTO(search: '7777')));
    }

    public function testTheHistorySortsByWeightAndByNode(): void
    {
        $this->picture(
            $this->node('node-2', [self::NOW], bytesPerBatch: 100),
            $this->node('node-1', [self::NOW - self::DAY], bytesPerBatch: 900),
        );

        $byWeight = $this->rows(new TableQueryDTO(
            sort: new TableSortDTO(HilosLogRotationsTableRow::bytes, TableConstants::ORDER_ASC),
        ));
        $this->assertSame([400, 3_600], array_map(static fn($row): int => $row->bytes, $byWeight));

        $byNode = $this->rows(new TableQueryDTO(
            sort: new TableSortDTO(HilosLogRotationsTableRow::node, TableConstants::ORDER_ASC),
        ));
        $this->assertSame(['node-1', 'node-2'], array_map(static fn($row): ?string => $row->node, $byNode));
    }

    /**
     * The weight is what the directory costs, so every class of stream counts toward it - including
     * the daemon's own, which the three file counts on the screen deliberately leave out.
     */
    public function testTheWeightCountsEveryStreamClassWhileTheCountsShowThree(): void
    {
        $this->picture($this->node('node-1', [self::NOW], bytesPerBatch: 10));

        $row = $this->rows(new TableQueryDTO())[0];

        $this->assertSame(40, $row->bytes);
        $this->assertSame(1, $row->agentFileCount);
        $this->assertSame(2, $row->workerFileCount);
        $this->assertSame(3, $row->workerMonopolisticFileCount);
        $this->assertSame(
            LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
                . '/' . date(LogRotationConstants::TIMESTAMP_FORMAT, self::NOW) . '/',
            $row->path,
        );
    }

    /**
     * Runs one window and hands back its typed rows.
     *
     * @param TableQueryDTO $query Window query
     * @return list<HilosLogRotationsTableRow> Rows of that window
     */
    private function rows(TableQueryDTO $query): array
    {
        /** @var list<HilosLogRotationsTableRow> $rows */
        $rows = new HilosLogRotationsTable()->getPage($query)->rows;

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
     * Builds one node's slot with a batch per given timestamp.
     *
     * @param string $nodeId Node the slot belongs to
     * @param list<int> $batchTimestamps Batch timestamps, ascending as a node reports them
     * @param int $bytesPerBatch Weight of each stream class within a batch
     * @param list<int> $confirmed Batch timestamps an operator has confirmed carrying off
     * @param ?string $logDirectory Log root this node reports, null when its build named none
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private function node(
        string $nodeId,
        array $batchTimestamps,
        int $bytesPerBatch = 1_000,
        array $confirmed = [],
        ?string $logDirectory = null,
    ): ClusterLogNodeSlot {
        $batches = array_map(
            static fn(int $timestamp): LogBatchSummary => new LogBatchSummary(
                timestamp: $timestamp,
                agentFileCount: 1,
                agentBytes: $bytesPerBatch,
                workerFileCount: 2,
                workerBytes: $bytesPerBatch,
                workerMonopolisticFileCount: 3,
                workerMonopolisticBytes: $bytesPerBatch,
                daemonFileCount: 4,
                daemonBytes: $bytesPerBatch,
                // The stamp says the batch was carried off, not when the run happened, so it is
                // deliberately unlike the batch's own timestamp.
                takenAt: in_array($timestamp, $confirmed, true) ? self::NOW : null,
            ),
            $batchTimestamps,
        );

        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: true,
                sampledAt: self::NOW,
                batches: $batches,
                keys: [],
                workers: [],
                growthBytesPerDay: [],
                logDirectory: $logDirectory,
            ),
            receivedAt: self::NOW,
        );
    }

    /**
     * Puts the retention thresholds where the settings resolver falls back to reading them.
     *
     * @param int $keepBatches Newest batches always kept
     * @param int $maxAgeSeconds Age beyond which a batch is eligible
     */
    private function retention(int $keepBatches, int $maxAgeSeconds): void
    {
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . $keepBatches);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . $maxAgeSeconds);
    }
}
