<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the backup metadata sidecar DTO and its enums.
 */
final class BackupMetadataTest extends TestCase
{
    public function testArrayRoundTripPreservesAllFields(): void
    {
        $metadata = new BackupMetadata(
            id: '20260718-full-01',
            createdAt: '2026-07-18T10:20:30+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [
                new BackupConnectionMeta(0, 'hilos_demo', 12),
                new BackupConnectionMeta(1, 'hilos_secondary', 3),
            ],
            sizeBytes: 4096,
            durationSeconds: 7,
            keep: true,
            status: BackupStatus::SUCCESS,
        );

        $restored = BackupMetadata::fromArray($metadata->toArray());

        $this->assertSame('20260718-full-01', $restored->id);
        $this->assertSame('2026-07-18T10:20:30+00:00', $restored->createdAt);
        $this->assertSame('prod', $restored->env);
        $this->assertSame(BackupScope::FULL, $restored->scope);
        $this->assertSame(BackupStatus::SUCCESS, $restored->status);
        $this->assertSame(4096, $restored->sizeBytes);
        $this->assertSame(7, $restored->durationSeconds);
        $this->assertTrue($restored->keep);
        $this->assertCount(2, $restored->connections);
        $this->assertSame(1, $restored->connections[1]->index);
        $this->assertSame('hilos_secondary', $restored->connections[1]->database);
        $this->assertSame(3, $restored->connections[1]->migrationIndex);
    }

    public function testJsonRoundTripPreservesFields(): void
    {
        $metadata = new BackupMetadata(
            id: 'b1',
            createdAt: '2026-07-18T00:00:00+00:00',
            env: 'test',
            scope: BackupScope::SCHEMA_SEED,
            connections: [new BackupConnectionMeta(0, 'db', 1)],
            sizeBytes: 10,
            durationSeconds: 1,
            keep: false,
            status: BackupStatus::ERROR,
        );

        $restored = BackupMetadata::fromJson($metadata->toJson());

        $this->assertSame('b1', $restored->id);
        $this->assertSame(BackupScope::SCHEMA_SEED, $restored->scope);
        $this->assertSame(BackupStatus::ERROR, $restored->status);
        $this->assertSame('db', $restored->connections[0]->database);
    }

    public function testWarningsRoundTripAndDefaultEmpty(): void
    {
        $withWarning = new BackupMetadata(
            id: 'w1',
            createdAt: '2026-07-19T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::SCHEMA_SEED,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: BackupStatus::SUCCESS,
            warnings: ['schema-seed found no reference tables: captured schema only, seed data is empty'],
        );

        $restored = BackupMetadata::fromArray($withWarning->toArray());

        $this->assertSame($withWarning->warnings, $restored->warnings);
        $this->assertSame([], BackupMetadata::fromArray([BackupMetadata::id => 'x'])->warnings);
    }

    public function testFailureReasonRoundTripsAndDefaultsToNull(): void
    {
        $errorMeta = new BackupMetadata(
            id: 'e1',
            createdAt: '2026-07-20T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 3,
            keep: false,
            status: BackupStatus::ERROR,
            failureReason: 'child exited with code 2: mysqldump: connection refused',
        );

        $restored = BackupMetadata::fromArray($errorMeta->toArray());
        $this->assertSame(
            'child exited with code 2: mysqldump: connection refused',
            $restored->failureReason,
        );

        // The key is always written, even when null, so an error sidecar can key off it.
        $successArray = $errorMeta->toArray();
        $this->assertArrayHasKey(BackupMetadata::failureReason, $successArray);

        // A legacy sidecar written before the field existed carries no key and reads back as null.
        $this->assertNull(BackupMetadata::fromArray([BackupMetadata::id => 'x'])->failureReason);
    }

    public function testDumpBytesRoundTripsAndLegacySidecarReadsZero(): void
    {
        $metadata = new BackupMetadata(
            id: 'd1',
            createdAt: '2026-08-01T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 3,
            keep: false,
            status: BackupStatus::SUCCESS,
            dumpBytes: 262144,
        );

        $restored = BackupMetadata::fromArray($metadata->toArray());
        $this->assertSame(262144, $restored->dumpBytes);
        $this->assertArrayHasKey(BackupMetadata::dumpBytes, $metadata->toArray());

        // A legacy sidecar written before the field existed carries no key and reads back as 0.
        $this->assertSame(0, BackupMetadata::fromArray([BackupMetadata::id => 'x'])->dumpBytes);
    }

    public function testVerificationFieldsRoundTripAndLegacySidecarReadsThemAsNull(): void
    {
        $metadata = new BackupMetadata(
            id: 'v1',
            createdAt: '2026-08-02T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 3,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: str_repeat('ab', 32),
            verifiedAt: '2026-08-02T04:05:06+00:00',
            verifyOutcome: BackupVerifyOutcome::OK,
        );

        $restored = BackupMetadata::fromArray($metadata->toArray());
        $this->assertSame(str_repeat('ab', 32), $restored->sha256);
        $this->assertSame('2026-08-02T04:05:06+00:00', $restored->verifiedAt);
        $this->assertSame(BackupVerifyOutcome::OK, $restored->verifyOutcome);

        // All three keys are always written, so an absent digest is explicit rather than ambiguous.
        $payload = $metadata->toArray();
        $this->assertArrayHasKey(BackupMetadata::sha256, $payload);
        $this->assertArrayHasKey(BackupMetadata::verifiedAt, $payload);
        $this->assertArrayHasKey(BackupMetadata::verifyOutcome, $payload);

        // A sidecar written before this ticket has no digest at all: nothing to check, not corrupt.
        $legacy = BackupMetadata::fromArray([BackupMetadata::id => 'x']);
        $this->assertNull($legacy->sha256);
        $this->assertNull($legacy->verifiedAt);
        $this->assertNull($legacy->verifyOutcome);
    }

    public function testAnUnknownStoredVerifyOutcomeReadsBackAsNull(): void
    {
        $metadata = BackupMetadata::fromArray([
            BackupMetadata::id => 'x',
            BackupMetadata::verifyOutcome => 'nonsense',
        ]);

        $this->assertNull($metadata->verifyOutcome);
        $this->assertSame(BackupVerifyOutcome::MISMATCH, BackupVerifyOutcome::fromString('mismatch'));
        $this->assertNull(BackupVerifyOutcome::fromString(''));
    }

    public function testWithKeepAndWithVerificationPreserveEveryOtherField(): void
    {
        $original = new BackupMetadata(
            id: 'v2',
            createdAt: '2026-08-02T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::SCHEMA_SEED,
            connections: [new BackupConnectionMeta(0, 'db', 4)],
            sizeBytes: 8192,
            durationSeconds: 11,
            keep: false,
            status: BackupStatus::SUCCESS,
            warnings: ['note'],
            failureReason: null,
            dumpBytes: 262144,
            sha256: str_repeat('cd', 32),
        );

        $pinned = $original->withKeep(true);
        $this->assertTrue($pinned->keep);
        $this->assertSame($original->sha256, $pinned->sha256);
        $this->assertSame($original->dumpBytes, $pinned->dumpBytes);
        $this->assertSame($original->warnings, $pinned->warnings);
        $this->assertSame($original->connections, $pinned->connections);

        $verified = $pinned->withVerification('2026-08-02T05:00:00+00:00', BackupVerifyOutcome::MISMATCH);
        $this->assertSame('2026-08-02T05:00:00+00:00', $verified->verifiedAt);
        $this->assertSame(BackupVerifyOutcome::MISMATCH, $verified->verifyOutcome);
        // The pin taken a moment earlier survives the second clone.
        $this->assertTrue($verified->keep);
        $this->assertSame($original->sha256, $verified->sha256);
        $this->assertSame(BackupScope::SCHEMA_SEED, $verified->scope);
    }

    public function testUnknownScopeAndStatusFallBackToDefaults(): void
    {
        $metadata = BackupMetadata::fromArray([
            BackupMetadata::id => 'x',
            BackupMetadata::scope => 'nonsense',
            BackupMetadata::status => '',
        ]);

        $this->assertSame(BackupScope::FULL, $metadata->scope);
        $this->assertSame(BackupStatus::SUCCESS, $metadata->status);
        $this->assertSame([], $metadata->connections);
        $this->assertSame(0, $metadata->sizeBytes);
    }

    public function testScopeAndStatusParseKnownValues(): void
    {
        $this->assertSame(BackupScope::SCHEMA_ONLY, BackupScope::fromString('schema-only'));
        $this->assertNull(BackupScope::fromString('unknown'));
        $this->assertNull(BackupScope::fromString(''));
        $this->assertSame(BackupStatus::ERROR, BackupStatus::fromString('error'));
        $this->assertNull(BackupStatus::fromString(null));
    }
}
