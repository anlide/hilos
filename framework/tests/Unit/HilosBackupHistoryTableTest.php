<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Backup\BackupChecksumState;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupShipState;
use Hilos\Database\Migration;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
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
    /** Migration track the fixture files are written under. */
    private const string MIGRATION_TRACK = 'main';

    private string $migrationRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        // The row's migration verdict is compared against the level this code lists, and the gate
        // reads that off disk. Pointed at an empty tree by default: no migrations listed, which is
        // what every case in this suite that says nothing about levels runs with.
        $this->migrationRoot = sys_get_temp_dir() . '/hilos-backup-table-migrations-' . uniqid('', true);
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName(self::MIGRATION_TRACK);
    }

    protected function tearDown(): void
    {
        // The path stays set on the static Migration for the rest of the process; removing the
        // tree is what makes it harmless - an unreadable path lists no migrations.
        $this->removeTree($this->migrationRoot);
        parent::tearDown();
    }

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
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::scope => 'full',
                BackupHistory::sizeBytes => 2048,
                BackupHistory::durationSeconds => 7,
                BackupHistory::keep => true,
            ])),
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
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::id => 'b2',
                BackupHistory::status => 'error',
            ])),
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
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::id => 'b3',
                BackupHistory::status => 'error',
                BackupHistory::failureReason => 'timed out after 30s',
            ])),
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
                BackupHistory::fromRow($this->historyRow()),
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

    public function testTheInProgressRowCarriesTheProgressAnchorsAndAStoredArchiveCarriesNone(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow($this->historyRow()),
            ),
            runtime: $this->runningRuntime([
                StateBackupRuntime::phase => 'archiving',
                StateBackupRuntime::phaseStartedAt => '2026-07-20T11:02:00+00:00',
                StateBackupRuntime::estimatedSeconds => 300,
            ]),
        );

        $rows = [];
        foreach ($table->getFullSnapshot()->rows as $row) {
            $this->assertInstanceOf(HilosBackupTableRow::class, $row);
            $rows[$row->getRowKey()] = $row;
        }

        $running = $rows[HilosBackupTableRow::RUNNING_ROW_KEY];
        $this->assertSame('archiving', $running->progressPhase);
        $this->assertSame('2026-07-20T11:02:00+00:00', $running->progressPhaseStartedAt);
        $this->assertSame(300, $running->progressEstimatedSeconds);

        $stored = $rows['b1'];
        $this->assertNull($stored->progressPhase, 'A stored archive is not a run and has no bar to fill');
        $this->assertNull($stored->progressPhaseStartedAt);
        $this->assertNull($stored->progressEstimatedSeconds);
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
            shipState: BackupShipState::SHIPPED,
            shippedAt: '2026-08-16T06:00:00+00:00',
            shipError: null,
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
                        HilosBackupTableRow::shipState => 'shipped',
                        HilosBackupTableRow::shippedAt => '2026-08-16T06:00:00+00:00',
                        HilosBackupTableRow::shipError => null,
                        // An archive nobody restored says so in every restore field.
                        HilosBackupTableRow::restorePhase => null,
                        HilosBackupTableRow::restoreOutcome => null,
                        HilosBackupTableRow::restoreFinishedAt => null,
                        HilosBackupTableRow::restoreFailureReason => null,
                        HilosBackupTableRow::restoreDatabaseTouched => false,
                        HilosBackupTableRow::restoreMigrationDecision => null,
                        HilosBackupTableRow::restoreMigrationBehind => null,
                        HilosBackupTableRow::restoreMigrationNotice => null,
                        HilosBackupTableRow::progressPhase => null,
                        HilosBackupTableRow::progressPhaseStartedAt => null,
                        HilosBackupTableRow::progressEstimatedSeconds => null,
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
            BackupHistory::fromRow($this->historyRow()),
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
            shipState: BackupShipState::SHIPPED,
            shippedAt: '2026-08-16T06:00:00+00:00',
            shipError: null,
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
                BackupHistory::fromRow($this->historyRow([
                    BackupHistory::id => $id,
                    ...$extra,
                ])),
            ));

            $mutation = $table->buildMutationForSourceEvent(
                SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, $id, []),
            );

            $this->assertSame($expected, $mutation->row?->checksumState, "row {$id}");
        }
    }

    public function testWithNoDestinationEveryRowSaysItShipsNowhere(): void
    {
        // The column must never read "pending" on an installation that copies nothing: a copy
        // that is never coming would look like one that is merely late, forever.
        foreach (['never' => [], 'stamped' => [
            BackupHistory::shippedAt => '2026-08-16T06:00:00+00:00',
            BackupHistory::shipOutcome => 'ok',
        ]] as $id => $extra) {
            $table = $this->table(histories: $this->historiesWith(
                BackupHistory::fromRow($this->historyRow([
                    BackupHistory::id => $id,
                    ...$extra,
                ])),
            ));

            $mutation = $table->buildMutationForSourceEvent(
                SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, $id, []),
            );

            $this->assertSame(BackupShipState::NONE, $mutation->row?->shipState, "row {$id}");
        }
    }

    public function testADestinationNothingCanShipToReadsTheSameAsNoDestination(): void
    {
        // An ssh destination with no pinned receiver parses perfectly and ships nothing: the
        // factory refuses to build a driver for it, so the agent never attempts a copy. Asking
        // only whether the value parsed would leave every row saying "pending" forever - the very
        // thing the column exists to prevent - so the table asks both questions the agent asks.
        $previous = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_SHIP_TARGET=ssh://backup@receiver.example/srv/backups');

        try {
            $this->assertSame(BackupShipState::NONE, $this->shipStateOfAnUnshippedRow());

            putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS=/etc/hilos/known_hosts');
            $this->assertSame(BackupShipState::PENDING, $this->shipStateOfAnUnshippedRow());
        } finally {
            putenv('BACKUP_SHIP_TARGET');
            putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS');
            Hilos::$env = $previous;
        }
    }

    public function testARunThatLeftNoArchiveIsNotWaitingForACopy(): void
    {
        // A failed run published nothing to send, and the planner only ever picks up a successful
        // row - so "pending" here would promise a transfer that is queued nowhere and never comes.
        // It is the same reading the Checksum column already gives that row.
        $previous = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_SHIP_TARGET=ssh://backup@receiver.example/srv/backups');
        putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS=/etc/hilos/known_hosts');

        try {
            $this->assertSame(BackupShipState::PENDING, $this->shipStateOfAnUnshippedRow());
            $this->assertSame(BackupShipState::NONE, $this->shipStateOfAnUnshippedRow('error'));
        } finally {
            putenv('BACKUP_SHIP_TARGET');
            putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS');
            Hilos::$env = $previous;
        }
    }

    /**
     * @param string $status Terminal status of the run that made the backup
     * @return ?BackupShipState Copy state a never-shipped row of that status is built with
     */
    private function shipStateOfAnUnshippedRow(string $status = 'success'): ?BackupShipState
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::id => 'owed',
                BackupHistory::status => $status,
            ])),
        ));

        return $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'owed', []),
        )->row?->shipState;
    }

    public function testAShippedRowCarriesItsInstantAndAFailedOneItsError(): void
    {
        // The two travel whatever the configured destination is: the state answers "should the
        // operator worry", these answer "when" and "why", and the row is where both are read.
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::shippedAt => '2026-08-16T06:00:00+00:00',
                BackupHistory::shipOutcome => 'failed',
                BackupHistory::shipError => 'ssh: connect timed out',
            ])),
        ));

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, 'b1', []),
        );

        $this->assertSame('2026-08-16T06:00:00+00:00', $mutation->row?->shippedAt);
        $this->assertSame('ssh: connect timed out', $mutation->row?->shipError);
    }

    public function testAVerifiedRowCarriesWhenItWasChecked(): void
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::sha256 => 'ab',
                BackupHistory::verifyOutcome => 'ok',
                BackupHistory::verifiedAt => '2026-08-02T06:00:00+00:00',
            ])),
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
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::id => 'odd',
                BackupHistory::verifyOutcome => 'ok',
            ])),
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
                BackupHistory::fromRow($this->historyRow()),
                BackupHistory::fromRow($this->historyRow([
                    BackupHistory::id => 'b2',
                ])),
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
                BackupHistory::fromRow($this->historyRow()),
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
            BackupHistory::fromRow($this->historyRow()),
        ));

        $this->assertNull($table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::ID, []),
        ));
    }

    public function testTheRestoredArchiveCarriesTheOutcomeAndTheRestIsBlank(): void
    {
        $table = $this->table(
            histories: $this->historiesWith(
                BackupHistory::fromRow($this->historyRow()),
                BackupHistory::fromRow($this->historyRow([
                    BackupHistory::id => 'b2',
                ])),
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
                BackupHistory::fromRow($this->historyRow()),
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

    public function testAnArchiveAheadOfThisCodeIsMarkedIncompatibleAndSaysWhy(): void
    {
        $this->listCodeMigrations(1, 40);

        $row = $this->rowOfArchiveAtLevel('b1', 44);

        $this->assertSame('refuse', $row->restoreMigrationDecision);
        $this->assertNull($row->restoreMigrationBehind, 'An archive ahead of the code is not behind it');
        $this->assertSame(
            'connection 0: archive at migration 44, code expects 40 (4 ahead); there is no downgrade path',
            $row->restoreMigrationNotice,
        );
    }

    public function testAnArchiveBehindThisCodeCarriesTheMigrationsItWillApply(): void
    {
        $this->listCodeMigrations(1, 40);

        $row = $this->rowOfArchiveAtLevel('b1', 32);

        $this->assertSame('allow', $row->restoreMigrationDecision);
        $this->assertSame(8, $row->restoreMigrationBehind);
        $this->assertSame(
            'connection 0: archive at migration 32, code expects 40; 8 migration(s) will be applied after the import',
            $row->restoreMigrationNotice,
        );
    }

    public function testAnArchiveAtThisCodesLevelHasNothingToSay(): void
    {
        $this->listCodeMigrations(1, 40);

        $row = $this->rowOfArchiveAtLevel('b1', 40);

        $this->assertSame('allow', $row->restoreMigrationDecision);
        $this->assertNull($row->restoreMigrationBehind);
        $this->assertNull($row->restoreMigrationNotice, 'A matching archive shows no badge and opens no notice');
    }

    public function testEachConnectionGetsItsOwnLineInTheNotice(): void
    {
        $this->listCodeMigrations(1, 40);
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::connections => [
                    [
                        BackupConnectionMeta::index => 0,
                        BackupConnectionMeta::database => 'primary',
                        BackupConnectionMeta::migrationIndex => 32,
                    ],
                    [
                        BackupConnectionMeta::index => 1,
                        BackupConnectionMeta::database => 'secondary',
                        BackupConnectionMeta::migrationIndex => null,
                    ],
                ],
            ])),
        ));

        $row = $this->storedRowOf($table, 'b1');

        // Newline and not '; ': the semicolon already lives inside the refusal sentence, so
        // splitting on it would cut a line in half.
        $this->assertSame(
            [
                'connection 0: archive at migration 32, code expects 40;'
                . ' 8 migration(s) will be applied after the import',
                'connection 1: archive records no migration level (sidecar predates the field);'
                . ' restoring without the compatibility check',
            ],
            explode("\n", (string)$row->restoreMigrationNotice),
        );
    }

    public function testTheInProgressRowHasNoArchiveToJudge(): void
    {
        $table = $this->table(runtime: $this->runningRuntime());

        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(StateBackupRuntime::RT_ITEM, StateBackupRuntime::ID, []),
        );

        $this->assertNull($mutation->row?->restoreMigrationDecision);
        $this->assertNull($mutation->row?->restoreMigrationBehind);
        $this->assertNull($mutation->row?->restoreMigrationNotice);
    }

    /**
     * Builds the stored row of an archive whose single connection recorded one level.
     *
     * @param string $id Archive id
     * @param ?int $migrationIndex Level the archive recorded; null when its sidecar predates the field
     * @return HilosBackupTableRow Stored backup row
     */
    private function rowOfArchiveAtLevel(string $id, ?int $migrationIndex): HilosBackupTableRow
    {
        $table = $this->table(histories: $this->historiesWith(
            BackupHistory::fromRow($this->historyRow([
                BackupHistory::id => $id,
                BackupHistory::connections => [
                    [
                        BackupConnectionMeta::index => 0,
                        BackupConnectionMeta::database => 'primary',
                        BackupConnectionMeta::migrationIndex => $migrationIndex,
                    ],
                ],
            ])),
        ));

        return $this->storedRowOf($table, $id);
    }

    /**
     * Pulls one stored archive's row out of the table the way a source change builds it.
     *
     * @param HilosBackupHistoryTable $table Table bound to the fixture index
     * @param string $id Archive id
     * @return HilosBackupTableRow Stored backup row
     */
    private function storedRowOf(HilosBackupHistoryTable $table, string $id): HilosBackupTableRow
    {
        $mutation = $table->buildMutationForSourceEvent(
            SourceChange::rtUpdated(BackupHistory::RT_COLLECTION, $id, []),
        );
        $row = $mutation?->row;
        $this->assertInstanceOf(HilosBackupTableRow::class, $row);

        return $row;
    }

    /**
     * Gives this code a migration level by writing the up-files the gate counts.
     *
     * @param int ...$indices Migration indices the code lists
     */
    private function listCodeMigrations(int ...$indices): void
    {
        $trackDir = $this->migrationRoot . '/' . self::MIGRATION_TRACK;
        if (!is_dir($trackDir)) {
            mkdir($trackDir, 0700, true);
        }
        foreach ($indices as $index) {
            file_put_contents($trackDir . '/' . $index . '_up.sql', "-- fixture\n");
        }
    }

    /**
     * Recursively removes a fixture tree (best effort).
     *
     * @param string $path Directory path
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
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
            StateRestoreRuntime::rehydrateComplete => false,
            StateRestoreRuntime::rehydrateProblems => [],
            StateRestoreRuntime::databaseTouched => false,
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
     * @param array<string, mixed> $overrides Fields replacing the mid-run default
     * @return BackupRuntime Running backup runtime singleton, seen the way the table sees it
     */
    private function runningRuntime(array $overrides = []): BackupRuntime
    {
        $state = StateBackupRuntime::fromRow($overrides + [
            StateBackupRuntime::running => true,
            StateBackupRuntime::currentBackupId => 'b9',
            StateBackupRuntime::scope => 'full',
            StateBackupRuntime::startedAt => '2026-07-20T11:00:00+00:00',
        ]);

        return new BackupRuntime($state);
    }

    /**
     * A stored backup row in the shape the runtime index holds one.
     *
     * @param array<string, mixed> $overrides Fields this row differs in
     * @return array<string, mixed> Runtime row carrying every field it cannot be read without
     */
    private function historyRow(array $overrides = []): array
    {
        return $overrides + [
            BackupHistory::id => 'b1',
            BackupHistory::createdAt => '2026-07-20T10:00:00+00:00',
            BackupHistory::env => 'prod',
            BackupHistory::status => 'success',
            BackupHistory::connections => [],
            BackupHistory::sizeBytes => 0,
            BackupHistory::durationSeconds => 0,
            BackupHistory::keep => false,
            BackupHistory::dumpBytes => 0,
            BackupHistory::restoreDurationSeconds => 0,
        ];
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
