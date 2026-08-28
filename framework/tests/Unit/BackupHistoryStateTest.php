<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Core\Exception\InvalidFormatException;
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
        // The row's environment is never absent because this is the only way a row is born, and
        // the metadata it is born from always names one - which is why the field is not nullable.
        $this->assertSame('prod', $history->env);
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
        // And it survives the sync wire, so the invariant holds in every worker, not just the writer.
        $this->assertSame('test', $restored->env);
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

    public function testDumpBytesTransfersFromMetadataRoundTripsAndUpdatesViaDiff(): void
    {
        $history = BackupHistory::fromMetadata(new BackupMetadata(
            id: 'dump-1',
            createdAt: '2026-08-01T09:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 2,
            keep: false,
            status: BackupStatus::SUCCESS,
            dumpBytes: 262144,
        ));
        $this->assertSame(262144, $history->dumpBytes);

        // The field must survive a runtime row round-trip: the space guard reads it off the index.
        $this->assertSame(262144, BackupHistory::fromRow($history->toArray())->dumpBytes);

        // And a synced update must land, or every worker but the writer keeps a stale estimate input.
        $history->applyDiff([BackupHistory::dumpBytes => 524288]);
        $this->assertSame(524288, $history->dumpBytes);
    }

    public function testFromRowRefusesARowThatCarriesNoDumpBytes(): void
    {
        // Zero is a value the sender chose - "this run wrote no data", which the space guard
        // filters on - so a row without the key is a frame that lost it, not a run that had none.
        $row = $this->requiredRow('legacy');
        unset($row[BackupHistory::dumpBytes]);

        $this->expectException(InvalidFormatException::class);

        BackupHistory::fromRow($row);
    }

    public function testVerificationFieldsTransferFromMetadataRoundTripAndUpdateViaDiff(): void
    {
        $history = BackupHistory::fromMetadata(new BackupMetadata(
            id: 'ver-1',
            createdAt: '2026-08-02T09:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 2,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: str_repeat('ab', 32),
            verifiedAt: '2026-08-02T10:00:00+00:00',
            verifyOutcome: BackupVerifyOutcome::OK,
        ));
        $this->assertSame(str_repeat('ab', 32), $history->sha256);
        $this->assertSame('2026-08-02T10:00:00+00:00', $history->verifiedAt);
        // The runtime row keeps the outcome as its stored value, like scope and status do.
        $this->assertSame('ok', $history->verifyOutcome);

        $restored = BackupHistory::fromRow($history->toArray());
        $this->assertSame($history->sha256, $restored->sha256);
        $this->assertSame($history->verifiedAt, $restored->verifiedAt);
        $this->assertSame('ok', $restored->verifyOutcome);

        // A verification runs in one worker; without the diff the others would keep showing
        // "never checked" for a backup that was just found corrupt.
        $history->applyDiff([
            BackupHistory::verifiedAt => '2026-08-02T12:00:00+00:00',
            BackupHistory::verifyOutcome => 'mismatch',
        ]);
        $this->assertSame('2026-08-02T12:00:00+00:00', $history->verifiedAt);
        $this->assertSame('mismatch', $history->verifyOutcome);

        // An explicit null in a diff clears the field rather than being ignored.
        $history->applyDiff([BackupHistory::sha256 => null]);
        $this->assertNull($history->sha256);

        // A row written before checksums existed carries none of the three, and does not throw.
        $legacy = BackupHistory::fromRow($this->requiredRow('legacy'));
        $this->assertNull($legacy->sha256);
        $this->assertNull($legacy->verifiedAt);
        $this->assertNull($legacy->verifyOutcome);
    }

    public function testShippingFieldsTransferFromMetadataRoundTripAndUpdateViaDiff(): void
    {
        $history = BackupHistory::fromMetadata(new BackupMetadata(
            id: 'ship-1',
            createdAt: '2026-08-16T09:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 2,
            keep: false,
            status: BackupStatus::SUCCESS,
            shippedAt: '2026-08-16T10:00:00+00:00',
            shipOutcome: BackupShipOutcome::OK,
        ));
        $this->assertSame('2026-08-16T10:00:00+00:00', $history->shippedAt);
        // The runtime row keeps the outcome as its stored value, like scope and status do.
        $this->assertSame('ok', $history->shipOutcome);
        $this->assertNull($history->shipError);

        $restored = BackupHistory::fromRow($history->toArray());
        $this->assertSame($history->shippedAt, $restored->shippedAt);
        $this->assertSame('ok', $restored->shipOutcome);

        // The transfer runs in the agent's worker; without the diff every other worker would keep
        // showing a copy that succeeded hours ago as still pending.
        $history->applyDiff([
            BackupHistory::shippedAt => null,
            BackupHistory::shipOutcome => 'failed',
            BackupHistory::shipError => 'ssh: connect timed out',
        ]);
        $this->assertNull($history->shippedAt);
        $this->assertSame('failed', $history->shipOutcome);
        $this->assertSame('ssh: connect timed out', $history->shipError);

        // A row written before shipping existed carries none of the three, and does not throw.
        $legacy = BackupHistory::fromRow($this->requiredRow('legacy'));
        $this->assertNull($legacy->shippedAt);
        $this->assertNull($legacy->shipOutcome);
        $this->assertNull($legacy->shipError);
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
     * A history row carrying every field it cannot be read without, and none of the optional ones.
     *
     * @param string $id Backup id
     * @return array<string, mixed> Runtime row in its smallest legal shape
     */
    private function requiredRow(string $id): array
    {
        return [
            BackupHistory::id => $id,
            BackupHistory::createdAt => '2026-08-01T00:00:00+00:00',
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
