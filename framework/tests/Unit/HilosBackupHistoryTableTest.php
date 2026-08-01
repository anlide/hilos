<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Backup\HilosBackupTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework backup list table.
 *
 * The two runtime sources (the stored backup index and the in-progress singleton)
 * are bound by a test subclass through the table's seams; the assertions exercise
 * the merge, the source-change dispatch, and the row projection only.
 */
final class HilosBackupHistoryTableTest extends TestCase
{
    public function testUnrelatedSourceIsIgnored(): void
    {
        $this->assertNull(
            $this->table()->buildMutationForSourceEvent(SourceChange::rtUpdated('other', 'x', [])),
        );
    }

    public function testRuntimeStartCreatesRunningRow(): void
    {
        $table = $this->table(runtime: $this->runningRuntime());

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupRuntime::RT_ITEM, BackupRuntime::ID, []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Create, $mutation->type);
        $this->assertSame(HilosBackupTableRow::RUNNING_ROW_KEY, $mutation->rowKey);
        $this->assertInstanceOf(HilosBackupTableRow::class, $mutation->row);
        $this->assertFalse($mutation->row->finished);
        $this->assertSame('running', $mutation->row->status);
        $this->assertSame('full', $mutation->row->scope);
        $this->assertSame('2026-07-20T11:00:00+00:00', $mutation->row->createdAt);
        // The in-progress row has no failure — the reason exists only on a completed failure.
        $this->assertNull($mutation->row->failureReason);
    }

    public function testRuntimeIdleDeletesRunningRow(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupRuntime::RT_ITEM, BackupRuntime::ID, []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame(HilosBackupTableRow::RUNNING_ROW_KEY, $mutation->rowKey);
        $this->assertNull($mutation->row);
    }

    public function testStoredBackupChangeProjectsFinishedRow(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([
                BackupHistory::id => 'b1',
                BackupHistory::createdAt => '2026-07-20T10:00:00+00:00',
                BackupHistory::env => 'prod',
                BackupHistory::scope => 'full',
                BackupHistory::sizeBytes => 2048,
                BackupHistory::durationSeconds => 7,
                BackupHistory::keep => true,
                BackupHistory::status => 'success',
            ]),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b1', []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame('b1', $mutation->rowKey);
        $this->assertInstanceOf(HilosBackupTableRow::class, $mutation->row);
        $this->assertTrue($mutation->row->finished);
        $this->assertTrue($mutation->row->keep);
        $this->assertSame(2048, $mutation->row->sizeBytes);
    }

    public function testFailedBackupProjectsNullFinished(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([
                BackupHistory::id => 'b2',
                BackupHistory::status => 'error',
            ]),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b2', []),
        );

        $this->assertNotNull($mutation);
        $this->assertNull($mutation->row?->finished);
    }

    public function testFailedBackupCarriesItsStoredReason(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([
                BackupHistory::id => 'b3',
                BackupHistory::status => 'error',
                BackupHistory::failureReason => 'timed out after 30s',
            ]),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b3', []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame('timed out after 30s', $mutation->row?->failureReason);
    }

    public function testMissingStoredBackupDeletesRow(): void
    {
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtDeleted(BackupHistory::RT_COLLECTION, 'gone'),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame('gone', $mutation->rowKey);
    }

    public function testFullSnapshotMergesStoredIndexAndRunningRow(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
            ),
            runtime: $this->runningRuntime(),
        );

        $keys = [];
        foreach ($table->getFullSnapshot()->rows as $row) {
            $this->assertInstanceOf(HilosBackupTableRow::class, $row);
            $keys[] = $row->getRowKey();
        }

        $this->assertContains('b1', $keys);
        $this->assertContains(HilosBackupTableRow::RUNNING_ROW_KEY, $keys);
        $this->assertCount(2, $keys);
    }

    public function testBrowserRowRidesSingleBackupSlot(): void
    {
        $table = $this->table();
        $row = new HilosBackupTableRow(
            rowKey: 'b1',
            createdAt: '2026-07-20T10:00:00+00:00',
            env: 'prod',
            scope: 'full',
            sizeBytes: 2048,
            durationSeconds: 7,
            keep: false,
            status: 'success',
            finished: true,
            failureReason: null,
        );

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 'b1',
                BrowserPageSignalData::sources => [
                    'backup' => [
                        HilosBackupTableRow::rowKey => 'b1',
                        HilosBackupTableRow::createdAt => '2026-07-20T10:00:00+00:00',
                        HilosBackupTableRow::env => 'prod',
                        HilosBackupTableRow::scope => 'full',
                        HilosBackupTableRow::sizeBytes => 2048,
                        HilosBackupTableRow::durationSeconds => 7,
                        HilosBackupTableRow::keep => false,
                        HilosBackupTableRow::status => 'success',
                        HilosBackupTableRow::finished => true,
                        HilosBackupTableRow::failureReason => null,
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }

    public function testTheInProgressRowIsDeclaredLive(): void
    {
        $create = $this->table(runtime: $this->runningRuntime())->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupRuntime::RT_ITEM, BackupRuntime::ID, []),
        );
        $delete = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupRuntime::RT_ITEM, BackupRuntime::ID, []),
        );

        // A progress row must never wait behind Apply: it would outlive the run it reports.
        $this->assertNotNull($create);
        $this->assertTrue($create->live);
        $this->assertNotNull($delete);
        $this->assertTrue($delete->live);
    }

    public function testAStoredBackupRowIsNotLive(): void
    {
        $histories = $this->historiesWith(
            BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
        );
        $mutation = $this->table($histories)->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b1', []),
        );

        // Stored backups are content: they keep the pending gate like any other row.
        $this->assertNotNull($mutation);
        $this->assertFalse($mutation->live);
    }

    public function testTheRowSlotCarriesNoIdField(): void
    {
        $row = new HilosBackupTableRow(
            rowKey: 'b1',
            createdAt: '2026-07-20T10:00:00+00:00',
            env: 'prod',
            scope: 'full',
            sizeBytes: 2048,
            durationSeconds: 7,
            keep: false,
            status: 'success',
            finished: true,
            failureReason: null,
        );

        // The frontend normalizer ingests any slot payload bearing an `id` as an entity
        // fragment and replaces it with a reference, which strips every other field off the
        // row. The identity rides the fragment's rowKey instead.
        $this->assertArrayNotHasKey('id', $row->toArray());
        $this->assertSame('b1', $row->toArray()[HilosBackupTableRow::rowKey]);
    }

    /**
     * Builds a backup index collection seeded with the given rows.
     *
     * @param BackupHistory ...$rows Stored backup index rows
     * @return BackupHistories Seeded backup index collection
     */
    private function historiesWith(BackupHistory ...$rows): BackupHistories
    {
        $histories = BackupHistories::init();
        foreach ($rows as $row) {
            $histories->add($row);
        }

        return $histories;
    }

    /**
     * Builds a running backup runtime singleton fixture.
     *
     * @return BackupRuntime Running backup runtime state
     */
    private function runningRuntime(): BackupRuntime
    {
        return BackupRuntime::fromRow([
            BackupRuntime::running => true,
            BackupRuntime::currentBackupId => 'b9',
            BackupRuntime::scope => 'full',
            BackupRuntime::startedAt => '2026-07-20T11:00:00+00:00',
        ]);
    }

    /**
     * Builds a backup table bound to in-memory runtime sources.
     *
     * @param ?BackupHistories $histories Stored backup index, or null when unavailable
     * @param ?BackupRuntime $runtime In-progress runtime singleton, or null when idle
     * @return HilosBackupHistoryTable Table over the bound sources
     */
    private function table(?BackupHistories $histories = null, ?BackupRuntime $runtime = null): HilosBackupHistoryTable
    {
        return new class($histories, $runtime) extends HilosBackupHistoryTable {
            public function __construct(
                private readonly ?BackupHistories $historiesFixture,
                private readonly ?BackupRuntime $runtimeFixture,
            ) {
                parent::__construct();
            }

            protected function histories(): ?BackupHistories
            {
                return $this->historiesFixture;
            }

            protected function runtimeState(): ?BackupRuntime
            {
                return $this->runtimeFixture;
            }
        };
    }
}
