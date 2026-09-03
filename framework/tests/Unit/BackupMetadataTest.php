<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Backup\Exception\BackupMetadataIncompleteException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame([], BackupMetadata::fromArray($this->minimalSidecar())->warnings);
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
        $this->assertNull(BackupMetadata::fromArray($this->minimalSidecar())->failureReason);
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
        $this->assertSame(0, BackupMetadata::fromArray($this->minimalSidecar())->dumpBytes);
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
        $legacy = BackupMetadata::fromArray($this->minimalSidecar());
        $this->assertNull($legacy->sha256);
        $this->assertNull($legacy->verifiedAt);
        $this->assertNull($legacy->verifyOutcome);
    }

    public function testAnUnknownStoredVerifyOutcomeReadsBackAsNull(): void
    {
        $metadata = BackupMetadata::fromArray(
            $this->minimalSidecar([BackupMetadata::verifyOutcome => 'nonsense']),
        );

        $this->assertNull($metadata->verifyOutcome);
        $this->assertSame(BackupVerifyOutcome::MISMATCH, BackupVerifyOutcome::fromString('mismatch'));
        $this->assertNull(BackupVerifyOutcome::fromString(''));
    }

    public function testShippingFieldsRoundTripAndLegacySidecarReadsThemAsNull(): void
    {
        $metadata = new BackupMetadata(
            id: 's1',
            createdAt: '2026-08-16T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 3,
            keep: false,
            status: BackupStatus::SUCCESS,
            shippedAt: '2026-08-16T04:05:06+00:00',
            shipOutcome: BackupShipOutcome::OK,
            shipError: null,
            shipEncryption: 'a1b2c3d4e5f6',
        );

        $restored = BackupMetadata::fromArray($metadata->toArray());
        $this->assertSame('2026-08-16T04:05:06+00:00', $restored->shippedAt);
        $this->assertSame(BackupShipOutcome::OK, $restored->shipOutcome);
        $this->assertNull($restored->shipError);
        $this->assertSame('a1b2c3d4e5f6', $restored->shipEncryption);

        // All four keys are always written, so "not copied anywhere" is told apart from
        // "written before shipping existed" by the presence of the key alone.
        $payload = $metadata->toArray();
        $this->assertArrayHasKey(BackupMetadata::shippedAt, $payload);
        $this->assertArrayHasKey(BackupMetadata::shipOutcome, $payload);
        $this->assertArrayHasKey(BackupMetadata::shipError, $payload);
        $this->assertArrayHasKey(BackupMetadata::shipEncryption, $payload);

        $legacy = BackupMetadata::fromArray($this->minimalSidecar());
        $this->assertNull($legacy->shippedAt);
        $this->assertNull($legacy->shipOutcome);
        $this->assertNull($legacy->shipError);
        // A sidecar written before encryption existed reads as "that copy left in the clear",
        // which is exactly what it did - so the planner owes it a copy the moment a key appears.
        $this->assertNull($legacy->shipEncryption);
    }

    public function testAnUnknownStoredShipOutcomeReadsBackAsNull(): void
    {
        $metadata = BackupMetadata::fromArray(
            $this->minimalSidecar([BackupMetadata::shipOutcome => 'halfway']),
        );

        $this->assertNull($metadata->shipOutcome);
    }

    public function testWithShippingRecordsTheAttemptAndKeepsEveryOtherField(): void
    {
        $original = new BackupMetadata(
            id: 's2',
            createdAt: '2026-08-16T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::SCHEMA_ONLY,
            connections: [new BackupConnectionMeta(0, 'db', 4)],
            sizeBytes: 8192,
            durationSeconds: 11,
            keep: true,
            status: BackupStatus::SUCCESS,
            warnings: ['note'],
            dumpBytes: 262144,
            sha256: str_repeat('ef', 32),
            verifiedAt: '2026-08-16T01:00:00+00:00',
            verifyOutcome: BackupVerifyOutcome::OK,
        );

        $failed = $original->withShipping(null, BackupShipOutcome::FAILED, 'ssh: connect timed out', null);
        $this->assertNull($failed->shippedAt);
        $this->assertSame(BackupShipOutcome::FAILED, $failed->shipOutcome);
        $this->assertSame('ssh: connect timed out', $failed->shipError);
        $this->assertNull($failed->shipEncryption);
        $this->assertTrue($failed->keep);
        $this->assertSame($original->sha256, $failed->sha256);
        $this->assertSame($original->verifiedAt, $failed->verifiedAt);
        $this->assertSame($original->warnings, $failed->warnings);
        $this->assertSame($original->connections, $failed->connections);

        // The retry clears the error the same way it sets the instant: one call writes all four.
        $shipped = $failed->withShipping('2026-08-16T06:00:00+00:00', BackupShipOutcome::OK, null, 'a1b2c3d4e5f6');
        $this->assertSame('2026-08-16T06:00:00+00:00', $shipped->shippedAt);
        $this->assertSame(BackupShipOutcome::OK, $shipped->shipOutcome);
        $this->assertNull($shipped->shipError);
        $this->assertSame('a1b2c3d4e5f6', $shipped->shipEncryption);
        $this->assertSame(BackupScope::SCHEMA_ONLY, $shipped->scope);
    }

    public function testTheOtherClonesCarryTheShippingRecordThrough(): void
    {
        // Pinning or verifying a backup must not quietly forget that a copy of it exists.
        $shipped = new BackupMetadata(
            id: 's3',
            createdAt: '2026-08-16T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 1,
            durationSeconds: 1,
            keep: false,
            status: BackupStatus::SUCCESS,
            shippedAt: '2026-08-16T02:00:00+00:00',
            shipOutcome: BackupShipOutcome::OK,
            shipEncryption: 'a1b2c3d4e5f6',
        );

        foreach ([
            $shipped->withKeep(true),
            $shipped->withVerification('2026-08-16T03:00:00+00:00', BackupVerifyOutcome::OK),
            $shipped->withRestore('2026-08-16T04:00:00+00:00', 12),
        ] as $clone) {
            $this->assertSame('2026-08-16T02:00:00+00:00', $clone->shippedAt);
            $this->assertSame(BackupShipOutcome::OK, $clone->shipOutcome);
            // Dropping the shape would make the next pass re-send a copy that is already there.
            $this->assertSame('a1b2c3d4e5f6', $clone->shipEncryption);
        }
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

    public function testWithSizeBytesReplacesTheSizeAndPreservesEveryOtherField(): void
    {
        $original = new BackupMetadata(
            id: 'z1',
            createdAt: '2026-08-20T00:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [new BackupConnectionMeta(0, 'db', 4)],
            sizeBytes: 0,
            durationSeconds: 9,
            keep: true,
            status: BackupStatus::SUCCESS,
            warnings: ['note'],
            failureReason: null,
            dumpBytes: 262144,
            sha256: str_repeat('ef', 32),
            verifiedAt: '2026-08-20T05:00:00+00:00',
            verifyOutcome: BackupVerifyOutcome::OK,
        );

        $measured = $original->withSizeBytes(4096);

        $this->assertSame(4096, $measured->sizeBytes);
        $this->assertSame($original->id, $measured->id);
        $this->assertSame($original->createdAt, $measured->createdAt);
        $this->assertSame($original->env, $measured->env);
        $this->assertSame($original->scope, $measured->scope);
        $this->assertSame($original->connections, $measured->connections);
        $this->assertSame($original->durationSeconds, $measured->durationSeconds);
        $this->assertSame($original->keep, $measured->keep);
        $this->assertSame($original->status, $measured->status);
        $this->assertSame($original->warnings, $measured->warnings);
        $this->assertSame($original->dumpBytes, $measured->dumpBytes);
        $this->assertSame($original->sha256, $measured->sha256);
        $this->assertSame($original->verifiedAt, $measured->verifiedAt);
        $this->assertSame($original->verifyOutcome, $measured->verifyOutcome);
    }

    public function testLegacyConnectionRecordsNoMigrationLevelWhileAnExplicitZeroSurvives(): void
    {
        // The distinction the restore gate stands on: a sidecar written before the field
        // must not read back as a database that genuinely carries no migrations.
        $legacy = BackupConnectionMeta::fromArray([
            BackupConnectionMeta::index => 0,
            BackupConnectionMeta::database => 'db',
        ]);
        $this->assertNull($legacy->migrationIndex);

        $zero = BackupConnectionMeta::fromArray(new BackupConnectionMeta(1, 'db', 0)->toArray());
        $this->assertSame(0, $zero->migrationIndex);
    }

    public function testUnknownScopeAndStatusFallBackToDefaults(): void
    {
        $metadata = BackupMetadata::fromArray($this->minimalSidecar([
            BackupMetadata::scope => 'nonsense',
            BackupMetadata::status => '',
        ]));

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

    /**
     * @return list<array{0: string}> One case per field the sidecar cannot be read without
     */
    public static function identityFieldProvider(): array
    {
        return [[BackupMetadata::id], [BackupMetadata::createdAt], [BackupMetadata::env]];
    }

    #[DataProvider('identityFieldProvider')]
    public function testASidecarMissingAnIdentityFieldIsRefused(string $field): void
    {
        $payload = $this->minimalSidecar();
        unset($payload[$field]);

        $this->expectException(BackupMetadataIncompleteException::class);
        BackupMetadata::fromArray($payload);
    }

    #[DataProvider('identityFieldProvider')]
    public function testASidecarWithAnEmptyIdentityFieldIsRefused(string $field): void
    {
        $this->expectException(BackupMetadataIncompleteException::class);
        BackupMetadata::fromArray($this->minimalSidecar([$field => '']));
    }

    /**
     * Builds the smallest payload the sidecar reader accepts: the three fields that address the
     * backup, plus whatever the case under test adds.
     *
     * @param array<string, mixed> $extra Fields layered on top of the identity trio
     * @return array<string, mixed> Sidecar payload
     */
    private function minimalSidecar(array $extra = []): array
    {
        return [
            BackupMetadata::id => 'x',
            BackupMetadata::createdAt => '2026-08-09T00:00:00+00:00',
            BackupMetadata::env => 'prod',
            ...$extra,
        ];
    }
}
