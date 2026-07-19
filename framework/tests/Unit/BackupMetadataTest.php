<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
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
