<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework backup runtime state rows and collection.
 */
final class BackupHistoryStateTest extends TestCase
{
    public function testFromMetadataMapsFields(): void
    {
        $metadata = new BackupMetadata(
            id: 'hist-1',
            createdAt: '2026-07-18T09:00:00+00:00',
            env: 'prod',
            scope: BackupScope::SCHEMA_ONLY,
            connections: [new BackupConnectionMeta(0, 'hilos_demo', 9)],
            sizeBytes: 2048,
            durationSeconds: 4,
            keep: true,
            status: BackupStatus::SUCCESS,
        );

        $history = BackupHistory::fromMetadata($metadata);

        $this->assertSame('hist-1', $history->getId());
        $this->assertSame('schema-only', $history->scope);
        $this->assertSame('success', $history->status);
        $this->assertSame(2048, $history->sizeBytes);
        $this->assertTrue($history->keep);
        $this->assertSame('hilos_demo', $history->connections[0]->database);
        $this->assertSame(BackupHistory::RT_COLLECTION, BackupHistory::getRtCollectionKey());
    }

    public function testRowRoundTripPreservesFields(): void
    {
        $history = BackupHistory::fromMetadata(new BackupMetadata(
            id: 'hist-2',
            createdAt: '2026-07-18T09:30:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [new BackupConnectionMeta(1, 'db1', 2)],
            sizeBytes: 5,
            durationSeconds: 6,
            keep: false,
            status: BackupStatus::ERROR,
            failureReason: 'child exited with code 1',
        ));

        $restored = BackupHistory::fromRow($history->toArray());

        $this->assertSame('hist-2', $restored->getId());
        $this->assertSame('full', $restored->scope);
        $this->assertSame('error', $restored->status);
        $this->assertSame(6, $restored->durationSeconds);
        $this->assertSame('child exited with code 1', $restored->failureReason);
        $this->assertCount(1, $restored->connections);
        $this->assertSame(1, $restored->connections[0]->index);
        $this->assertSame('db1', $restored->connections[0]->database);
    }

    public function testFailureReasonTransfersFromMetadataAndUpdatesViaDiff(): void
    {
        $history = BackupHistory::fromMetadata(new BackupMetadata(
            id: 'err-1',
            createdAt: '2026-07-20T09:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 2,
            keep: false,
            status: BackupStatus::ERROR,
            failureReason: 'timed out after 30s',
        ));
        $this->assertSame('timed out after 30s', $history->failureReason);

        $history->applyDiff([BackupHistory::failureReason => 'child exited with code 1']);
        $this->assertSame('child exited with code 1', $history->failureReason);

        // Re-projecting an error row as a success clears the reason: a null diff must apply.
        $history->applyDiff([BackupHistory::failureReason => null]);
        $this->assertNull($history->failureReason);
    }

    public function testHistoriesCollectionLookup(): void
    {
        $histories = BackupHistories::init();
        $histories->add(BackupHistory::fromMetadata($this->minimalMetadata('a')));
        $histories->add(BackupHistory::fromMetadata($this->minimalMetadata('b')));

        $this->assertCount(2, $histories);
        $this->assertSame('a', $histories['a']->getId());
        $this->assertNull($histories->get('missing'));
        $this->assertNull($histories->get(null));
    }

    public function testRuntimeSingletonDefaultsAndRoundTrip(): void
    {
        $runtime = BackupRuntime::create();

        $this->assertSame(BackupRuntime::ID, $runtime->getId());
        $this->assertFalse($runtime->running);
        $this->assertNull($runtime->currentBackupId);
        $this->assertNull($runtime->scope);
        $this->assertNull($runtime->startedAt);

        $restored = BackupRuntime::fromRow([
            BackupRuntime::running => true,
            BackupRuntime::currentBackupId => 'hist-9',
            BackupRuntime::scope => 'full',
            BackupRuntime::startedAt => '2026-07-18T10:00:00+00:00',
        ]);

        $this->assertTrue($restored->running);
        $this->assertSame('hist-9', $restored->currentBackupId);
        $this->assertSame('full', $restored->scope);
        $this->assertSame('2026-07-18T10:00:00+00:00', $restored->startedAt);
    }

    public function testHistoryAppliesAnInboundSyncDiff(): void
    {
        $row = BackupHistory::fromMetadata($this->minimalMetadata('hist-diff'));

        $row->applyDiff([
            BackupHistory::keep => true,
            BackupHistory::status => 'error',
            BackupHistory::sizeBytes => 4096,
        ]);

        // Untouched fields survive; the diff lands. Without applyDiff every worker but the
        // writer would keep the row exactly as it first arrived.
        $this->assertTrue($row->keep);
        $this->assertSame('error', $row->status);
        $this->assertSame(4096, $row->sizeBytes);
        $this->assertSame('test', $row->env);
        $this->assertSame('hist-diff', $row->getId());
    }

    public function testRuntimeAppliesAnInboundSyncDiff(): void
    {
        $runtime = BackupRuntime::create();

        $runtime->applyDiff([
            BackupRuntime::running => true,
            BackupRuntime::currentBackupId => 'hist-live',
            BackupRuntime::scope => 'full',
            BackupRuntime::startedAt => '2026-07-18T10:00:00+00:00',
        ]);

        $this->assertTrue($runtime->running);
        $this->assertSame('hist-live', $runtime->currentBackupId);

        $runtime->applyDiff([
            BackupRuntime::running => false,
            BackupRuntime::currentBackupId => null,
            BackupRuntime::scope => null,
            BackupRuntime::startedAt => null,
        ]);

        // Clearing the in-progress row is a diff of nulls: it must clear, not be ignored.
        $this->assertFalse($runtime->running);
        $this->assertNull($runtime->currentBackupId);
        $this->assertNull($runtime->scope);
        $this->assertNull($runtime->startedAt);
    }

    /**
     * @param string $id Backup id
     * @return BackupMetadata Minimal successful metadata
     */
    private function minimalMetadata(string $id): BackupMetadata
    {
        return new BackupMetadata(
            id: $id,
            createdAt: '2026-07-18T00:00:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: BackupStatus::SUCCESS,
        );
    }
}
