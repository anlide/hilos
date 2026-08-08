<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\Runtime\State\Item\RestoreRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the restore runtime singleton state row.
 */
final class RestoreRuntimeStateTest extends TestCase
{
    public function testCreateIsIdle(): void
    {
        $runtime = RestoreRuntime::create();

        $this->assertFalse($runtime->running);
        $this->assertNull($runtime->backupId);
        $this->assertNull($runtime->scope);
        $this->assertNull($runtime->phase);
        $this->assertNull($runtime->startedAt);
        $this->assertNull($runtime->finishedAt);
        $this->assertNull($runtime->outcome);
        $this->assertNull($runtime->failureReason);
        $this->assertSame(RestoreRuntime::ID, $runtime->getId());
        $this->assertSame(RestoreRuntime::RT_ITEM, RestoreRuntime::getRtCollectionKey());
    }

    public function testRowRoundTripPreservesFields(): void
    {
        $runtime = RestoreRuntime::create();
        $runtime->running = true;
        $runtime->backupId = 'backup-7';
        $runtime->scope = 'full';
        $runtime->phase = RestorePhase::IMPORTING->value;
        $runtime->startedAt = '2026-08-08T12:00:00+00:00';

        $restored = RestoreRuntime::fromRow($runtime->toArray());

        $this->assertTrue($restored->running);
        $this->assertSame('backup-7', $restored->backupId);
        $this->assertSame('full', $restored->scope);
        $this->assertSame(RestorePhase::IMPORTING->value, $restored->phase);
        $this->assertSame('2026-08-08T12:00:00+00:00', $restored->startedAt);
        $this->assertNull($restored->finishedAt);
        $this->assertNull($restored->outcome);
        $this->assertNull($restored->failureReason);
    }

    public function testFromRowToleratesMissingKeys(): void
    {
        $restored = RestoreRuntime::fromRow([]);

        $this->assertFalse($restored->running);
        $this->assertNull($restored->backupId);
        $this->assertNull($restored->phase);
        $this->assertNull($restored->outcome);
    }

    public function testApplyDiffUpdatesOnlyPresentFields(): void
    {
        $runtime = RestoreRuntime::create();
        $runtime->running = true;
        $runtime->backupId = 'backup-9';
        $runtime->phase = RestorePhase::IMPORTING->value;

        $runtime->applyDiff([
            RestoreRuntime::running => false,
            RestoreRuntime::phase => RestorePhase::FAILED->value,
            RestoreRuntime::finishedAt => '2026-08-08T12:05:00+00:00',
            RestoreRuntime::outcome => BackupStatus::ERROR->value,
            RestoreRuntime::failureReason => 'import failed on connection 0',
        ]);

        $this->assertFalse($runtime->running);
        $this->assertSame('backup-9', $runtime->backupId);
        $this->assertSame(RestorePhase::FAILED->value, $runtime->phase);
        $this->assertSame('2026-08-08T12:05:00+00:00', $runtime->finishedAt);
        $this->assertSame(BackupStatus::ERROR->value, $runtime->outcome);
        $this->assertSame('import failed on connection 0', $runtime->failureReason);
    }

    public function testApplyDiffNullsClearFields(): void
    {
        $runtime = RestoreRuntime::create();
        $runtime->backupId = 'backup-11';
        $runtime->outcome = BackupStatus::SUCCESS->value;

        $runtime->applyDiff([
            RestoreRuntime::backupId => null,
            RestoreRuntime::outcome => null,
        ]);

        $this->assertNull($runtime->backupId);
        $this->assertNull($runtime->outcome);
    }
}
