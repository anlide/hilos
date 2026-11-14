<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Generator;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Database;
use Hilos\Files\ContentHash;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Hilos;
use Hilos\Runtime\Exception\State\RtRowCodecNotSupportedException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Row\BackupHistoryRowCodec;
use Hilos\Runtime\Row\HilosProbeNoteRowCodec;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowComparison;
use Hilos\Runtime\Row\RtRowIntegerCast;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRetention;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtMembershipVersion;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtPreparedIdentity;
use Hilos\Runtime\Snapshot\RtPreparedRow;
use Hilos\Runtime\Snapshot\RtSnapshotExport;
use Hilos\Runtime\Snapshot\RtSnapshotImport;
use Hilos\Runtime\Snapshot\RtSnapshotPortion;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtSnapshotStatus;
use Hilos\Runtime\Snapshot\RtVersionCut;
use Hilos\Runtime\Snapshot\RtVersionPages;
use Hilos\Runtime\Snapshot\RtVersionStore;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\Snapshot\RtWorkLane;
use Hilos\Runtime\Snapshot\RtWorkScheduler;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\AuthAttempt;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\HilosStatusMaintenance;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter;
use Hilos\Runtime\State\Item\RestoreRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\State\Item\TableLagRuntime;
use Hilos\Runtime\State\Item\TableRefusalRuntime;
use Hilos\Runtime\View\Actions\Collection\HilosProbeNotesActions;
use Hilos\Runtime\View\Actions\Item\HilosProbeNoteActions;
use Hilos\Runtime\View\Collection\HilosProbeNotes as ViewHilosProbeNotes;
use Hilos\Runtime\View\Context\RtContext;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real segmented row backing, the committed version store, and snapshot generations read out of
 * one collection and staged into another in bounded passes.
 */
