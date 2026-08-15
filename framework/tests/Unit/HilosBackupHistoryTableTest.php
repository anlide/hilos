<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Backup\BackupChecksumState;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Backup\HilosBackupTableRow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
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
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
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
            checksumState: BackupChecksumState::VERIFIED,
            verifiedAt: '2026-08-02T06:00:00+00:00',
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
                        HilosBackupTableRow::checksumState => 'verified',
                        HilosBackupTableRow::verifiedAt => '2026-08-02T06:00:00+00:00',
                        // An archive nobody restored says so in every restore field.
                        HilosBackupTableRow::restorePhase => null,
                        HilosBackupTableRow::restoreOutcome => null,
                        HilosBackupTableRow::restoreFinishedAt => null,
                        HilosBackupTableRow::restoreFailureReason => null,
                        HilosBackupTableRow::restoreDatabaseTouched => false,
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }

    public function testTheInProgressRowIsDeclaredLive(): void
    {
        $create = $this->table(runtime: $this->runningRuntime())->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
        );
        $delete = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
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
            checksumState: BackupChecksumState::VERIFIED,
            verifiedAt: '2026-08-02T06:00:00+00:00',
        );

        // The frontend normalizer ingests any slot payload bearing an `id` as an entity
        // fragment and replaces it with a reference, which strips every other field off the
        // row. The identity rides the fragment's rowKey instead.
        $this->assertArrayNotHasKey('id', $row->toArray());
        $this->assertSame('b1', $row->toArray()[HilosBackupTableRow::rowKey]);
    }

    public function testTheChecksumColumnShowsAllFourStates(): void
    {
        $states = [
            // No digest at all: the sidecar predates checksums, so there is nothing to check.
            'legacy' => [[], BackupChecksumState::NONE],
            // A digest exists but nobody has run a check against it yet.
            'fresh' => [[BackupHistory::sha256 => 'ab'], BackupChecksumState::PRESENT],
            'checked' => [
                [BackupHistory::sha256 => 'ab', BackupHistory::verifyOutcome => 'ok'],
                BackupChecksumState::VERIFIED,
            ],
            'broken' => [
                [BackupHistory::sha256 => 'ab', BackupHistory::verifyOutcome => 'mismatch'],
                BackupChecksumState::MISMATCH,
            ],
        ];

        foreach ($states as $id => [$extra, $expected]) {
            $table = $this->table(histories: $this->historiesWith(
                BackupHistory::fromRow([
                    BackupHistory::id => $id,
                    BackupHistory::status => 'success',
                    ...$extra,
                ]),
            ));

            $mutation = $table->buildMutationForSourceEvent(
                SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, $id, []),
            );

            $this->assertSame($expected, $mutation->row?->checksumState, "row {$id}");
        }
    }

    public function testAVerifiedRowCarriesWhenItWasChecked(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([
                BackupHistory::id => 'b1',
                BackupHistory::status => 'success',
                BackupHistory::sha256 => 'ab',
                BackupHistory::verifyOutcome => 'ok',
                BackupHistory::verifiedAt => '2026-08-02T06:00:00+00:00',
            ]),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b1', []),
        );

        $this->assertSame('2026-08-02T06:00:00+00:00', $mutation->row?->verifiedAt);
        // The digest itself is not part of the row payload: the browser never needs it.
        $this->assertArrayNotHasKey(BackupHistory::sha256, $mutation->row?->toArray() ?? []);
    }

    public function testAVerifiedOutcomeWithoutADigestStillReadsAsNone(): void
    {
        // Defensive: an index row cannot honestly claim a check it has no digest for.
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([
                BackupHistory::id => 'odd',
                BackupHistory::status => 'success',
                BackupHistory::verifyOutcome => 'ok',
            ]),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'odd', []),
        );

        $this->assertSame(BackupChecksumState::NONE, $mutation->row?->checksumState);
    }

    public function testTheInProgressRowHasNothingToChecksum(): void
    {
        $mutation = $this->table(runtime: $this->runningRuntime())->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
        );

        // The archive does not exist yet, so the running row claims neither digest nor check.
        $this->assertSame(BackupChecksumState::NONE, $mutation->row?->checksumState);
        $this->assertNull($mutation->row?->verifiedAt);
    }

    public function testAnUnmountedIndexLeavesTheInProgressRowAlone(): void
    {
        // A project that never declared the BACKUP feature has no index to walk. The snapshot then
        // holds what the table does know - the run in flight - and reaching for the absent index
        // is not a step on the way there, so no warning is raised getting to it.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException("PHP raised: {$message}");
        });

        try {
            $snapshot = $this->table(runtime: $this->runningRuntime())->getFullSnapshot();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $snapshot->rows);
        $row = $snapshot->rows[0];
        $this->assertInstanceOf(HilosBackupTableRow::class, $row);
        $this->assertSame(HilosBackupTableRow::RUNNING_ROW_KEY, $row->requireRowKey());
    }

    public function testARestoreUpdatesTheRowOfTheArchiveItReplays(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
                BackupHistory::fromRow([BackupHistory::id => 'b2', BackupHistory::status => 'success']),
            ),
            restore: $this->restoreRuntime('b2'),
        );

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::ID, []),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame('b2', $mutation->rowKey, 'Only the archive being replayed moves');
        $this->assertTrue($mutation->live, 'A phase held behind Apply would outlive the run it describes');
        $this->assertSame('importing', $mutation->row?->restorePhase);
    }

    public function testARestoreOfAnArchiveTheIndexDoesNotCarryMovesNothing(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
            ),
            restore: $this->restoreRuntime('gone'),
        );

        $this->assertNull($table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::ID, []),
        ));
    }

    public function testAnIdleRestoreRowMovesNothing(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
        ));

        $this->assertNull($table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::ID, []),
        ));
    }

    public function testTheRestoredArchiveCarriesTheOutcomeAndTheRestIsBlank(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
                BackupHistory::fromRow([BackupHistory::id => 'b2', BackupHistory::status => 'success']),
            ),
            restore: $this->restoreRuntime('b2', [
                StateRestoreRuntime::running => false,
                StateRestoreRuntime::phase => 'failed',
                StateRestoreRuntime::outcome => 'error',
                StateRestoreRuntime::finishedAt => '2026-08-15T10:34:00+00:00',
                StateRestoreRuntime::failureReason => 'import failed',
                StateRestoreRuntime::databaseTouched => true,
            ]),
        );

        $rows = [];
        foreach ($table->getFullSnapshot()->rows as $row) {
            $this->assertInstanceOf(HilosBackupTableRow::class, $row);
            $rows[$row->requireRowKey()] = $row;
        }

        $this->assertSame('error', $rows['b2']->restoreOutcome);
        $this->assertSame('2026-08-15T10:34:00+00:00', $rows['b2']->restoreFinishedAt);
        $this->assertSame('import failed', $rows['b2']->restoreFailureReason);
        $this->assertTrue($rows['b2']->restoreDatabaseTouched);

        // The runtime row is a singleton about the LAST run; a neighbour must not read as
        // restored just because it shares the list with the archive that was.
        $this->assertNull($rows['b1']->restoreOutcome);
        $this->assertNull($rows['b1']->restorePhase);
        $this->assertFalse($rows['b1']->restoreDatabaseTouched);
    }

    public function testASucceededRestoreDoesNotReportADamagedDatabase(): void
    {
        // The runtime flag is true after every restore that got past its first destructive
        // step, and a successful one always does. On the row the field answers whether damage
        // was left behind, so a success has to read as false - otherwise the outcome modal
        // warns about a half-replaced database on every restore that worked.
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow([BackupHistory::id => 'b1', BackupHistory::status => 'success']),
            ),
            restore: $this->restoreRuntime('b1', [
                StateRestoreRuntime::running => false,
                StateRestoreRuntime::phase => 'succeeded',
                StateRestoreRuntime::outcome => 'success',
                StateRestoreRuntime::finishedAt => '2026-08-15T10:34:00+00:00',
                StateRestoreRuntime::databaseTouched => true,
            ]),
        );

        $rows = [];
        foreach ($table->getFullSnapshot()->rows as $row) {
            $this->assertInstanceOf(HilosBackupTableRow::class, $row);
            $rows[$row->requireRowKey()] = $row;
        }

        $this->assertSame('success', $rows['b1']->restoreOutcome);
        $this->assertFalse($rows['b1']->restoreDatabaseTouched);
    }

    /**
     * Builds a restore runtime singleton fixture naming one archive.
     *
     * @param string $backupId Archive the restore names
     * @param array<string, mixed> $overrides Fields replacing the mid-run default
     * @return RestoreRuntime Restore runtime singleton, seen the way the table sees it
     */
    private function restoreRuntime(string $backupId, array $overrides = []): RestoreRuntime
    {
        $state = StateRestoreRuntime::fromRow($overrides + [
            StateRestoreRuntime::running => true,
            StateRestoreRuntime::backupId => $backupId,
            StateRestoreRuntime::scope => 'full',
            StateRestoreRuntime::phase => 'importing',
            StateRestoreRuntime::startedAt => '2026-08-15T10:30:00+00:00',
        ]);

        return new RestoreRuntime($state);
    }

    /**
     * Builds a backup index the way the table reads one: a view over a seeded state collection.
     *
     * The fixture builds the backing rows because that is what the index is made of; the table
     * itself only ever sees the view, so that is what the seam hands it.
     *
     * @param BackupHistory ...$rows Stored backup index rows
     * @return BackupHistories Seeded backup index view
     */
    private function historiesWith(BackupHistory ...$rows): BackupHistories
    {
        $state = StateBackupHistories::init();
        foreach ($rows as $row) {
            $state->add($row);
        }

        $histories = BackupHistories::init();
        $histories->setStateCollection($state);
        $histories->setCollectionName(BackupHistory::RT_COLLECTION);

        return $histories;
    }

    /**
     * Builds a running backup runtime singleton fixture.
     *
     * @return BackupRuntime Running backup runtime singleton, seen the way the table sees it
     */
    private function runningRuntime(): BackupRuntime
    {
        $state = StateBackupRuntime::fromRow([
            StateBackupRuntime::running => true,
            StateBackupRuntime::currentBackupId => 'b9',
            StateBackupRuntime::scope => 'full',
            StateBackupRuntime::startedAt => '2026-07-20T11:00:00+00:00',
        ]);

        return new BackupRuntime($state);
    }

    /**
     * Builds a backup table bound to in-memory runtime sources.
     *
     * @param ?BackupHistories $histories Stored backup index view, or null when unmounted
     * @param ?BackupRuntime $runtime In-progress runtime singleton, or null when idle
     * @return HilosBackupHistoryTable Table over the bound sources
     */
    private function table(
        ?BackupHistories $histories = null,
        ?BackupRuntime $runtime = null,
        ?RestoreRuntime $restore = null,
    ): HilosBackupHistoryTable {
        return new class($histories, $runtime, $restore) extends HilosBackupHistoryTable {
            public function __construct(
                private readonly ?BackupHistories $historiesFixture,
                private readonly ?BackupRuntime $runtimeFixture,
                private readonly ?RestoreRuntime $restoreFixture,
            ) {
                parent::__construct();
            }

            protected function histories(): ?BackupHistories
            {
                return $this->historiesFixture;
            }

            protected function runtimeView(): ?BackupRuntime
            {
                return $this->runtimeFixture;
            }

            protected function restoreRuntimeView(): ?RestoreRuntime
            {
                return $this->restoreFixture;
            }
        };
    }
}