final class RtResumableSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$rt = null;
        SourceChangeBus::reset();
        parent::tearDown();
    }

    /** Frozen records keep both complete versions while the source changes between tiny advances. */
    public function testBindingFrozenDescriptorKeepsNestedValuesBaselineAndIncarnation(): void
    {
        $arena = new RtRowArena();
        $row = $this->row();
        $row[BackupHistory::connections] = [[
            BackupConnectionMeta::index => 0,
            BackupConnectionMeta::database => str_repeat('nested-Ж', 800),
            BackupConnectionMeta::migrationIndex => null,
        ]];
        $input = RtRowValues::fromNative($row, $arena);
        $restore = new RtWorkDriver(BackupHistory::fromValues($input->reader()));
        $this->complete($restore);
        $state = $restore->result();
        $state->markRtSyncBaseline();
        $baseline = $state->toArray();
        $state->keep = true;
        $expected = $state->toArray();
        $frozen = $state->freezeRow();
        $id = RtRowValues::fromNative($state->id, $arena);
        $identity = new RtWorkDriver(RtPreparedIdentity::prepare($id));
        $this->complete($identity);
        $prepare = new RtWorkDriver($frozen->prepare($identity->result()));
        $changed = false;
        $this->assertGreaterThan(100, $this->complete($prepare, function () use ($state, &$changed): void {
            if (!$changed) {
                $state->keep = false;
                $state->env = 'changed-during-preparation';
                $state->connections = [];
                $state->markRtSyncBaseline();
                $changed = true;
            }
        }));
        $record = $prepare->result();
        $this->assertSame($expected, $record->values()->toNative());
        $this->assertSame($baseline, $record->baseline()->toNative());
        $this->assertSame($frozen->incarnation, $record->incarnation());
        $this->assertSame($frozen->incarnation, $state->freezeRow()->incarnation);
        $replacement = BackupHistory::fromRow($row);
        $this->assertSame($state->id, $replacement->id);
        $this->assertNotSame($frozen->incarnation, $replacement->freezeRow()->incarnation);
        $this->assertGreaterThan(0, $arena->retention()->bytes(RtRowRole::Snapshot));
        $this->assertGreaterThan(0, $arena->retention()->bytes(RtRowRole::Ordinary));

        $export = new RtWorkDriver($frozen->changes($arena));
        $export->advance(new RtWorkBudget(3, 2));
        $this->assertFalse($export->isFinished());
        $export->cancel();
        unset($state, $restore, $input, $export, $frozen, $identity, $prepare, $id);
        $this->drain($arena);
        $this->assertSame($expected, $record->values()->toNative(), 'Another retained record survives cancellation');
        unset($record);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** The ordinary codec export also reads one descriptor across all yields. */
    public function testBindingCodecExportCannotMixFieldsFromLaterLiveVersions(): void
    {
        $arena = new RtRowArena();
        $state = HilosProbeNote::create('row', 'node', str_repeat('initial-text-', 300));
        $expected = $state->toArray();
        $out = new RtRowWriter($arena);
        $driver = new RtWorkDriver((static function () use ($state, $out): Generator {
            yield from $state->rowCodec()->export($state, $out);

            return yield from $out->finish();
        })());
        $driver->advance(new RtWorkBudget(1, 1));
        $state->text = 'later';
        $this->complete($driver);
        $this->assertSame($expected, $driver->result()->toNative());
    }

    /** Draft writes, refused patches and cancellation leave the live values and baseline alone. */
    public function testBindingDraftIsolationAndSegmentedPreviousMatchLegacyRollback(): void
    {
        $arena = new RtRowArena();
        $state = BackupHistory::fromRow($this->row());
        $baseline = $state->toArray();
        $draft = $state->draftRow();
        $draft->keep = true;
        $draft->env = 'draft';
        $this->assertSame($baseline, $state->toArray());
        $this->assertSame($state->freezeRow()->incarnation, $draft->freezeRow()->incarnation);
        $invalid = RtRowValues::fromNative([BackupHistory::env => 'refused', BackupHistory::keep => null], $arena);
        $patch = new RtWorkDriver($draft->rowCodec()->patch($draft, $invalid->reader()));
        try {
            $this->complete($patch);
            $this->fail('A refused patch must leave the draft as it was');
        } catch (InvalidFormatException) {
            $this->assertSame('draft', $draft->env);
            $this->assertTrue($draft->keep);
        }
        $changes = new RtWorkDriver($draft->freezeRow()->changes($arena));
        $this->complete($changes);
        $this->assertSame([BackupHistory::env => 'draft', BackupHistory::keep => true], $changes->result()->diff->toNative());
        $this->assertSame([BackupHistory::env => 'test', BackupHistory::keep => false], $changes->result()->previous->toNative());
        $unchanged = new RtWorkDriver($state->freezeRow()->changes($arena));
        $this->complete($unchanged);
        $this->assertSame([], $unchanged->result()->diff->toNative());

        Database::transactionStart();
        try {
            $state->env = 'temporary';
            $state->keep = true;
            $state->sync();
        } finally {
            Database::transactionRollback();
        }
        $this->assertSame($baseline, $state->toArray());
        $unchanged = new RtWorkDriver($state->freezeRow()->changes($arena));
        $this->complete($unchanged);
        $this->assertSame([], $unchanged->result()->diff->toNative());
        $this->assertSame([], $unchanged->result()->previous->toNative());
    }

    /** Null/default and structural equality survive different leaf boundaries and nested cancellation. */
    public function testBindingComparisonAndCanceledNestedWalkUseTheSharedRetirementBudget(): void
    {
        $arena = new RtRowArena();
        $nested = str_repeat('same-Ж', 150);
        for ($depth = 0; $depth < 160; ++$depth) {
            $nested = [$nested];
        }
        $left = RtRowValues::fromNative($nested, $arena);
        $out = new RtRowWriter($arena);
        $copy = new RtWorkDriver(RtRowWriter::copy($left->reader(), $arena));
        $this->complete($copy);
        $right = $copy->result();
        $equal = new RtWorkDriver(RtRowComparison::equal($left->reader(), $right->reader()));
        $this->complete($equal);
        $this->assertTrue($equal->result());
        $cancel = new RtWorkDriver(RtRowComparison::equal($left->reader(), $right->reader()));
        $cancel->advance(new RtWorkBudget(300, 1000));
        $this->assertFalse($cancel->isFinished());
        $cancel->cancel();
        $this->assertGreaterThan(0, $arena->retention()->pendingReleases);
        $this->drain($arena);
        $equal = new RtWorkDriver(RtRowComparison::equal($left->reader(), $right->reader()));
        $this->complete($equal);
        $this->assertTrue($equal->result());
        $different = RtRowValues::fromNative([1], $arena);
        $equal = new RtWorkDriver(RtRowComparison::equal($left->reader(), $different->reader()));
        $this->complete($equal);
        $this->assertFalse($equal->result());
        unset($left, $right, $copy, $equal, $cancel, $different, $out);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** Many small rows retain insertion order, immutable codec values and their own baseline across C/U/D. */
    public function testCommittedCutsKeepMembershipValuesAndBaselinesAcrossPreparedChanges(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $store->retainChanges();
        $batch = $store->begin();
        $expected = [];
        for ($index = 0; $index < 96; ++$index) {
            $id = 'row-' . ((53 + $index) % 96);
            $row = $this->preparedNote($arena, $id, 'before-' . $index);
            $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
            $this->complete($driver);
            $expected[$id] = $row->values()->toNative();
        }
        $this->assertSame(0, $store->cut()->revision());
        $store->apply($batch);
        $cut = $store->cut();
        $this->assertSame(96, $cut->revision());
        $this->assertSame(96, $cut->count());
        $this->assertSame($expected, $this->cutRows($cut));

        $updated = $this->preparedNote($arena, 'row-53', 'after', baseline: $this->preparedNote($arena, 'row-53', 'before-0')->values());
        $deleted = $this->preparedNote($arena, 'row-54', 'before-1');
        $created = $this->preparedNote($arena, 'late', 'created');
        $batch = $store->begin();
        foreach ([TableMutationType::Create->value => $created, TableMutationType::Update->value => $updated,
            TableMutationType::Delete->value => $deleted] as $type => $row) {
            $driver = new RtWorkDriver($batch->change(TableMutationType::from($type), $row));
            $this->complete($driver, function () use ($store, $cut): void {
                $this->assertSame(96, $store->cut()->revision());
                $this->assertSame(96, $cut->count());
            });
            $this->assertSame($expected, $this->cutRows($cut));
        }
        $store->apply($batch);
        $current = $store->cut();
        $this->assertSame(99, $current->revision());
        $this->assertSame($expected, $this->cutRows($cut));
        $expected['row-53'][HilosProbeNote::text] = 'after';
        unset($expected['row-54']);
        $expected['late'] = $created->values()->toNative();
        $this->assertSame($expected, $this->cutRows($current));
        $find = new RtWorkDriver($current->find($updated->identity()));
        $this->complete($find);
        $this->assertSame('before-0', $find->result()->baseline()->toNative()[HilosProbeNote::text]);
        $this->assertSame('after', $find->result()->values()->toNative()[HilosProbeNote::text]);
        $this->assertSame(1, $find->result()->incarnation());

        $cursor = $current->changes();
        $changes = [];
        while (true) {
            $driver = new RtWorkDriver($cursor->advance());
            $this->complete($driver);
            if (($change = $driver->result()) === null) {
                break;
            }
            $page = $change->rootIn($arena);
            $changes[] = [$page->scalar, $page->first->scalar];
        }
        $this->assertSame([[97, 'create'], [98, 'update'], [99, 'delete']], $changes);
        $cursor->cancel();

        unset($batch, $row, $updated, $deleted, $created, $driver, $cursor, $change, $page, $find);
        $cut->cancel();
        $current->cancel();
        unset($store, $cut, $current);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Retired));
    }

    /** Long keys/sets hash and compare through tiny byte allowances, including a later row incarnation. */
    public function testLongPreparedIdentitiesAndDeleteRecreateKeepOldCutsDetached(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $id = str_repeat('long-key-Ж', 1000);
        $set = str_repeat('set-Ж', 1200);
        $old = $this->preparedNote($arena, $id, 'old', set: $set);
        $batch = $store->begin();
        $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $old));
        $this->complete($driver);
        $store->apply($batch);
        $cut = $store->cut();
        $this->assertSame($set, $old->identity()->set()->id()->toNative());
        $fresh = $this->preparedNote($arena, $id, 'new', 2, $set);
        $batch = $store->begin();
        foreach ([[TableMutationType::Delete, $old], [TableMutationType::Create, $fresh]] as [$type, $row]) {
            $driver = new RtWorkDriver($batch->change($type, $row));
            $this->complete($driver);
        }
        $store->apply($batch);
        $current = $store->cut();
        foreach ([[$cut, 'old', 1], [$current, 'new', 2]] as [$version, $text, $incarnation]) {
            $find = new RtWorkDriver($version->find($fresh->identity()));
            $this->assertGreaterThan(50, $this->complete($find));
            $this->assertSame($text, $find->result()->values()->toNative()[HilosProbeNote::text]);
            $this->assertSame($incarnation, $find->result()->incarnation());
        }
        $pending = new RtWorkDriver($cut->find($old->identity()));
        $pending->advance(new RtWorkBudget(1, 1));
        $this->assertFalse($pending->isFinished());
        $cut->cancel();
        $this->drain($arena);
        $this->complete($pending);
        $this->assertSame('old', $pending->result()->values()->toNative()[HilosProbeNote::text]);
        $batch = $store->begin();
        $driver = new RtWorkDriver($batch->change(TableMutationType::Update, $old));
        try {
            $this->complete($driver);
            $this->fail('A stale incarnation cannot update its replacement');
        } catch (LogicException $failure) {
            $this->assertStringContainsString('incarnation', $failure->getMessage());
        }
        $batch->cancel();
        $this->assertSame(3, $store->cut()->revision());
        unset($old, $fresh, $row, $batch, $driver, $find, $version, $failure, $pending);
        $cut->cancel();
        $current->cancel();
        unset($store, $cut, $current);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** Distinct long ids sharing one hash still support bucket path-copy, exact lookup and stable insertion order. */
    public function testHashCollisionsCompareSegmentedKeysAndCopyOnlyTheirBucketPath(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $rows = [];
        $prefix = str_repeat('same-prefix-', 400);
        foreach (['first', 'middle', 'last', 'new'] as $suffix) {
            $row = $this->preparedNote($arena, $prefix . $suffix, $suffix);
            $identity = $row->identity();
            // Model a genuine hash collision at the internal prepared-descriptor boundary.
            $descriptor = new RtWorkDriver(RtVersionPages::make(
                $arena,
                RtRowKind::Identity,
                str_repeat("\0", 32),
                $identity->id()->rootIn($arena),
                $identity->descriptor->rootIn($arena)->second,
            ));
            $this->complete($descriptor);
            $identity = new RtPreparedIdentity($arena, $descriptor->result());
            $record = new RtWorkDriver(RtPreparedRow::prepare($identity, $row->values(), $row->baseline(), 1));
            $this->complete($record);
            $rows[] = $record->result();
        }
        $batch = $store->begin();
        foreach (array_slice($rows, 0, 3) as $row) {
            $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
            $this->complete($driver);
        }
        $store->apply($batch);
        $cut = $store->cut();
        $replacement = $this->preparedNote($arena, $prefix . 'first', 'changed');
        $record = new RtWorkDriver(RtPreparedRow::prepare($rows[0]->identity(), $replacement->values(), $rows[0]->values(), 1));
        $this->complete($record);
        $batch = $store->begin();
        foreach ([[TableMutationType::Update, $record->result()], [TableMutationType::Delete, $rows[1]],
            [TableMutationType::Create, $rows[3]]] as [$type, $row]) {
            $driver = new RtWorkDriver($batch->change($type, $row));
            $this->complete($driver);
        }
        $store->apply($batch);
        $current = $store->cut();
        $this->assertSame(['first', 'middle', 'last'], array_column($this->cutRows($cut), HilosProbeNote::text));
        $this->assertSame(['changed', 'last', 'new'], array_column($this->cutRows($current), HilosProbeNote::text));
        foreach ($rows as $index => $row) {
            $find = new RtWorkDriver($current->find($row->identity()));
            $this->complete($find);
            if ($index === 1) {
                $this->assertNull($find->result());
            } else {
                $this->assertSame($index === 0 ? 'changed' : ['last', 'new'][$index - 2],
                    $find->result()->values()->toNative()[HilosProbeNote::text]);
            }
        }
        unset($rows, $row, $identity, $descriptor, $record, $driver, $batch, $replacement, $find);
        $cut->cancel();
        $current->cancel();
        unset($cut, $current, $store);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** Cancel closes only its generation; shared pages stay physically charged until the last ref retires. */
    public function testVersionCancellationAndLastReferenceRetireWithinTheSharedWorkBudget(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $batch = $store->begin();
        for ($index = 0; $index < 40; ++$index) {
            $row = $this->preparedNote($arena, 'row-' . $index, str_repeat('value', 50));
            $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
            $this->complete($driver);
        }
        $store->apply($batch);
        unset($batch, $driver, $row);
        $this->drain($arena);
        $physical = $arena->retention()->physicalBytes;
        $first = $store->cut();
        $second = $store->cut();
        $this->assertSame($physical, $arena->retention()->physicalBytes, 'Cuts share physical pages');
        $this->assertGreaterThan(0, $arena->retention()->bytes(RtRowRole::Snapshot));
        $first->cancel();
        $this->assertSame($physical, $arena->retention()->physicalBytes);
        $this->drain($arena);
        $this->assertSame($physical, $arena->retention()->physicalBytes);

        $cursor = $second->rows();
        $walking = new RtWorkDriver($cursor->advance());
        for ($pass = 0; $pass < 3; ++$pass) {
            $walking->advance(new RtWorkBudget(7, 3));
        }
        $this->assertFalse($walking->isFinished());
        $beforeCursorCancel = $arena->retention()->physicalBytes;
        $cursor->cancel();
        $this->assertSame($beforeCursorCancel, $arena->retention()->physicalBytes);
        $this->drain($arena);
        try {
            $this->complete($walking);
            $this->fail('A canceled cursor cannot return a partially prepared traversal');
        } catch (LogicException $failure) {
            $this->assertStringContainsString('closed', $failure->getMessage());
        }
        $walking->cancel();
        unset($walking, $cursor, $failure);
        $this->drain($arena);
        $this->assertSame($physical, $arena->retention()->physicalBytes);

        $batch = $store->begin();
        $row = $this->preparedNote($arena, 'canceled', str_repeat('candidate', 100));
        $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
        for ($pass = 0; $pass < 80; ++$pass) {
            $budget = new RtWorkBudget(1, 1);
            $driver->advance($budget);
            $this->assertLessThanOrEqual(1, $budget->usedSteps());
            $this->assertLessThanOrEqual(1, $budget->usedBytes());
        }
        $this->assertFalse($driver->isFinished());
        $beforeCancel = $arena->retention()->physicalBytes;
        $driver->cancel();
        $batch->cancel();
        $this->assertSame($beforeCancel, $arena->retention()->physicalBytes);
        unset($batch, $driver, $row);
        $this->drain($arena);
        $this->assertSame(40, $second->count());
        $this->assertCount(40, $this->cutRows($second));
        unset($store, $first);
        $this->drain($arena);
        $this->assertGreaterThan(0, $arena->retention()->physicalBytes);
        $second->cancel();
        $beforeRetire = $arena->retention()->physicalBytes;
        $arena->advanceRetirement(new RtWorkBudget(1, 1));
        $this->assertSame($beforeRetire, $arena->retention()->physicalBytes);
        $this->assertGreaterThan(50, $this->drain($arena));
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Candidate));
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Snapshot));
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Retired));
    }

    /**
     * A selector records its changes only while a reader holds them: without one a write builds no
     * change record, and a replaced row's values do not outlive it in the committed version; the
     * next install after the last hold leaves no prefix behind.
     */
    public function testChangesAreRecordedOnlyWhileAReaderHoldsThem(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $this->commitVersion($store, TableMutationType::Create, $this->preparedNote($arena, 'n1', 'first'));
        $this->assertNull($this->firstChange($store->cut()), 'Nobody holds the changes, so none is recorded');

        $store->retainChanges();
        $this->commitVersion($store, TableMutationType::Create, $this->preparedNote($arena, 'n2', 'second'));
        $this->assertSame(TableMutationType::Create->value, $this->firstChange($store->cut()));

        $store->releaseChanges();
        $this->commitVersion($store, TableMutationType::Create, $this->preparedNote($arena, 'n3', 'third'));
        $this->assertNull($this->firstChange($store->cut()), 'The next install after the last hold leaves no prefix');
        $this->assertSame(['n1', 'n2', 'n3'], array_keys($this->cutRows($store->cut())));
        unset($store);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** An actual memory-only transaction keeps its prepared prefix invisible and rolls back without revisions or deltas. */
    public function testOpenTransactionAndRollbackNeverEnterTheCommittedCut(): void
    {
        $arena = new RtRowArena();
        $store = new RtVersionStore($arena);
        $row = $this->preparedNote($arena, 'rolled-back', 'temporary');
        Database::transactionStart();
        try {
            $batch = $store->begin();
            $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
            $this->complete($driver);
            $store->apply($batch);
            $this->assertSame([], $this->cutRows($store->cut()));
            $this->assertSame(0, $store->cut()->revision());
        } finally {
            Database::transactionRollback();
        }
        $cut = $store->cut();
        $driver = new RtWorkDriver($cut->changes()->advance());
        $this->complete($driver);
        $this->assertNull($driver->result());
        $this->assertSame(0, $cut->revision());
        $batch = $store->begin();
        $driver = new RtWorkDriver($batch->change(TableMutationType::Create, $row));
        $this->complete($driver);
        $store->apply($batch);
        $this->assertSame(1, $store->cut()->revision());
        unset($batch, $driver, $row, $cut, $store);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** A large field and a long value inside one record both cross many budget boundaries. */
    public function testLargeBackupHistoryRestoresExportsAndPatchesInBoundedParts(): void
    {
        $arena = new RtRowArena();
        $row = $this->row();
        $longDatabase = str_repeat("database-\0-Ж-", 9000);
        for ($index = 0; $index < 120; ++$index) {
            $row[BackupHistory::connections]['source-' . $index] = [
                BackupConnectionMeta::index => (string)$index,
                BackupConnectionMeta::database => $index === 61 ? $longDatabase : 'db-' . $index,
                BackupConnectionMeta::migrationIndex => $index % 2 === 0 ? null : (string)$index,
            ];
        }
        $row[BackupHistory::connections]['legacy'] = [];
        $incoming = RtRowValues::fromNative($row, $arena, RtRowRole::Snapshot);
        $codec = new BackupHistoryRowCodec();
        $restore = new RtWorkDriver($codec->restore($incoming->reader()));
        $passes = $this->complete($restore);
        $this->assertGreaterThan(100, $passes);
        $history = $restore->result();
        $this->assertInstanceOf(BackupHistory::class, $history);
        $expected = BackupHistory::fromRow($row)->toArray();
        $this->assertSame($expected, $history->toArray());
        $incoming->release();
        $this->drain($arena);
        $this->assertGreaterThan(strlen($longDatabase), $arena->retention()->bytes(RtRowRole::Candidate));
        $this->assertGreaterThan(0, $arena->retention()->physicalBytes);
        $this->assertCount(121, $history->connections);
        $this->assertInstanceOf(BackupConnectionMeta::class, $history->connections[61]);
        $this->assertSame($longDatabase, $history->connections[61]->database);
        $this->assertSame('2026-11-05T09:00:00.000000Z', $history->createdAt);
        $this->assertSame([
            BackupConnectionMeta::index => 0,
            BackupConnectionMeta::database => '',
            BackupConnectionMeta::migrationIndex => null,
        ], $expected[BackupHistory::connections][120]);

        $destination = new RtRowArena();
        $writer = new RtRowWriter($destination, RtRowRole::Wire);
        $export = new RtWorkDriver((static function () use ($codec, $history, $writer): Generator {
            yield from $codec->export($history, $writer);

            return yield from $writer->finish();
        })());
        $this->assertGreaterThan(100, $this->complete($export));
        $this->assertSame($expected, $export->result()->toNative());

        $diff = RtRowValues::fromNative([
            BackupHistory::id => 'ignored-like-the-legacy-patch',
            BackupHistory::keep => true,
            BackupHistory::connections => ['non-list-input' => [
                BackupConnectionMeta::index => '  -12.75e1tail',
                BackupConnectionMeta::database => false,
                BackupConnectionMeta::migrationIndex => true,
            ]],
            BackupHistory::verifiedAt => '2026-11-05T12:30:00+03:00',
        ], $arena);
        $patch = new RtWorkDriver($codec->patch($history, $diff->reader()));
        $this->complete($patch, function () use ($history): void {
            $this->assertFalse($history->keep);
            $this->assertNull($history->verifiedAt);
        });
        $legacy = BackupHistory::fromRow($expected);
        $legacy->applyDiff($diff->toNative());
        $this->assertSame($legacy->toArray(), $history->toArray());
        $this->assertSame($row[BackupHistory::id], $history->id);

        unset($history, $restore, $patch, $export, $writer, $incoming, $diff, $legacy);
        $this->drain($arena);
        $this->drain($destination);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $destination->retention()->physicalBytes);
    }

    /** Rejection after a long validated prefix leaves all original fields readable. */
    public function testValidatorRefusesRestoreAndPatchWithoutInstallingPartialFields(): void
    {
        $arena = new RtRowArena();
        $codec = new BackupHistoryRowCodec();
        $valid = RtRowValues::fromNative($this->row(), $arena);
        $restore = new RtWorkDriver($codec->restore($valid->reader()));
        $this->complete($restore);
        $history = $restore->result();
        $before = $history->toArray();
        $connections = [];
        for ($index = 0; $index < 60; ++$index) {
            $connections[] = [BackupConnectionMeta::database => str_repeat('long-database', 40)];
        }
        $connections[] = 'a record must be an array';
        $badDiff = RtRowValues::fromNative([
            BackupHistory::env => 'would-have-changed',
            BackupHistory::keep => true,
            BackupHistory::connections => $connections,
        ], $arena);
        $patch = new RtWorkDriver($codec->patch($history, $badDiff->reader()));
        try {
            $this->complete($patch, function () use ($history): void {
                $this->assertFalse($history->keep);
                $this->assertSame('test', $history->env);
            });
            $this->fail('The non-array connection must refuse the draft');
        } catch (InvalidFormatException $exception) {
            $this->assertStringContainsString(BackupHistory::connections, $exception->getMessage());
        }
        $this->assertTrue($patch->isCanceled());
        $this->assertSame($before, $history->toArray());
        unset($exception); // Its trace legitimately retains the refused preparation's inputs.

        $badRow = $this->row();
        $badRow[BackupHistory::connections] = [[BackupConnectionMeta::database => ['not', 'a', 'name']]];
        $badValues = RtRowValues::fromNative($badRow, $arena);
        $refused = new RtWorkDriver($codec->restore($badValues->reader()));
        try {
            $this->complete($refused);
            $this->fail('A connection database that is no scalar must refuse the row, not read as "Array"');
        } catch (InvalidFormatException $exception) {
            $this->assertStringContainsString(BackupHistory::connections, $exception->getMessage());
            $this->assertTrue($refused->isCanceled());
        }
        unset($exception);

        foreach ([BackupHistory::createdAt, BackupHistory::reachable] as $field) {
            $badRow = $this->row();
            $badRow[$field] = $field === BackupHistory::createdAt ? '2026-02-30T00:00:00Z' : 1;
            $badValues = RtRowValues::fromNative($badRow, $arena);
            $refused = new RtWorkDriver($codec->restore($badValues->reader()));
            try {
                $this->complete($refused);
                $this->fail('The concrete row validator must refuse this field');
            } catch (InvalidFormatException) {
                $this->assertTrue($refused->isCanceled());
            }
        }
        unset($history, $restore, $valid, $badDiff, $patch, $badValues, $refused);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->pages);
    }

    /** Cancelling one holder never releases shared pages needed by ordinary delivery. */
    public function testSharedLeasesAndCanceledDraftRetireOnlyWithTheirLastReferences(): void
    {
        $arena = new RtRowArena();
        $value = RtRowValues::fromNative([str_repeat('shared', 14000), range(0, 80)], $arena);
        $snapshot = $value->retain(RtRowRole::Snapshot);
        $ordinary = $value->retain(RtRowRole::Ordinary);
        $this->drain($arena);
        $residentBytes = $arena->retention()->bytes(RtRowRole::Resident);
        $physicalBytes = $arena->retention()->physicalBytes;
        $this->assertGreaterThan(0, $residentBytes);
        $this->assertSame($residentBytes, $arena->retention()->bytes(RtRowRole::Snapshot));
        $snapshot->release();
        $this->assertSame($physicalBytes, $arena->retention()->physicalBytes);
        $this->assertSame($residentBytes, $arena->retention()->bytes(RtRowRole::Snapshot));
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Snapshot));
        $this->assertSame($physicalBytes, $arena->retention()->physicalBytes);

        $copy = new RtWorkDriver(RtRowWriter::copy($value->reader(), $arena, RtRowRole::Candidate));
        for ($pass = 0; $pass < 8; ++$pass) {
            $budget = new RtWorkBudget(9, 13);
            $copy->advance($budget);
            $this->assertLessThanOrEqual(9, $budget->usedSteps());
            $this->assertLessThanOrEqual(13, $budget->usedBytes());
        }
        $this->assertFalse($copy->isFinished());
        $beforeCancel = $arena->retention()->physicalBytes;
        $copy->cancel();
        $this->assertTrue($copy->isCanceled());
        $this->assertSame($beforeCancel, $arena->retention()->physicalBytes);
        $closedBudget = new RtWorkBudget(5, 7);
        $copy->advance($closedBudget);
        $this->assertSame(0, $closedBudget->usedSteps());
        $this->drain($arena);
        $this->assertSame($physicalBytes, $arena->retention()->physicalBytes);
        $value->release();
        $this->drain($arena);
        $this->assertSame($physicalBytes, $arena->retention()->physicalBytes);
        $this->assertSame(str_repeat('shared', 14000), $ordinary->toNative()[0]);
        $ordinary->release();
        $this->assertSame($physicalBytes, $arena->retention()->physicalBytes);
        $this->assertGreaterThan(100, $this->drain($arena, true));
        $this->assertSame(0, $arena->retention()->physicalBytes);
        foreach (RtRowRole::cases() as $role) {
            $this->assertSame(0, $arena->retention()->bytes($role));
            $this->assertSame(0, $arena->retention()->leases($role));
        }

        // Distinct roots may also share a child, not just leases on the same root.
        $payload = RtRowValues::fromNative(str_repeat('same child', 3000), $arena);
        $roots = [];
        for ($index = 0; $index < 2; ++$index) {
            $writer = new RtRowWriter($arena);
            $build = new RtWorkDriver((static function () use ($writer, $payload): Generator {
                yield from $writer->write('payload', $payload);

                return yield from $writer->finish();
            })());
            $this->complete($build);
            $roots[] = $build->result();
        }
        $payload->release();
        $this->drain($arena);
        $withBothRoots = $arena->retention()->physicalBytes;
        $roots[0]->release();
        $this->drain($arena);
        $this->assertGreaterThan(0, $arena->retention()->physicalBytes);
        $this->assertLessThan($withBothRoots, $arena->retention()->physicalBytes);
        $this->assertSame(str_repeat('same child', 3000), $roots[1]->toNative()['payload']);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Retired));
        $roots[1]->release();
        $this->drain($arena, true);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Retired));
    }

    /** A traversal stack is service state and must also retire in bounded parts. */
    public function testDeepCopyAndItsCancellationDoNotDropANativeTraversalStack(): void
    {
        $arena = new RtRowArena();
        $nested = str_repeat('deep value', 1200);
        for ($depth = 0; $depth < 400; ++$depth) {
            $nested = [$nested];
        }
        $source = RtRowValues::fromNative($nested, $arena, RtRowRole::Snapshot);
        $copy = new RtWorkDriver(RtRowWriter::copy($source->reader(), $arena, RtRowRole::Candidate));
        $this->complete($copy);
        $this->assertSame($nested, $copy->result()->toNative());
        $copy->result()->release();
        unset($copy);
        $this->drain($arena);

        $canceled = new RtWorkDriver(RtRowWriter::copy($source->reader(), $arena, RtRowRole::Candidate));
        for ($pass = 0; $pass < 80; ++$pass) {
            $budget = new RtWorkBudget(17, 5);
            $canceled->advance($budget);
            $this->assertLessThanOrEqual(17, $budget->usedSteps());
            $this->assertLessThanOrEqual(5, $budget->usedBytes());
        }
        $this->assertFalse($canceled->isFinished());
        $physical = $arena->retention()->physicalBytes;
        $transient = $arena->retention()->bytes(RtRowRole::Transient);
        $this->assertGreaterThan(0, $transient);
        $canceled->cancel();
        $this->assertSame($physical, $arena->retention()->physicalBytes);
        $this->assertSame($transient, $arena->retention()->bytes(RtRowRole::Transient));
        $source->release();
        $this->assertGreaterThan(100, $this->drain($arena));
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Transient));
        $this->assertSame(0, $arena->retention()->bytes(RtRowRole::Retired));
    }

    /** Work is shared within a pass; only an idle lane's unused share is borrowable. */
    public function testOnePassSharesItsAllowanceAndClosesOldConsumers(): void
    {
        $scheduler = new RtWorkScheduler(16, 64);
        $ordinary = $scheduler->budget(RtWorkLane::Ordinary);
        $snapshot = $scheduler->budget(RtWorkLane::Snapshot);
        $retire = $scheduler->budget(RtWorkLane::Retire);
        $this->assertSame($ordinary, $scheduler->budget(RtWorkLane::Ordinary));
        for ($index = 0; $index < 8; ++$index) {
            $this->assertSame(4, $ordinary->take(4));
        }
        $this->assertNull($ordinary->take(1));
        $scheduler->finish(RtWorkLane::Snapshot);
        $this->assertSame(24, $ordinary->take(100));
        $this->assertSame(8, $retire->take(100));
        $this->assertSame(64, $ordinary->usedBytes() + $snapshot->usedBytes() + $retire->usedBytes());
        while ($ordinary->take() !== null) {
        }
        while ($retire->take() !== null) {
        }
        $this->assertSame(16, $ordinary->usedSteps() + $snapshot->usedSteps() + $retire->usedSteps());
        $scheduler->beginPass();
        $this->assertNull($ordinary->take());
        $this->assertSame(32, $scheduler->budget(RtWorkLane::Ordinary)->take(100));

        $default = new RtWorkBudget();
        $this->assertSame(65536, $default->take(PHP_INT_MAX));
        for ($index = 1; $index < 128; ++$index) {
            $this->assertSame(0, $default->take());
        }
        $this->assertNull($default->take());
        $this->assertSame(128, $default->usedSteps());
    }

    /** Numeric-prefix compatibility must not require materializing a long numeric string. */
    public function testConnectionIntegerCastsRemainCompatibleAcrossTinyBudgets(): void
    {
        $arena = new RtRowArena();
        foreach ([
            null, true, false, 17, -19.75, [], [1], '', '  -12.75e1tail', '1e+',
            '1.0e+', '.75', 'word', '9223372036854775808', '-9223372036854775809',
            str_repeat('0', 9000) . '123', '1.' . str_repeat('0', 9000) . '1e2',
            '999.999999999999999999999999999999999999999', '1e999999999999999999999999',
        ] as $native) {
            $value = RtRowValues::fromNative($native, $arena);
            $cast = new RtWorkDriver(RtRowIntegerCast::read($value->reader()));
            $this->complete($cast);
            $this->assertSame((int)$native, $cast->result());
        }
        unset($cast, $value);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** Existing third-party synchronous rows remain usable but explicitly opt out. */
    public function testUnmigratedRowsRefuseTheNewPathWithoutInvokingSerialization(): void
    {
        $legacy = UnmigratedSnapshotRow::fromRow([]);
        $this->assertSame('legacy', $legacy->getId());
        foreach (['freezeRow', 'draftRow'] as $method) {
            try {
                UnmigratedSnapshotRow::fromRow([])->$method();
                $this->fail('A legacy-only row must refuse the binding path');
            } catch (RtRowCodecNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(RtRowCodecNotSupportedException::class);
        $legacy->rowCodec();
    }

    /**
     * @param class-string<RtState> $rowClass Concrete row under test
     * @param array<string, mixed> $row Full row with intentional nullable omissions
     * @param array<string, mixed> $diff Mutable fields and ignored identity fields
     */
    #[DataProvider('scalarRows')]
    public function testScalarRowsKeepTheirIdentityBackingAndLegacySemantics(string $rowClass, array $row, array $diff): void
    {
        $arena = new RtRowArena();
        $destination = new RtRowArena();
        $legacy = $rowClass::fromRow($row);
        $before = $legacy->toArray();
        $codec = $legacy->rowCodec();
        $incoming = RtRowValues::fromNative($row, $arena, RtRowRole::Snapshot);
        $restore = new RtWorkDriver($codec->restore($incoming->reader()));
        $this->complete($restore);
        $draft = $restore->result();
        $this->assertInstanceOf($rowClass, $draft);
        $this->assertSame($before, $draft->toArray());
        $this->assertSame($legacy->getId(), $draft->getId());
        $incoming->release();
        $this->drain($arena);
        if (count(array_filter($row, is_string(...))) > 0) {
            $this->assertGreaterThan(0, $arena->retention()->bytes(RtRowRole::Candidate));
        }

        $writer = new RtRowWriter($destination, RtRowRole::Wire);
        $export = new RtWorkDriver((static function () use ($codec, $draft, $writer): Generator {
            yield from $codec->export($draft, $writer);

            return yield from $writer->finish();
        })());
        $this->complete($export);
        $this->assertSame($before, $export->result()->toNative());

        $changes = RtRowValues::fromNative($diff, $arena);
        $patch = new RtWorkDriver($codec->patch($draft, $changes->reader()));
        $this->complete($patch, function () use ($draft, $before): void {
            $this->assertSame($before, $draft->toArray());
        });
        $legacy->applyDiff($diff);
        $this->assertSame($legacy->toArray(), $draft->toArray());
        $this->assertSame($legacy->getId(), $draft->getId());
        $this->assertSame($rowClass::getRtCollectionKey(), $draft::getRtCollectionKey());
        unset($draft, $restore, $export, $writer, $patch, $changes, $incoming, $legacy);
        $this->drain($arena);
        $this->drain($destination);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $destination->retention()->physicalBytes);
        foreach (RtRowRole::cases() as $role) {
            $this->assertSame(0, $arena->retention()->leases($role));
            $this->assertSame(0, $destination->retention()->leases($role));
        }
    }

    /**
     * @param class-string<RtState> $rowClass Concrete row under test
     * @param array<string, mixed> $row Full row whose first field is required
     * @param array<string, mixed> $diff Unused valid patch from the shared cases
     */
    #[DataProvider('scalarRows')]
    public function testScalarCodecsRefuseMissingNullAndMistypedRequiredFields(string $rowClass, array $row, array $diff): void
    {
        $codec = $rowClass::fromRow($row)->rowCodec();
        $key = array_key_first($row);
        foreach (['missing', null, []] as $invalid) {
            $badRow = $row;
            if ($invalid === 'missing') {
                unset($badRow[$key]);
            } else {
                $badRow[$key] = $invalid;
            }
            try {
                $rowClass::fromRow($badRow);
                $this->fail('The legacy row requires this field');
            } catch (InvalidFormatException) {
                $this->assertArrayHasKey($key, $row);
            }
            $arena = new RtRowArena();
            $incoming = RtRowValues::fromNative($badRow, $arena);
            $restore = new RtWorkDriver($codec->restore($incoming->reader()));
            try {
                $this->complete($restore);
                $this->fail('The segmented row must preserve the required field contract');
            } catch (InvalidFormatException) {
                $this->assertTrue($restore->isCanceled());
            }
            unset($incoming, $restore);
            $this->drain($arena);
            $this->assertSame(0, $arena->retention()->physicalBytes);
        }
    }

    /** @return iterable<string, array{class-string<RtState>, array<string, mixed>, array<string, mixed>}> Concrete row cases */
    public static function scalarRows(): iterable
    {
        $long = str_repeat("scalar-\0-Ж-", 1300);
        yield 'admin view mode' => [AdminViewModeRuntime::class, [AdminViewModeRuntime::enabled => false], [AdminViewModeRuntime::enabled => true]];
        yield 'auth counter and float widening' => [AuthAttempt::class, [
            AuthAttempt::scope => ThrottleScope::IP, AuthAttempt::identity => $long, AuthAttempt::action => 'sign-in',
            AuthAttempt::count => 0, AuthAttempt::windowStartedAt => 12, AuthAttempt::level => 2,
        ], [AuthAttempt::identity => 'ignored', AuthAttempt::count => 1, AuthAttempt::blockedUntil => 0]];
        yield 'backup progress and UTC normalization' => [BackupRuntime::class, [
            BackupRuntime::running => false, BackupRuntime::currentBackupId => $long,
            BackupRuntime::startedAt => '2026-11-05T10:00:00+01:00',
        ], [BackupRuntime::running => true, BackupRuntime::scope => $long,
            BackupRuntime::phaseStartedAt => '2026-11-05T12:30:00+03:00', BackupRuntime::startedAt => null]];
        yield 'send attempt nullable fields' => [HilosCodeSendAttempt::class, [
            HilosCodeSendAttempt::sessionTokenHash => 'session-hash', HilosCodeSendAttempt::ticket => 'ticket',
            HilosCodeSendAttempt::channel => HilosCodeSendAttempt::CHANNEL_EMAIL,
            HilosCodeSendAttempt::state => HilosCodeSendAttempt::STATE_QUEUED, HilosCodeSendAttempt::updatedAt => 10,
            HilosCodeSendAttempt::detail => $long,
        ], [HilosCodeSendAttempt::ticket => 'ignored', HilosCodeSendAttempt::sessionTokenHash => 'ignored',
            HilosCodeSendAttempt::detail => null, HilosCodeSendAttempt::reason => $long, HilosCodeSendAttempt::resendAt => 0]];
        yield 'OAuth trip addresses and ending' => [HilosOAuthTrip::class, [
            HilosOAuthTrip::tripKeyHash => 'trip-hash', HilosOAuthTrip::sessionTokenHash => 'session-hash',
            HilosOAuthTrip::acceptKey => $long, HilosOAuthTrip::mode => OAuthPendingLogin::MODE_LOGIN,
            HilosOAuthTrip::provider => 'oauth:example', HilosOAuthTrip::updatedAt => 0,
        ], [HilosOAuthTrip::tripKeyHash => 'ignored', HilosOAuthTrip::mode => 'ignored',
            HilosOAuthTrip::ending => $long, HilosOAuthTrip::acceptKey => '', HilosOAuthTrip::userId => 0]];
        yield 'fleet status' => [HilosProbeFleetStatus::class, [
            HilosProbeFleetStatus::workerIndex => $long, HilosProbeFleetStatus::jobsDone => 0,
            HilosProbeFleetStatus::rowsSeen => 2, HilosProbeFleetStatus::updatedAt => 10,
        ], [HilosProbeFleetStatus::workerIndex => 'ignored', HilosProbeFleetStatus::rowsSeen => 0]];
        yield 'note belongs to its node' => [HilosProbeNote::class, [
            HilosProbeNote::noteId => 'note', HilosProbeNote::nodeId => $long, HilosProbeNote::text => $long,
        ], [HilosProbeNote::nodeId => 'ignored', HilosProbeNote::noteId => 'ignored', HilosProbeNote::text => $long . 'patched']];
        yield 'profile flow key and nullable target' => [HilosProfileFlow::class, [
            HilosProfileFlow::sessionTokenHash => 'session-hash', HilosProfileFlow::operation => StepUpOperationKey::CHANGE_EMAIL,
            HilosProfileFlow::userId => 7, HilosProfileFlow::step => HilosProfileFlow::STEP_CURRENT_SENT,
            HilosProfileFlow::address => $long, HilosProfileFlow::expiresAt => 0,
        ], [HilosProfileFlow::sessionTokenHash => 'ignored', HilosProfileFlow::operation => 'ignored',
            HilosProfileFlow::target => $long, HilosProfileFlow::address => '', HilosProfileFlow::expiresAt => 12]];
        yield 'name restore and patch do not trim' => [HilosProfileNameChange::class, [
            HilosProfileNameChange::sessionTokenHash => 'session-hash', HilosProfileNameChange::userId => 7,
            HilosProfileNameChange::requestId => 'request', HilosProfileNameChange::originAcceptKey => 'connection',
            HilosProfileNameChange::name => '  ' . $long . '  ', HilosProfileNameChange::phase => HilosProfileNameChange::PHASE_CHECKING,
            HilosProfileNameChange::startedAt => 0,
        ], [HilosProfileNameChange::sessionTokenHash => 'ignored', HilosProfileNameChange::name => '  patched  ',
            HilosProfileNameChange::refusal => $long, HilosProfileNameChange::deadlineAt => 0]];
        yield 'Status key and unknown observations' => [HilosStatusComponent::class, [
            HilosStatusComponent::componentKey => 'component-' . str_repeat('a', 118), HilosStatusComponent::expectedInstances => 2,
        ], [HilosStatusComponent::expectedInstances => 1, HilosStatusComponent::liveInstances => 0, HilosStatusComponent::observedAt => 0]];
        yield 'upload declarations and hold marks' => [HilosUpload::class, [
            HilosUpload::acceptKey => 'connection', HilosUpload::clientUploadId => 'upload', HilosUpload::target => 'target',
            HilosUpload::filename => $long, HilosUpload::mimeType => 'image/example', HilosUpload::declaredSize => 10,
            HilosUpload::receivedBytes => 0, HilosUpload::phase => UploadPhase::READY->value, HilosUpload::updatedAt => 10,
            HilosUpload::uploadHoldId => null, HilosUpload::uploadHoldDeadlineAt => null, HilosUpload::uploadHoldApproved => false,
        ], [HilosUpload::acceptKey => 'ignored', HilosUpload::filename => 'ignored', HilosUpload::receivedBytes => 10,
            HilosUpload::phase => UploadPhase::COMPLETE->value, HilosUpload::uploadHoldId => $long,
            HilosUpload::uploadHoldDeadlineAt => 12, HilosUpload::uploadHoldApproved => true]];
        yield 'OAuth pending legacy patch is deliberately empty' => [OAuthPendingLogin::class, [
            OAuthPendingLogin::acceptKey => 'connection', OAuthPendingLogin::sessionToken => 'token',
            OAuthPendingLogin::provider => 'oauth:example', OAuthPendingLogin::code => $long,
            OAuthPendingLogin::tripKeyHash => 'trip-hash', OAuthPendingLogin::mode => OAuthPendingLogin::MODE_LINK,
            OAuthPendingLogin::linkUserId => 7,
        ], [OAuthPendingLogin::code => ['ignored malformed value'], OAuthPendingLogin::mode => null, OAuthPendingLogin::linkUserId => 'ignored']];
        yield 'recovery grant' => [RecoveryWaiter::class, [
            RecoveryWaiter::acceptKey => 'connection', RecoveryWaiter::identifier => $long,
            RecoveryWaiter::sessionToken => 'token', RecoveryWaiter::codeAccepted => false,
        ], [RecoveryWaiter::acceptKey => 'ignored', RecoveryWaiter::identifier => '', RecoveryWaiter::codeAccepted => true]];
        yield 'registration waiter' => [RegistrationWaiter::class, [
            RegistrationWaiter::acceptKey => 'connection', RegistrationWaiter::identifier => $long, RegistrationWaiter::sessionToken => 'token',
        ], [RegistrationWaiter::acceptKey => 'ignored', RegistrationWaiter::sessionToken => $long]];
        yield 'table lag singleton' => [TableLagRuntime::class, [TableLagRuntime::windowMs => 0, TableLagRuntime::facetsMs => 1], [
            TableLagRuntime::windowMs => 1, TableLagRuntime::facetsMs => 0,
        ]];
        yield 'table refusal singleton' => [TableRefusalRuntime::class,
            [TableRefusalRuntime::tableKey => $long], [TableRefusalRuntime::tableKey => '']];
    }

    /** Factory defaults are valid backing even before any field was explicitly written. */
    public function testFactoryRowsExportAndAcceptAnEmptyPatchWithoutMaterializingDefaults(): void
    {
        foreach ([
            AdminViewModeRuntime::create(), AuthAttempt::create(ThrottleScope::IP, 'identity', 'action'), BackupRuntime::create(),
            HilosCodeSendAttempt::create('hash', 'ticket', HilosCodeSendAttempt::CHANNEL_EMAIL, 1),
            HilosOAuthTrip::create('trip', 'session', 'connection', OAuthPendingLogin::MODE_LOGIN, 'oauth:example', 1),
            HilosProbeFleetStatus::create('fleet'), HilosProbeNote::create('note', 'node', 'text'),
            HilosProfileFlow::create('hash', StepUpOperationKey::CHANGE_EMAIL, 7, HilosProfileFlow::STEP_CURRENT_SENT, 'address', null, 1),
            HilosProfileNameChange::create('hash', 7, 'request', 'connection', '  requested  ', 1, null),
            HilosStatusComponent::create('component', 1),
            HilosUpload::create('connection', 'upload', 'target', null, 'file', 'image/example', 1, 'tmp', 1),
            OAuthPendingLogin::create('connection', 'token', 'oauth:example', 'code', 'hash'),
            RecoveryWaiter::create('connection', 'address', 'token'), RegistrationWaiter::create('connection', 'address', 'token'),
            TableLagRuntime::create(), TableRefusalRuntime::create(),
            HilosClusterNode::create('node', 'Node', 'role', [], null, false, 0.0),
            HilosSessionRotation::create('ticket', 'token', [], 0.0),
            HilosSessionToastStack::create('session', []), RestoreRuntime::create(),
            HilosStatusMaintenance::create(), ProtectedModeRuntime::create(),
            HilosModerationCheck::create('accept', 7, 'kind', 'request', 'owner', null, null, 11, 22),
        ] as $state) {
            $arena = new RtRowArena();
            $before = $state->toArray();
            $codec = $state->rowCodec();
            $empty = RtRowValues::fromNative([], $arena);
            $patch = new RtWorkDriver($codec->patch($state, $empty->reader()));
            $this->complete($patch);
            $this->assertSame($before, $state->toArray());
            $writer = new RtRowWriter($arena);
            $export = new RtWorkDriver((static function () use ($codec, $state, $writer): Generator {
                yield from $codec->export($state, $writer);

                return yield from $writer->finish();
            })());
            $this->complete($export);
            $this->assertSame($before, $export->result()->toNative());
            unset($empty, $patch, $export, $writer);
            $this->drain($arena);
            $this->assertSame(0, $arena->retention()->physicalBytes);
        }
    }

    /** Factories alone apply their own normalization; missing nullable fields stay null, never property defaults. */
    public function testScalarFactoriesNullOmissionAndDefaultsRemainDistinct(): void
    {
        $this->assertSame('requested', HilosProfileNameChange::create('hash', 7, 'request', 'connection', '  requested  ', 1, null)->name);
        $this->assertSame('login', OAuthPendingLogin::create('connection', 'token', 'oauth:example', 'code', 'hash')->mode);
        $this->assertSame(0, OAuthPendingLogin::create('connection', 'token', 'oauth:example', 'code', 'hash')->linkUserId);
        $this->assertFalse(AdminViewModeRuntime::create()->enabled);
        $this->assertSame('', TableRefusalRuntime::create()->tableKey);
        $this->assertSame('nodeId', HilosProbeNote::SET_VIA);

        $arena = new RtRowArena();
        $codec = BackupRuntime::create()->rowCodec();
        $row = RtRowValues::fromNative([BackupRuntime::running => false, BackupRuntime::scope => ''], $arena);
        $restore = new RtWorkDriver($codec->restore($row->reader()));
        $this->complete($restore);
        $backup = $restore->result();
        $this->assertSame('', $backup->scope);
        $this->assertNull($backup->startedAt);
        $this->assertNull($backup->estimatedSeconds);
        $changes = RtRowValues::fromNative([BackupRuntime::estimatedSeconds => 0], $arena);
        $patch = new RtWorkDriver($codec->patch($backup, $changes->reader()));
        $this->complete($patch);
        $this->assertSame('', $backup->scope);
        $this->assertSame(0, $backup->estimatedSeconds);
        $changes = RtRowValues::fromNative([BackupRuntime::scope => null], $arena);
        $patch = new RtWorkDriver($codec->patch($backup, $changes->reader()));
        $this->complete($patch);
        $this->assertNull($backup->scope);
        $this->assertSame(0, $backup->estimatedSeconds);
        unset($backup, $row, $restore, $changes, $patch);
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /** Scalar and domain refusals discard all prepared text and preserve every original field. */
    public function testScalarDomainRefusalsRetireDraftsAndKeepOriginalFields(): void
    {
        $long = str_repeat("rejected-\0-Ж-", 3000);
        $upload = HilosUpload::create('connection', 'upload', 'target', null, 'file', 'image/example', 10, 'tmp', 1)->toArray();
        $component = HilosStatusComponent::create('component', 1)->toArray();
        $backup = BackupRuntime::create()->toArray();
        foreach ([
            [HilosUpload::class, $upload, [HilosUpload::phase => $long]],
            [HilosUpload::class, $upload, [HilosUpload::errorMessage => $long, HilosUpload::uploadHoldApproved => true]],
            [HilosUpload::class, $upload, [HilosUpload::errorMessage => $long, HilosUpload::uploadHoldId => '']],
            [HilosUpload::class, $upload, [HilosUpload::errorMessage => $long, HilosUpload::uploadHoldDeadlineAt => 1]],
            [HilosUpload::class, $upload, [HilosUpload::errorMessage => $long,
                HilosUpload::uploadHoldId => 'hold', HilosUpload::uploadHoldDeadlineAt => 0]],
            [HilosUpload::class, $upload, [HilosUpload::receivedBytes => 10, HilosUpload::updatedAt => 'wrong type']],
            [HilosStatusComponent::class, $component, [HilosStatusComponent::componentKey => $long]],
            [HilosStatusComponent::class, $component, [HilosStatusComponent::componentKey => 'another-component']],
            [HilosStatusComponent::class, $component, [HilosStatusComponent::expectedInstances => 0]],
            [HilosStatusComponent::class, $component, [HilosStatusComponent::expectedInstances => 2, HilosStatusComponent::liveInstances => -1]],
            [BackupRuntime::class, $backup, [BackupRuntime::currentBackupId => $long, BackupRuntime::startedAt => '2026-02-30T00:00:00Z']],
            [BackupRuntime::class, $backup, [BackupRuntime::currentBackupId => $long, BackupRuntime::running => 1]],
        ] as [$rowClass, $row, $diff]) {
            $arena = new RtRowArena();
            $codec = $rowClass::fromRow($row)->rowCodec();
            $incoming = RtRowValues::fromNative($row, $arena);
            $restore = new RtWorkDriver($codec->restore($incoming->reader()));
            $this->complete($restore);
            $draft = $restore->result();
            $before = $draft->toArray();
            $changes = RtRowValues::fromNative($diff, $arena);
            $patch = new RtWorkDriver($codec->patch($draft, $changes->reader()));
            try {
                $this->complete($patch, function () use ($draft, $before): void {
                    $this->assertSame($before, $draft->toArray());
                });
                $this->fail('The invalid scalar/domain patch must be refused');
            } catch (InvalidFormatException) {
                $this->assertTrue($patch->isCanceled());
            }
            $this->assertSame($before, $draft->toArray());
            $badRow = RtRowValues::fromNative(array_replace($row, $diff), $arena);
            $refused = new RtWorkDriver($codec->restore($badRow->reader()));
            if (!isset($diff[HilosStatusComponent::componentKey]) || $diff[HilosStatusComponent::componentKey] !== 'another-component') {
                try {
                    $this->complete($refused);
                    $this->fail('The invalid scalar/domain row must be refused');
                } catch (InvalidFormatException) {
                    $this->assertTrue($refused->isCanceled());
                }
            }
            unset($draft, $restore, $incoming, $changes, $patch, $badRow, $refused);
            $this->drain($arena);
            $this->assertSame(0, $arena->retention()->physicalBytes);
            foreach (RtRowRole::cases() as $role) {
                $this->assertSame(0, $arena->retention()->bytes($role));
                $this->assertSame(0, $arena->retention()->leases($role));
            }
        }
        foreach ([HilosUpload::uploadHoldId, HilosUpload::uploadHoldDeadlineAt, HilosUpload::uploadHoldApproved] as $required) {
            $arena = new RtRowArena();
            $row = $upload;
            unset($row[$required]);
            $incoming = RtRowValues::fromNative($row, $arena);
            $restore = new RtWorkDriver(HilosUpload::create('connection', 'upload', 'target', null, 'file', 'image/example', 1, 'tmp', 1)
                ->rowCodec()->restore($incoming->reader()));
            try {
                $this->complete($restore);
                $this->fail('Upload hold fields are nullable but never omittable');
            } catch (InvalidFormatException) {
                $this->assertTrue($restore->isCanceled());
            }
            unset($incoming, $restore);
            $this->drain($arena);
            $this->assertSame(0, $arena->retention()->physicalBytes);
        }
    }

    /** Long list elements retain order and duplicates, while scalar widening and moment normalization stay unchanged. */
    public function testStringListRowsRestoreExportAndPatchWithChangingBudgets(): void
    {
        $long = str_repeat("nested-Ж-\0", 16000);
        $list = array_merge(array_fill(0, 90, 'duplicate'), [$long, 'last']);
        $node = HilosClusterNode::create('node', 'Node', 'role', [], null, false, 0.0);
        $rotation = HilosSessionRotation::create('ticket', 'token', [], 0.0);
        $restore = RestoreRuntime::create();
        $cases = [
            [$node, HilosClusterNode::capabilities, HilosClusterNode::name, 'Node', [
                HilosClusterNode::nodeId => ['ignored'], HilosClusterNode::name => 'Changed', HilosClusterNode::lastSeen => 9,
            ]],
            [$rotation, HilosSessionRotation::acceptKeysToDrop, HilosSessionRotation::sessionToken, 'token', [
                HilosSessionRotation::ticket => null, HilosSessionRotation::sessionToken => 'changed', HilosSessionRotation::expiresAtMs => 9,
            ]],
            [$restore, RestoreRuntime::rehydrateProblems, RestoreRuntime::running, false, [
                RestoreRuntime::running => true, RestoreRuntime::startedAt => '2026-11-05T12:00:00+03:00',
            ]],
        ];
        foreach ($cases as [$factory, $listKey, $guard, $original, $changes]) {
            $arena = new RtRowArena();
            $wire = new RtRowArena();
            $row = $factory->toArray();
            $row[$listKey] = $list;
            $incoming = RtRowValues::fromNative($row, $arena);
            $codec = $factory->rowCodec();
            $reader = new RtWorkDriver($codec->restore($incoming->reader()));
            $this->assertGreaterThan(100, $this->complete($reader));
            $state = $reader->result();
            $this->assertSame($row, $state->toArray());
            $encoded = $this->exportRow($state, $wire);
            $this->assertSame($row, $encoded->toNative());
            $changes[$listKey] = ['last', $long, 'last'];
            $diff = RtRowValues::fromNative($changes, $arena);
            $patch = new RtWorkDriver($codec->patch($state, $diff->reader()));
            $this->assertGreaterThan(100, $this->complete($patch, function () use ($state, $guard, $original): void {
                $this->assertSame($original, $state->{$guard});
            }));
            $legacy = $factory::fromRow($row);
            $legacy->applyDiff($changes);
            $this->assertSame($legacy->toArray(), $state->toArray());
            $this->assertSame(['last', $long, 'last'], $state->{$listKey});
            unset($reader, $state, $patch, $incoming, $diff, $encoded, $legacy);
            $this->drain($arena);
            $this->drain($wire);
            $this->assertSame(0, $arena->retention()->physicalBytes);
            $this->assertSame(0, $wire->retention()->physicalBytes);
        }
        $this->assertSame('ticket', $rotation->ticket);
    }

    /** Toasts normalize outer keys without imposing a schema on nested values or their keys. */
    public function testToastCodecKeepsPermissiveNestedValuesAndRetiresCanceledAndRefusedDrafts(): void
    {
        $arena = new RtRowArena();
        $wire = new RtRowArena();
        $long = str_repeat("message-\0-Ж", 6000);
        $row = [
            HilosSessionToastStack::sessionTokenHash => 'session',
            HilosSessionToastStack::toasts => [
                'first' => [
                    HilosSessionToastStack::TOAST_MESSAGE => $long,
                    HilosSessionToastStack::TOAST_EXPIRED_BY => [null, ['arbitrary' => [true, 17, $long]]],
                    $long => ['nested-key' => false],
                ],
                'scalar' => 19,
                'null' => null,
            ],
            HilosSessionToastStack::readingBy => ['tab', 'tab'],
        ];
        $input = RtRowValues::fromNative($row, $arena);
        $codec = HilosSessionToastStack::create('session', [])->rowCodec();
        $reader = new RtWorkDriver($codec->restore($input->reader()));
        $this->assertGreaterThan(100, $this->complete($reader));
        $state = $reader->result();
        $expected = HilosSessionToastStack::fromRow($row)->toArray();
        $this->assertSame($expected, $state->toArray());
        $output = $this->exportRow($state, $wire);
        $this->assertSame($expected, $output->toNative());

        $changes = [HilosSessionToastStack::toasts => ['renumber' => $row[HilosSessionToastStack::toasts]]];
        $diff = RtRowValues::fromNative($changes, $arena);
        $patch = new RtWorkDriver($codec->patch($state, $diff->reader()));
        $this->complete($patch);
        $legacy = HilosSessionToastStack::fromRow($row);
        $legacy->applyDiff($changes);
        $before = $legacy->toArray();
        $this->assertSame($before, $state->toArray());
        $this->drain($arena);

        $bad = RtRowValues::fromNative($changes + [HilosSessionToastStack::readingBy => ['not-a-list' => 'tab']], $arena);
        $refused = new RtWorkDriver($codec->patch($state, $bad->reader()));
        try {
            $this->complete($refused);
            $this->fail('A malformed strict string list must refuse the hidden toast draft');
        } catch (InvalidFormatException) {
            $this->assertTrue($refused->isCanceled());
            $this->assertSame($before, $state->toArray());
        }
        unset($bad, $refused);
        $this->drain($arena);
        $canceled = new RtWorkDriver($codec->restore($input->reader()));
        $canceled->advance(new RtWorkBudget(300, 211));
        $this->assertFalse($canceled->isFinished());
        $canceled->cancel();
        $this->assertTrue($canceled->isCanceled());
        unset($reader, $state, $patch, $input, $diff, $bad, $refused, $canceled, $output, $legacy);
        $this->assertGreaterThan(0, $arena->retention()->pendingReleases);
        $this->assertGreaterThan(1, $this->drain($arena));
        $this->drain($wire);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $wire->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->leases(RtRowRole::Candidate));
    }

    /** Diagnostics keep nullable fields, canonical record order and the two historical omission defaults. */
    public function testProtectedModeNestedReportsAndOmissionDefaultsRemainCompatible(): void
    {
        $arena = new RtRowArena();
        $wire = new RtRowArena();
        $long = str_repeat('node-name-', 7000);
        $row = ProtectedModeRuntime::create()->toArray();
        $row[ProtectedModeRuntime::phase] = ProtectedModeRuntime::PHASE_VERIFYING;
        $row[ProtectedModeRuntime::passHashes] = ['same', $long, 'same'];
        $row[ProtectedModeRuntime::preservedAgentTypes] = [];
        $row[ProtectedModeRuntime::watchdogAlertEmails] = null;
        unset($row[ProtectedModeRuntime::entryMode], $row[ProtectedModeRuntime::watchdogPendingNodeIds]);
        for ($index = 0; $index < 95; ++$index) {
            $row[ProtectedModeRuntime::maintenanceNodes][] = [
                'nodeId' => 'node-' . $index,
                'nodeName' => $index === 51 ? $long : null,
                ProtectedModeRuntime::phase => $index === 0 ? null : ProtectedModeRuntime::PHASE_ACTIVE,
                'quiesced' => null,
                'isLeader' => $index === 0,
                'isInitiator' => false,
                'reportState' => 'missing',
                'ignored' => $index === 51 ? [$long => $long] : [],
            ];
        }
        $input = RtRowValues::fromNative($row, $arena);
        $codec = ProtectedModeRuntime::create()->rowCodec();
        $reader = new RtWorkDriver($codec->restore($input->reader()));
        $this->assertGreaterThan(100, $this->complete($reader));
        $state = $reader->result();
        $expected = ProtectedModeRuntime::fromRow($row)->toArray();
        $this->assertSame($expected, $state->toArray());
        $this->assertSame(ProtectedModeRuntime::ENTRY_MODE_FREEZE, $state->entryMode);
        $this->assertSame([], $state->watchdogPendingNodeIds);
        $this->assertSame([], $state->preservedAgentTypes);
        $this->assertNull($state->watchdogAlertEmails);
        $output = $this->exportRow($state, $wire);
        $this->assertSame($expected, $output->toNative());
        $changes = [ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_INACTIVE];
        $diff = RtRowValues::fromNative($changes, $arena);
        $patch = new RtWorkDriver($codec->patch($state, $diff->reader()));
        $this->complete($patch);
        $this->assertSame(ProtectedModeRuntime::ENTRY_MODE_FREEZE, $state->entryMode, 'A patch does not reconstruct an absent entry mode');
        $before = $state->toArray();

        $badRows = [
            array_replace($row, [ProtectedModeRuntime::watchdogPendingNodeIds => null]),
            array_replace_recursive($row, [ProtectedModeRuntime::maintenanceNodes => [94 => ['reportState' => $long]]]),
            array_replace($row, [ProtectedModeRuntime::entryMode => $long]),
        ];
        $missingNullable = $row;
        unset($missingNullable[ProtectedModeRuntime::maintenanceNodes][94]['nodeName']);
        $badRows[] = $missingNullable;
        foreach ($badRows as $badRow) {
            $bad = RtRowValues::fromNative($badRow, $arena);
            $refused = new RtWorkDriver($codec->patch($state, $bad->reader()));
            try {
                $this->complete($refused);
                $this->fail('An invalid diagnostic report or choice must refuse the whole patch');
            } catch (InvalidFormatException) {
                $this->assertSame($before, $state->toArray());
            }
            unset($bad, $refused);
            $this->drain($arena);
        }
        $explicitNull = $row + [ProtectedModeRuntime::entryMode => null];
        $nullInput = RtRowValues::fromNative($explicitNull, $arena);
        $nullRestore = new RtWorkDriver($codec->restore($nullInput->reader()));
        $this->complete($nullRestore);
        $this->assertNull($nullRestore->result()->entryMode);
        unset($reader, $state, $patch, $input, $diff, $bad, $refused, $output, $nullInput, $nullRestore);
        $this->drain($arena);
        $this->drain($wire);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $wire->retention()->physicalBytes);
    }

    /** Catalog membership, uniqueness and cross-field ordering are validated before publication. */
    public function testMaintenanceCodecValidatesLongKeysAndRelatedFieldsInHiddenDrafts(): void
    {
        $arena = new RtRowArena();
        $wire = new RtRowArena();
        $row = HilosStatusMaintenance::create()->toArray();
        $row[HilosStatusMaintenance::announcementKnown] = true;
        $row[HilosStatusMaintenance::workPublicId] = str_repeat('a', 32);
        $row[HilosStatusMaintenance::periodId] = str_repeat('b', 32);
        $row[HilosStatusMaintenance::title] = str_repeat('maintenance-', 13000);
        $row[HilosStatusMaintenance::startsAt] = 10;
        $row[HilosStatusMaintenance::endsAt] = 11;
        for ($index = 0; $index < 70; ++$index) {
            $row[HilosStatusMaintenance::componentKeys][] = 'component-' . $index . '-' . str_repeat('a', 110);
        }
        $input = RtRowValues::fromNative($row, $arena);
        $codec = HilosStatusMaintenance::create()->rowCodec();
        $reader = new RtWorkDriver($codec->restore($input->reader()));
        $this->assertGreaterThan(100, $this->complete($reader));
        $state = $reader->result();
        $this->assertSame($row, $state->toArray());
        $output = $this->exportRow($state, $wire);
        $this->assertSame($row, $output->toNative());
        // An empty patch copies no changed list: these parts advance validation of the existing keys.
        $empty = RtRowValues::fromNative([], $arena);
        $validation = new RtWorkDriver($codec->patch($state, $empty->reader()));
        $this->assertGreaterThan(100, $this->complete($validation));
        $this->assertSame($row, $state->toArray());
        $duplicateKeys = $row[HilosStatusMaintenance::componentKeys];
        $duplicateKeys[] = $duplicateKeys[69];
        foreach ([
            [HilosStatusMaintenance::active => true, HilosStatusMaintenance::componentKeys => $duplicateKeys],
            [HilosStatusMaintenance::active => true, HilosStatusMaintenance::endsAt => 10],
            [HilosStatusMaintenance::active => true, HilosStatusMaintenance::id => 'another'],
            [HilosStatusMaintenance::active => true, HilosStatusMaintenance::announcementKnown => false],
            [HilosStatusMaintenance::componentKeys => ['bad key']],
        ] as $changes) {
            $diff = RtRowValues::fromNative($changes, $arena);
            $patch = new RtWorkDriver($codec->patch($state, $diff->reader()));
            try {
                $this->complete($patch, function () use ($state): void {
                    $this->assertFalse($state->active);
                });
                $this->fail('Domain validation must refuse the entire maintenance patch');
            } catch (InvalidFormatException) {
                $this->assertTrue($patch->isCanceled());
                $this->assertSame($row, $state->toArray());
            }
        }
        unset($reader, $state, $patch, $input, $diff, $output, $empty, $validation);
        $this->drain($arena);
        $this->drain($wire);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $wire->retention()->physicalBytes);
    }

    /** The immutable snapshot collection stays typed and ordered, and its distinct ids are validated incrementally. */
    public function testModerationCodecKeepsSnapshotIdentityAndNoOpPatchWhileValidatingNestedDrafts(): void
    {
        $arena = new RtRowArena();
        $wire = new RtRowArena();
        $snapshots = new UploadSnapshotCollection(new UploadSnapshotData('first', str_repeat('a', 32), str_repeat('b', ContentHash::LENGTH)));
        $factory = HilosModerationCheck::create('accept', 7, 'kind', 'request', 'owner', null, null, 11, 22, uploadSnapshots: $snapshots);
        $this->assertSame($snapshots, $factory->uploadSnapshots);
        $row = $factory->toArray();
        $row[HilosModerationCheck::text] = str_repeat('moderation-', 16000);
        for ($index = 0; $index < 65; ++$index) {
            $row[HilosModerationCheck::uploadSnapshots][] = [
                UploadSnapshotData::clientUploadId => str_pad('upload-' . $index, UploadFrame::MAX_ID_LENGTH, 'x'),
                UploadSnapshotData::tmpIndex => str_repeat('a', 32),
                UploadSnapshotData::contentHash => str_repeat('b', ContentHash::LENGTH),
                'ignored' => str_repeat('large-unknown', 200),
            ];
        }
        $input = RtRowValues::fromNative($row, $arena);
        $codec = $factory->rowCodec();
        $reader = new RtWorkDriver($codec->restore($input->reader()));
        $this->assertGreaterThan(100, $this->complete($reader));
        $state = $reader->result();
        $expected = HilosModerationCheck::fromRow($row)->toArray();
        $this->assertSame($expected, $state->toArray());
        $this->assertSame('owner', $state->verdictTo);
        $this->assertSame('accept|kind', $state->getId());
        $this->assertInstanceOf(UploadSnapshotCollection::class, $state->uploadSnapshots);
        $this->assertCount(66, $state->uploadSnapshots);
        $this->assertInstanceOf(UploadSnapshotData::class, iterator_to_array($state->uploadSnapshots)[65]);
        $output = $this->exportRow($state, $wire);
        $this->assertSame($expected, $output->toNative());
        $diff = RtRowValues::fromNative([HilosModerationCheck::uploadSnapshots => [], HilosModerationCheck::uploadHoldId => ''], $arena);
        $patch = new RtWorkDriver($codec->patch($state, $diff->reader()));
        $this->complete($patch);
        $this->assertSame($expected, $state->toArray());

        $duplicate = $row;
        $duplicate[HilosModerationCheck::uploadSnapshots][] = $row[HilosModerationCheck::uploadSnapshots][65];
        $invalidRecord = $row;
        $invalidRecord[HilosModerationCheck::uploadSnapshots][65][UploadSnapshotData::tmpIndex] = 'not-a-temp-index';
        foreach ([$duplicate, $invalidRecord, array_replace($row, [HilosModerationCheck::uploadHoldId => null]),
            array_replace($row, [HilosModerationCheck::uploadHoldId => ''])] as $badRow) {
            $bad = RtRowValues::fromNative($badRow, $arena);
            $refused = new RtWorkDriver($codec->restore($bad->reader()));
            try {
                $this->complete($refused);
                $this->fail('A duplicate, malformed or unheld snapshot list must refuse the row');
            } catch (InvalidFormatException) {
                $this->assertTrue($refused->isCanceled());
            }
            unset($bad, $refused);
            $this->drain($arena);
        }
        unset($reader, $state, $patch, $input, $diff, $bad, $refused, $output);
        $this->drain($arena);
        $this->drain($wire);
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $wire->retention()->physicalBytes);
        $this->assertSame(0, $arena->retention()->leases(RtRowRole::Candidate));
    }

    /**
     * Where an RT pass drives the arena, a synchronous door retires what it released itself and
     * none of the backlog it found; the backlog wakes the pass, which advances it on its retirement
     * share, in portions. Without a pass the door retires the backlog too: nothing else would.
     */
    public function testADoorRetiresItsOwnReleasesAndThePassRetiresTheBacklogInPortions(): void
    {
        $arena = new RtRowArena();
        $wakes = 0;
        $wake = static function () use (&$wakes): void {
            ++$wakes;
        };
        $arena->bindPassWake($wake);
        $backlog = RtRowValues::fromNative(array_fill(0, 400, str_repeat('backlog ', 40)), $arena);
        $this->assertFalse($arena->hasPendingRetirement(), 'The door retired what its own encoding released');
        $this->assertSame(0, $wakes, 'A door\'s own releases wake nobody');
        $backlog->release();
        $pending = $arena->retention()->pendingReleases;
        $this->assertGreaterThan(0, $pending);
        $this->assertSame(1, $wakes);

        $value = RtRowValues::fromNative(['door' => 'own'], $arena);
        $this->assertSame($pending, $arena->retention()->pendingReleases, 'The door left the backlog alone');

        $pass = new RtWorkScheduler(16, 512);
        $passes = 0;
        while ($arena->hasPendingRetirement()) {
            $pass->beginPass();
            $arena->advanceRetirement($pass->budget(RtWorkLane::Retire));
            $this->assertLessThan(100000, ++$passes);
        }
        $this->assertGreaterThan(1, $passes, 'The backlog retires in portions of the share');

        $arena->unbindPassWake($wake);
        $value->release();
        $this->assertTrue($arena->hasPendingRetirement());
        $last = RtRowValues::fromNative(['door' => 'last'], $arena);
        $this->assertFalse($arena->hasPendingRetirement(), 'Without a pass the outermost door retires the backlog too');
        $last->release();
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /**
     * A burst of releases beyond the shared queue's bound is retired down to the bound over calls,
     * each a bounded portion, and the rest stays with the pass's share. Inside a door the queue in
     * use is the door's own, so there it retires nothing.
     */
    public function testExcessRetirementTakesTheSharedQueueDownToItsBoundOutsideADoorOnly(): void
    {
        $arena = new RtRowArena();
        $arena->bindPassWake(static function (): void {
        });
        $burst = [];
        for ($value = 0; $value < RtRowArena::SHARED_BACKLOG_RELEASES + 256; $value++) {
            $burst[] = RtRowValues::fromNative(['value' => $value], $arena);
        }
        foreach ($burst as $values) {
            $values->release();
        }
        $burst = [];
        $pending = $arena->retention()->pendingReleases;
        $this->assertGreaterThanOrEqual(RtRowArena::SHARED_BACKLOG_RELEASES + 256, $pending);

        $arena->openDoor();
        $arena->retireExcess();
        $arena->closeDoor();
        $this->assertSame($pending, $arena->retention()->pendingReleases, 'Inside a door the shared queue is left alone');

        for ($calls = 0; $arena->retention()->pendingReleases > RtRowArena::SHARED_BACKLOG_RELEASES; $calls++) {
            $this->assertLessThan(64, $calls, 'The rounds bring the queue down to its bound');
            $pages = $arena->retention()->pages;
            $arena->retireExcess();
            $retired = $pages - $arena->retention()->pages;
            $this->assertGreaterThan(0, $retired, 'Each call retires a portion');
            $this->assertLessThanOrEqual(RtRowArena::EXCESS_RETIREMENT_STEPS, $retired, 'A call spends no more than its steps');
        }
        $this->assertLessThanOrEqual(RtRowArena::SHARED_BACKLOG_RELEASES, $arena->retention()->pendingReleases);
        $this->assertTrue($arena->hasPendingRetirement(), 'The rest stays with the share');
        $this->drain($arena);
        $this->assertSame(0, $arena->retention()->physicalBytes);
    }

    /**
     * A few large values stay far under the count bound and still hold more than the volume bound:
     * they are retired down to it over calls, each spending no more than its bytes, and the rest
     * stays with the share.
     */
    public function testLargeValuesUnderTheCountBoundAreRetiredBeyondTheVolumeBoundAPortionACall(): void
    {
        $arena = new RtRowArena();
        $arena->bindPassWake(static function (): void {
        });
        $large = [];
        for ($value = 0; $value < 12; $value++) {
            $large[] = RtRowValues::fromNative(['blob' => str_repeat('x', 1048576)], $arena);
        }
        foreach ($large as $values) {
            $values->release();
        }
        $large = [];
        $volume = static fn (RtRowRetention $retention): int => $retention->queuedBytes + $retention->retiredBytes;
        $this->assertLessThan(RtRowArena::SHARED_BACKLOG_RELEASES, $arena->retention()->pendingReleases);
        $this->assertGreaterThan(2 * RtRowArena::SHARED_BACKLOG_BYTES, $volume($arena->retention()));

        for ($calls = 0; $volume($arena->retention()) > RtRowArena::SHARED_BACKLOG_BYTES; $calls++) {
            $this->assertLessThan(64, $calls, 'The rounds bring the queue down to its volume bound');
            $before = $arena->retention();
            $arena->retireExcess();
            $after = $arena->retention();
            $retired = $before->pages - $after->pages;
            $this->assertGreaterThan(0, $retired, 'Each call retires a portion');
            // Payload a call charges, one leaf begun by the call before it, and the pages it unlinked.
            $this->assertLessThanOrEqual(
                RtRowArena::EXCESS_RETIREMENT_BYTES + RtRowPage::LEAF_BYTES + $retired * RtRowPage::OVERHEAD_BYTES,
                $volume($before) - $volume($after),
                'A call spends no more than its bytes',
            );
        }
        $this->assertGreaterThan(1, $calls, 'The excess takes more than one call');
        $this->assertTrue($arena->hasPendingRetirement(), 'The rest stays with the share');
        while ($arena->hasPendingRetirement()) {
            $arena->advanceRetirement(new RtWorkBudget());
        }
        $this->assertSame(0, $arena->retention()->physicalBytes);
        $this->assertSame(0, $volume($arena->retention()));
    }

    /**
     * A write's carrier references the row's own values instead of copying them: a long list is
     * the same root in every carrier, so carrying costs the row's fields, not its bytes. A baseline
     * mark of a row that stands in no collection carries nothing at all.
     */
    public function testCarrierReferencesTheRowsValuesAndIsBuiltOnlyForAHolder(): void
    {
        $arena = RtRowArena::process();
        $row = $this->row();
        for ($index = 0; $index < 60; ++$index) {
            $row[BackupHistory::connections][] = [
                BackupConnectionMeta::index => $index,
                BackupConnectionMeta::database => 'db-' . $index,
                BackupConnectionMeta::migrationIndex => null,
            ];
        }
        $history = BackupHistory::fromRow($row);
        $first = $history->carriedRow();
        $pages = $arena->retention()->pages;
        $second = $history->carriedRow();
        $this->assertInstanceOf(RtRowValues::class, $first);
        $this->assertInstanceOf(RtRowValues::class, $second);
        $this->assertSame(
            $first->field(BackupHistory::connections)?->rootIn($arena),
            $second->field(BackupHistory::connections)?->rootIn($arena),
        );
        $this->assertLessThan(100, $arena->retention()->pages - $pages);

        $transient = $arena->retention()->bytes(RtRowRole::Transient);
        $history->markRtSyncBaseline();
        $this->assertSame($transient, $arena->retention()->bytes(RtRowRole::Transient));
    }

    /**
     * A level whose transaction is gone keeps neither undo nor announcement, so a write made under
     * it stands in the live rows; the committed cut holds it as well - with the parent level that
     * still commits, or at once when no level is left.
     */
    public function testWriteUnderAFailedLevelStandsInTheCutAsInTheLiveRows(): void
    {
        $source = $this->mounted();
        Database::transactionStart();
        try {
            Database::runtimeTransactionLevel()?->markFailed();
            $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'written after the loss'));
        } finally {
            Database::transactionRollback();
        }
        $this->assertArrayHasKey('n1', $this->cutNotes($source->notes));

        Database::transactionStartNestable();
        Database::transactionStartNestable();
        try {
            Database::runtimeTransactionLevel()?->markFailed();
            $source->notes->add(HilosProbeNote::create('n2', 'node-a', 'kept by the parent'));
            Database::transactionRollback();
            $this->assertArrayNotHasKey('n2', $this->cutNotes($source->notes));
        } finally {
            Database::transactionCommit();
        }
        $this->assertSame($this->notesOf($source->notes), $this->cutNotes($source->notes));
    }

    /**
     * Many small rows of one cut, the source's create/update/delete after it, and one switch: until
     * publication every read is the last whole version; after it membership, values, the selector,
     * kept identities and the announcements agree with the source.
     */
    public function testManySmallRowsAndCutChangesPublishInOneSwitch(): void
    {
        $source = $this->mounted();
        for ($index = 0; $index < 40; ++$index) {
            $source->notes->add(HilosProbeNote::create('n' . $index, $index % 2 === 0 ? 'node-a' : 'node-b', 'text ' . $index));
        }
        $cut = RtSnapshot::capture(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole());
        $source->notes->add(HilosProbeNote::create('n40', 'node-a', 'created after the cut'));
        $deltas = [RtOrdinaryAdmission::operation(TableMutationType::Create, ResumableSnapshotRtContext::NOTES, null, $source->notes['n40'])];
        $updated = $source->notes['n1'];
        $before = $updated->draftRow();
        $updated->text = 'updated after the cut';
        $updated->markRtSyncBaseline();
        $deltas[] = RtOrdinaryAdmission::operation(TableMutationType::Update, ResumableSnapshotRtContext::NOTES, $before, $updated);
        $deltas[] = RtOrdinaryAdmission::operation(TableMutationType::Delete, ResumableSnapshotRtContext::NOTES, $source->notes['n2'], null);
        $source->notes->remove('n2');
        $portions = $this->portions($cut);
        $this->assertGreaterThan(3, count($portions));
        $expected = $this->notesOf($source->notes);
        unset($before, $updated);

        $receiver = $this->mounted();
        foreach (['n0' => 'old 0', 'n2' => 'old 2', 'n5' => 'old 5', 'gone' => 'not in the cut'] as $id => $text) {
            $receiver->notes->add(HilosProbeNote::create($id, 'node-b', $text));
        }
        $kept = $receiver->notes['n5'];
        $whole = $this->notesOf($receiver->notes);
        $announced = $this->announcements();
        $membership = RtMembershipVersion::current();
        $epoch = $receiver->notes->snapshotEpoch();

        $import = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole(), $cut->revision());
        foreach ($portions as $portion) {
            $import->append($portion);
        }
        foreach ($deltas as $delta) {
            $import->appendDelta($delta);
        }
        $import->finishInput();
        $this->assertGreaterThan(10, $this->drive($import, function () use ($receiver, $whole, $announced): void {
            $this->assertSame($whole, $this->notesOf($receiver->notes), 'Until publication the last whole version is read');
            $this->assertSame([], $announced->changes);
        }));
        $this->assertSame($whole, $this->notesOf($receiver->notes));
        $import->publish();

        $this->assertSame($expected, $this->notesOf($receiver->notes));
        $this->assertSame($kept, $receiver->notes['n5'], 'A row the snapshot updates keeps its identity');
        $this->assertSame('text 5', $kept->text);
        $this->assertSame($expected, $this->cutNotes($receiver->notes));
        $this->assertSame($epoch + 1, $receiver->notes->snapshotEpoch());
        $this->assertGreaterThan($membership, RtMembershipVersion::current());
        $this->assertSame([], $announced->changes, 'Announcements follow publication in portions');
        $this->drive($import);
        $this->assertTrue($receiver->notes->isSettled());
        $deleted = array_values(array_filter($announced->changes, static fn (array $change): bool => $change[0] === TableMutationType::Delete));
        $this->assertSame([[TableMutationType::Delete, 'n2'], [TableMutationType::Delete, 'gone']], array_map(
            static fn (array $change): array => [$change[0], $change[1]],
            $deleted,
        ));
        $this->assertCount(42, $announced->changes);
        foreach ($announced->changes as [, , $provenance]) {
            $this->assertSame(SourceChangeProvenance::AppliedRemote, $provenance);
        }
        $this->assertSame('text 5', $kept->text, 'Settling keeps the published values');

        $receiver->notes->remove('n7');
        $kept->text = 'a later local write wins';
        $kept->markRtSyncBaseline();
        $this->assertSame('a later local write wins', $this->cutNotes($receiver->notes)['n5'][HilosProbeNote::text]);
        $this->assertArrayNotHasKey('n7', $this->cutNotes($receiver->notes));

        $refused = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole(), $cut->revision());
        $refused->append(new RtSnapshotPortion([], [], RtSnapshotImport::DEFAULT_RESERVE_BYTES));
        try {
            $refused->append(new RtSnapshotPortion([], [], 1));
            $this->fail('A portion beyond the reserve is refused');
        } catch (FrameworkLogicException $beyond) {
            $this->assertStringContainsString('reserve', $beyond->getMessage());
        }
    }

    /** One history row with a long list and a long value crosses many passes on both sides. */
    public function testOneLargeBackupHistoryStagesInBoundedPassesAndKeepsItsHandle(): void
    {
        $row = $this->row();
        $longDatabase = str_repeat("database-\0-Ж-", 4000);
        for ($index = 0; $index < 90; ++$index) {
            $row[BackupHistory::connections]['source-' . $index] = [
                BackupConnectionMeta::index => (string)$index,
                BackupConnectionMeta::database => $index === 45 ? $longDatabase : 'db-' . $index,
                BackupConnectionMeta::migrationIndex => null,
            ];
        }
        $source = $this->mounted();
        $source->histories->add(BackupHistory::fromRow($row));
        $cut = RtSnapshot::capture(ResumableSnapshotRtContext::HISTORIES, RtSnapshotScope::whole());
        $expected = $source->histories->toArray();
        $portions = $this->portions($cut);

        $receiver = $this->mounted();
        $older = $this->row();
        $older[BackupHistory::sizeBytes] = 1;
        $receiver->histories->add(BackupHistory::fromRow($older));
        $handle = $receiver->histories[$row[BackupHistory::id]];
        $import = RtSnapshot::stage(ResumableSnapshotRtContext::HISTORIES, RtSnapshotScope::whole(), $cut->revision());
        foreach ($portions as $portion) {
            $import->append($portion);
        }
        $import->finishInput();
        $this->assertGreaterThan(100, $this->drive($import, function () use ($handle): void {
            $this->assertSame(1, $handle->sizeBytes);
            $this->assertSame([], $handle->connections);
        }));
        $import->publish();
        $this->assertSame($handle, $receiver->histories[$row[BackupHistory::id]]);
        $this->assertSame($expected, $receiver->histories->toArray());
        $this->assertSame($longDatabase, $handle->connections[45]->database);
        $this->drive($import);
        $this->assertSame($expected, $receiver->histories->toArray());
    }

    /**
     * Reads between passes see the old version; a set-scoped generation leaves the other owners'
     * rows and the live writes made meanwhile, repairs the view cache and keeps kept handles and
     * the standalone item the same objects.
     */
    public function testScopedGenerationKeepsOtherOwnersLiveWritesCacheAndIdentities(): void
    {
        $source = $this->mounted();
        foreach ([['b1', 'node-b', 'b one new'], ['b3', 'node-b', 'b three new'], ['a1', 'node-a', 'not ours to send']] as [$id, $node, $text]) {
            $source->notes->add(HilosProbeNote::create($id, $node, $text));
        }
        $source->item->text = 'item new';
        $source->item->markRtSyncBaseline();
        $cut = RtSnapshot::capture(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::sets(['node-b']));
        $itemCut = RtSnapshot::capture(ResumableSnapshotRtContext::ITEM, RtSnapshotScope::whole());

        $receiver = $this->mounted();
        $rows = [['a1', 'node-a', 'a one'], ['b1', 'node-b', 'b one'], ['b2', 'node-b', 'b two'], ['a2', 'node-a', 'a two']];
        foreach ($rows as [$id, $node, $text]) {
            $receiver->notes->add(HilosProbeNote::create($id, $node, $text));
        }
        $view = $receiver->getRtCollection(ResumableSnapshotRtContext::NOTES);
        $wrapper = $view['b1'];
        $this->assertNotNull($view['b2']);
        $kept = $receiver->notes['b1'];
        $item = $receiver->item;
        $import = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::sets(['node-b']), $cut->revision());
        foreach ($this->portions($cut) as $portion) {
            $import->append($portion);
        }
        $import->finishInput();
        $this->drive($import, function () use ($receiver, $view, $wrapper): void {
            $this->assertSame('b one', $receiver->notes['b1']->text);
            $this->assertSame($wrapper, $view['b1']);
            $this->assertNotNull($view['b2']);
        });
        $beside = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::sets(['node-a']), $cut->revision());
        $beside->finishInput();
        $this->drive($beside);
        $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($beside));
        $receiver->notes['a1']->text = 'a one written meanwhile';
        $receiver->notes['a1']->markRtSyncBaseline();
        $receiver->notes->add(HilosProbeNote::create('a3', 'node-a', 'a three written meanwhile'));
        $receiver->notes->remove('a2');
        $this->drive($import);
        $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($import));
        $import->publish();

        $this->assertSame(RtSnapshotStatus::Canceled, $this->statusOf($beside), 'A generation built beside a published one retries');
        $notes = $this->notesOf($receiver->notes);
        $this->assertSame(['a1', 'a3', 'b1', 'b3'], array_keys($notes));
        $this->assertSame('a one written meanwhile', $notes['a1'][HilosProbeNote::text]);
        $this->assertSame('b one new', $notes['b1'][HilosProbeNote::text]);
        $this->assertSame($notes, $this->cutNotes($receiver->notes));
        $this->assertSame($kept, $receiver->notes['b1']);
        $this->assertNull($view['b2'], 'A swept row is not revived by a cached wrapper');
        $this->assertSame($kept, $view['b1']->getState());
        $this->assertNull(RtStaleness::staleSince(ResumableSnapshotRtContext::NOTES));

        $itemImport = RtSnapshot::stage(ResumableSnapshotRtContext::ITEM, RtSnapshotScope::whole(), $itemCut->revision());
        foreach ($this->portions($itemCut) as $portion) {
            $itemImport->append($portion);
        }
        $itemImport->finishInput();
        $this->drive($itemImport, function () use ($item): void {
            $this->assertSame('item text', $item->text);
        });
        $itemImport->publish();
        $this->assertSame($item, $receiver->item);
        $this->assertSame('item new', $item->text);
        $empty = RtSnapshot::stage(ResumableSnapshotRtContext::ITEM, RtSnapshotScope::whole(), 0);
        $empty->finishInput();
        $this->drive($empty);
        $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($empty));
        $empty->publish();
        $this->assertSame('item new', $item->text, 'An empty standalone snapshot leaves the item as it was');
    }

    /**
     * The source's stale marks are prepared beside the candidate - the live marks less the swept
     * rows' - and switched in at publication with the rows; marks that changed since they were
     * prepared hold publication back until they are prepared again. The caller's bookkeeping is
     * handed the generation's own maps of arrived and swept rows, and the announcements are read
     * off those maps afterwards in portions.
     */
    public function testPublicationSwitchesInPreparedStaleMarksAndHandsOverTheGenerationsMaps(): void
    {
        RtStaleness::reset();
        try {
            $source = $this->mounted();
            for ($index = 0; $index < 6; ++$index) {
                $source->notes->add(HilosProbeNote::create('n' . $index, 'node-b', 'text ' . $index));
            }
            $cut = RtSnapshot::capture(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole());
            $receiver = $this->mounted();
            foreach (['n1' => 'old 1', 'gone' => 'not in the cut', 'left' => 'not in it either'] as $id => $text) {
                $receiver->notes->add(HilosProbeNote::create($id, 'node-b', $text));
            }
            RtStaleness::mark(ResumableSnapshotRtContext::NOTES, ['n1', 'gone'], 10.0);
            $announced = $this->announcements();
            $handed = [];
            $import = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole(), $cut->revision());
            $import->whenPublished(static function (array $arrived, array $swept) use (&$handed): void {
                $types = array_values(array_unique(array_map(get_debug_type(...), [...$arrived, ...$swept])));
                $handed[] = [array_keys($arrived), array_keys($swept), $types];
            });
            foreach ($this->portions($cut) as $portion) {
                $import->append($portion);
            }
            $import->finishInput();
            $this->drive($import);
            $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($import));

            RtStaleness::mark(ResumableSnapshotRtContext::NOTES, ['left'], 20.0);
            $import->publish();
            $this->assertSame([], $handed, 'Marks changed since they were prepared: nothing is published');
            $this->assertSame(['gone', 'left', 'n1'], array_keys($this->notesOf($receiver->notes)), 'The last whole version is still read');
            $this->assertSame(['n1' => 10.0, 'gone' => 10.0, 'left' => 20.0], RtStaleness::staleRows(ResumableSnapshotRtContext::NOTES),
                'The live marks stand as they are');

            $this->drive($import);
            $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($import));
            $import->publish();
            $this->assertSame([[['n1', 'n0', 'n2', 'n3', 'n4', 'n5'], ['gone', 'left'], [RtRowValues::class]]], $handed,
                'The kept row first, as the candidate placed them');
            $this->assertSame(['n1' => 10.0], RtStaleness::staleRows(ResumableSnapshotRtContext::NOTES),
                'A kept row keeps its mark; the swept rows\' marks go with them');
            $this->assertSame([], $announced->changes, 'Announcements follow publication in portions');

            $this->drive($import);
            $this->assertSame([[TableMutationType::Delete, 'gone'], [TableMutationType::Delete, 'left']], array_map(
                static fn (array $change): array => [$change[0], $change[1]],
                array_slice($announced->changes, 0, 2),
            ));
            $this->assertCount(8, $announced->changes);
        } finally {
            RtStaleness::reset();
        }
    }

    /** A row its validator refuses refuses the generation; nothing of it is visible, and it retires. */
    public function testValidatorRefusalPublishesNothingAndRetiresTheGeneration(): void
    {
        $receiver = $this->mounted();
        $receiver->notes->add(HilosProbeNote::create('n1', 'node-a', 'stays'));
        $arena = RtRowArena::process();
        $this->drain($arena);
        $candidate = $arena->retention()->bytes(RtRowRole::Candidate);
        $valid = RtRowValues::fromNative([HilosProbeNote::noteId => 'n2', HilosProbeNote::nodeId => 'node-a', HilosProbeNote::text => 'fine']);
        $invalid = RtRowValues::fromNative([HilosProbeNote::noteId => 'n3', HilosProbeNote::nodeId => 7, HilosProbeNote::text => 'refused']);
        $import = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::whole(), 1);
        $import->append(new RtSnapshotPortion(['n2', 'n3'], [$valid, $invalid], 64));
        $import->finishInput();
        unset($valid, $invalid);
        $this->drive($import);
        $this->assertSame(RtSnapshotStatus::Refused, $this->statusOf($import));
        $import->append(new RtSnapshotPortion(['n4'], [RtRowValues::fromNative([])], 1));
        $this->assertSame(RtSnapshotStatus::Refused, $import->advance(new RtWorkBudget()));
        $this->assertSame(
            ['n1' => [HilosProbeNote::noteId => 'n1', HilosProbeNote::nodeId => 'node-a', HilosProbeNote::text => 'stays']],
            $this->notesOf($receiver->notes),
        );
        try {
            $import->publish();
            $this->fail('A refused generation is not published');
        } catch (FrameworkLogicException $refused) {
            $this->assertStringContainsString('ready', $refused->getMessage());
        }
        unset($import);
        $this->drain($arena);
        $this->assertSame($candidate, $arena->retention()->bytes(RtRowRole::Candidate));
    }

    /**
     * A history outgrowing its allowance cancels the generation alone: the ordinary writes stand,
     * nothing goes stale, held memory retires with its last reference in portions, and a retry
     * from a new cut publishes; kept handles then settle in portions.
     */
    public function testHistoryOverflowCancelsOnlyTheGenerationAndStagedMemoryRetires(): void
    {
        $source = $this->mounted();
        for ($index = 0; $index < 6; ++$index) {
            $source->notes->add(HilosProbeNote::create('b' . $index, 'node-b', 'owner ' . $index));
        }
        $cut = RtSnapshot::capture(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::sets(['node-b']));
        $portions = $this->portions($cut);

        $receiver = $this->mounted();
        foreach (['b0', 'b1', 'b2'] as $id) {
            $receiver->notes->add(HilosProbeNote::create($id, 'node-b', 'replica of ' . $id));
        }
        $arena = RtRowArena::process();
        $this->drain($arena);
        $candidate = $arena->retention()->bytes(RtRowRole::Candidate);
        $import = new RtSnapshotImport(
            ResumableSnapshotRtContext::NOTES,
            RtSnapshotScope::sets(['node-b']),
            $cut->revision(),
            historyLimit: 3 * (RtRowPage::OVERHEAD_BYTES + 256),
        );
        foreach ($portions as $portion) {
            $import->append($portion);
        }
        $import->finishInput();
        $this->drive($import);
        $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($import));
        $this->assertGreaterThan($candidate, $arena->retention()->bytes(RtRowRole::Candidate));
        for ($index = 0; $index < 8; ++$index) {
            $receiver->notes->add(HilosProbeNote::create('a' . $index, 'node-a', str_repeat('ordinary ', 20)));
        }
        $this->assertSame(RtSnapshotStatus::Canceled, $import->advance(new RtWorkBudget()));
        $this->assertCount(11, $receiver->notes, 'Ordinary writes stand');
        $this->assertSame('replica of b0', $receiver->notes['b0']->text);
        $this->assertNull(RtStaleness::staleSince(ResumableSnapshotRtContext::NOTES), 'A canceled generation leaves the replica fresh');
        $late = RtOrdinaryAdmission::operation(TableMutationType::Delete, ResumableSnapshotRtContext::NOTES, $receiver->notes['b0'], null);
        $import->appendDelta($late);
        unset($import, $late);
        $this->assertGreaterThan(1, $this->drain($arena));
        $this->assertSame($candidate, $arena->retention()->bytes(RtRowRole::Candidate));

        $retry = RtSnapshot::stage(ResumableSnapshotRtContext::NOTES, RtSnapshotScope::sets(['node-b']), $cut->revision());
        foreach ($portions as $portion) {
            $retry->append($portion);
        }
        $retry->finishInput();
        $this->drive($retry);
        $this->assertSame(RtSnapshotStatus::Ready, $this->statusOf($retry));
        $kept = $receiver->notes['b1'];
        $retry->publish();
        $this->assertSame($kept, $receiver->notes['b1']);
        $this->assertSame('owner 1', $kept->text);
        $this->assertCount(14, $receiver->notes);
        $this->assertFalse($receiver->notes->isSettled());
        $passes = 0;
        while (!$receiver->notes->isSettled()) {
            $budget = new RtWorkBudget(1, 0);
            $this->assertSame(RtSnapshotStatus::Published, $retry->advance($budget));
            $this->assertSame(1, $budget->usedSteps());
            $this->assertLessThan(100, ++$passes);
        }
        $this->assertGreaterThan(3, $passes);
        unset($retry, $portions, $cut);
        $this->drain($arena);
        $this->assertSame($candidate, $arena->retention()->bytes(RtRowRole::Candidate));
        $this->assertSame('owner 1', $kept->text);
    }

    /**
     * @return ResumableSnapshotRtContext Fresh runtime of this process, with names bound
     */
    private function mounted(): ResumableSnapshotRtContext
    {
        $context = new ResumableSnapshotRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context;
    }

    /** @return ResumableSnapshotAnnouncements Subscriber recording what is announced from now on */
    private function announcements(): ResumableSnapshotAnnouncements
    {
        $subscriber = new ResumableSnapshotAnnouncements();
        SourceChangeBus::subscribe($subscriber);

        return $subscriber;
    }

    /**
     * @param RtSnapshotExport $export Read of one cut
     * @return list<RtSnapshotPortion> Its portions, read under changing small allowances
     */
    private function portions(RtSnapshotExport $export): array
    {
        $portions = [];
        $passes = 0;
        while (!$export->isFinished()) {
            $portion = $export->advance(new RtWorkBudget([2, 5, 13][$passes % 3], [40, 300, 2000][$passes % 3]));
            if ($portion !== null) {
                $portions[] = $portion;
            }
            $this->assertLessThan(200000, ++$passes, 'The read must keep progressing');
        }

        return $portions;
    }

    /**
     * Advances a generation under changing tiny allowances while it builds, and after publication
     * until a pass finds nothing to do.
     *
     * @param RtSnapshotImport $import Generation under test
     * @param ?callable(): void $between Observe the live source between building passes
     * @return int Passes taken
     */
    private function drive(RtSnapshotImport $import, ?callable $between = null): int
    {
        $passes = 0;
        do {
            $steps = [1, 7, 29][$passes % 3];
            $bytes = [3, 71, 509][$passes % 3];
            $budget = new RtWorkBudget($steps, $bytes);
            $status = $import->advance($budget);
            $this->assertLessThanOrEqual($steps, $budget->usedSteps());
            $this->assertLessThanOrEqual($bytes, $budget->usedBytes());
            if ($status === RtSnapshotStatus::Building && $between !== null) {
                $between();
            }
            $this->assertLessThan(200000, ++$passes, 'A generation must keep progressing');
        } while ($status === RtSnapshotStatus::Building || ($status === RtSnapshotStatus::Published && $budget->usedSteps() > 0));

        return $passes;
    }

    /**
     * @param RtSnapshotImport $import Generation to ask
     * @return RtSnapshotStatus Where it stands, asked by a pass that may take no step
     */
    private function statusOf(RtSnapshotImport $import): RtSnapshotStatus
    {
        return $import->advance(new RtWorkBudget(0, 0));
    }

    /**
     * @param RtStates<RtState> $collection Collection to read
     * @return array<string, array<string, mixed>> Its rows by id, sorted by id
     */
    private function notesOf(RtStates $collection): array
    {
        $rows = $collection->toArray();
        ksort($rows);

        return $rows;
    }

    /**
     * @param RtStates<RtState> $collection Collection whose committed selector to read
     * @return array<string, mixed> Rows of its current cut by id, sorted by id
     */
    private function cutNotes(RtStates $collection): array
    {
        $rows = $this->cutRows($collection->versionCut());
        ksort($rows);

        return $rows;
    }

    /**
     * @param RtState $state Prepared application row
     * @param RtRowArena $arena Destination owner
     * @return RtRowValues Wire value prepared across changing small budgets
     */
    private function exportRow(RtState $state, RtRowArena $arena): RtRowValues
    {
        $writer = new RtRowWriter($arena, RtRowRole::Wire);
        $export = new RtWorkDriver((static function () use ($state, $writer): Generator {
            yield from $state->rowCodec()->export($state, $writer);

            return yield from $writer->finish();
        })());
        $this->complete($export);

        return $export->result();
    }

    /**
     * Real codec output is frozen before the mutable draft is changed again.
     * Native arrays here are explicit test ingress, outside store preparation.
     *
     * @param RtRowArena $arena Isolated storage owner
     * @param string $id Example row key
     * @param string $text Example value
     * @param int $incarnation Row lifetime token
     * @param string $set Example set key
     * @param ?RtRowValues $baseline Independent baseline, or the exported values
     * @return RtPreparedRow Fully prepared immutable codec record
     */
    private function preparedNote(
        RtRowArena $arena,
        string $id,
        string $text,
        int $incarnation = 1,
        string $set = 'node',
        ?RtRowValues $baseline = null,
    ): RtPreparedRow {
        $input = RtRowValues::fromNative([
            HilosProbeNote::noteId => $id,
            HilosProbeNote::nodeId => $set,
            HilosProbeNote::text => $text,
        ], $arena);
        $codec = new HilosProbeNoteRowCodec();
        $restore = new RtWorkDriver($codec->restore($input->reader()));
        $this->complete($restore);
        $draft = $restore->result();
        $writer = new RtRowWriter($arena);
        $export = new RtWorkDriver((static function () use ($codec, $draft, $writer): Generator {
            yield from $codec->export($draft, $writer);

            return yield from $writer->finish();
        })());
        $this->complete($export);
        $values = $export->result();
        $idField = new RtWorkDriver($values->reader()->field(HilosProbeNote::noteId));
        $setField = new RtWorkDriver($values->reader()->field(HilosProbeNote::nodeId));
        $this->complete($idField);
        $this->complete($setField);
        $identity = new RtWorkDriver(RtPreparedIdentity::prepare($idField->result(), $setField->result()));
        $this->complete($identity);
        $record = new RtWorkDriver(RtPreparedRow::prepare($identity->result(), $values, $baseline ?? $values, $incarnation));
        $this->complete($record);
        $draft->text = 'mutable draft changed after preparation';

        return $record->result();
    }

    /**
     * @param RtVersionStore $store Selector to commit into, outside any transaction
     * @param TableMutationType $type Operation
     * @param RtPreparedRow $row Prepared record
     */
    private function commitVersion(RtVersionStore $store, TableMutationType $type, RtPreparedRow $row): void
    {
        $batch = $store->begin();
        $this->complete(new RtWorkDriver($batch->change($type, $row)));
        $store->apply($batch);
    }

    /**
     * @param RtVersionCut $cut Committed cut
     * @return ?string Operation of the first change its prefix records, null for none
     */
    private function firstChange(RtVersionCut $cut): ?string
    {
        $cursor = $cut->changes();
        $driver = new RtWorkDriver($cursor->advance());
        $this->complete($driver);
        $change = $driver->result();
        $type = $change?->rootIn($change->reader()->arena())->first->scalar;
        unset($change);
        $cursor->cancel();
        $cut->cancel();

        return $type;
    }

    /**
     * @param RtVersionCut $cut Frozen insertion-order membership
     * @return array<string, array<string, mixed>> Explicit application/test materialization
     */
    private function cutRows(RtVersionCut $cut): array
    {
        $cursor = $cut->rows();
        $rows = [];
        while (true) {
            $driver = new RtWorkDriver($cursor->advance());
            $this->complete($driver);
            if (($entry = $driver->result()) === null) {
                break;
            }
            $arena = $entry->reader()->arena();
            $record = new RtPreparedRow($arena, new RtRowValues($arena, $entry->rootIn($arena)->first, RtRowRole::Transient));
            $rows[$record->identity()->id()->toNative()] = $record->values()->toNative();
        }
        $cursor->cancel();

        return $rows;
    }

    /**
     * @param RtWorkDriver $driver Preparation under test
     * @param ?callable(): void $between Observe the unchanged draft between advances
     * @return int Number of controlled passes
     */
    private function complete(RtWorkDriver $driver, ?callable $between = null): int
    {
        $passes = 0;
        while (!$driver->isFinished()) {
            $steps = [1, 7, 29][$passes % 3];
            $bytes = [3, 71, 509][$passes % 3];
            $budget = new RtWorkBudget($steps, $bytes);
            $driver->advance($budget);
            $this->assertGreaterThan(0, $budget->usedSteps());
            $this->assertLessThanOrEqual($steps, $budget->usedSteps());
            $this->assertLessThanOrEqual($bytes, $budget->usedBytes());
            if (!$driver->isFinished() && $between !== null) {
                $between();
            }
            $this->assertLessThan(200000, ++$passes, 'Preparation must keep progressing');
        }

        return $passes;
    }

    /**
     * @param RtRowArena $arena Owner whose pending references retire
     * @param bool $observePhysical Assert monotonic physical release between every pass
     * @return int Retirement passes
     */
    private function drain(RtRowArena $arena, bool $observePhysical = false): int
    {
        $passes = 0;
        $previous = $arena->retention()->physicalBytes;
        while ($arena->retention()->pendingReleases > 0) {
            $budget = new RtWorkBudget(7, 11);
            $arena->advanceRetirement($budget);
            $this->assertGreaterThan(0, $budget->usedSteps());
            $this->assertLessThanOrEqual(7, $budget->usedSteps());
            $this->assertLessThanOrEqual(11, $budget->usedBytes());
            if ($observePhysical) {
                $this->assertLessThanOrEqual($previous, $arena->retention()->physicalBytes);
                $previous = $arena->retention()->physicalBytes;
                if ($arena->retention()->leases(RtRowRole::Ordinary) === 0) {
                    $this->assertSame($previous, $arena->retention()->bytes(RtRowRole::Retired));
                }
            }
            $this->assertLessThan(200000, ++$passes, 'Retirement must keep progressing');
        }

        return $passes;
    }

    /** @return array<string, mixed> Full row with nullable keys legitimately absent */
    private function row(): array
    {
        return [
            BackupHistory::id => 'history-id',
            BackupHistory::createdAt => '2026-11-05T10:00:00+01:00',
            BackupHistory::env => 'test',
            BackupHistory::scope => BackupScope::FULL->value,
            BackupHistory::connections => [],
            BackupHistory::sizeBytes => 120,
            BackupHistory::durationSeconds => 3,
            BackupHistory::keep => false,
            BackupHistory::status => BackupStatus::SUCCESS->value,
            BackupHistory::dumpBytes => 400,
            BackupHistory::restoreDurationSeconds => 0,
            BackupHistory::reachable => true,
        ];
    }
}

/** Synchronous fixture deliberately lacking a codec. */
final class UnmigratedSnapshotRow extends RtState
{
    /**
     * @param array<string, mixed> $row Unused legacy row
     * @return static Legacy synchronous instance
     */
    public static function fromRow(array $row): static
    {
        return new static();
    }

    /** @return string Stable fixture key */
    public function getId(): string
    {
        return 'legacy';
    }

    /** @return array<string, mixed> Serialization must never be a codec fallback */
    public function toArray(): array
    {
        TestCase::fail('Unmigrated row serialization must not run on the new path');
    }
}

/** Runtime context mounting one note collection cut into sets by node, one history collection and one standalone note. */
final class ResumableSnapshotRtContext extends RtContext
{
    public const string NOTES = 'resumableNotes';

    public const string HISTORIES = 'resumableHistories';

    public const string ITEM = 'resumableNoteItem';

    public readonly HilosProbeNotes $notes;

    public readonly BackupHistories $histories;

    public readonly HilosProbeNote $item;

    /** Registers the collections, the notes' view and the standalone note. */
    public function configure(): void
    {
        $this->notes = HilosProbeNotes::init();
        $this->histories = BackupHistories::init();
        $this->item = HilosProbeNote::create('item', 'node-a', 'item text');
        $this->_stateItems[self::ITEM] = $this->item;
        $this->_stateCollections[self::NOTES] = $this->notes;
        $this->_stateCollections[self::HISTORIES] = $this->histories;
        $this->setRepresent(self::NOTES, ViewHilosProbeNotes::class, HilosProbeNotesActions::class, HilosProbeNoteActions::class);
    }
}

/** Records every announcement with how it was made. */
final class ResumableSnapshotAnnouncements implements SourceChangeSubscriberInterface
{
    /** @var list<array{TableMutationType, string, SourceChangeProvenance}> Operation, row id and provenance */
    public array $changes = [];

    /**
     * @param SourceChange $change Announced change
     * @param SourceChangeProvenance $provenance How it was made
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->changes[] = [$change->mutationType, $change->sourceId, $provenance];
    }
}
