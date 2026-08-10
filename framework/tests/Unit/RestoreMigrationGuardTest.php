<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\RestoreMigrationDecision;
use Hilos\Backup\RestoreMigrationGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the restore migration-index gate.
 *
 * Only {@see RestoreMigrationGuard::decide()} is covered here: it is a pure function, and
 * keeping the disk read in its own method is what makes that possible. The companion
 * codeMigrationIndex() reads migration files and is exercised through the CLI and engine
 * suites, which have a fixture tree to read.
 */
final class RestoreMigrationGuardTest extends TestCase
{
    public function testEqualLevelsAllowWithNothingToReport(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 40)], 40);

        $this->assertSame(RestoreMigrationDecision::ALLOW, $result->decision);
        $this->assertNull($result->reason);
        $this->assertSame(40, $result->codeIndex);
        $this->assertSame([], $result->gaps, 'A matching connection has nothing to say about it');
    }

    public function testArchiveOlderThanCodeIsAllowedAndReportedAsAGap(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 32)], 40);

        $this->assertSame(RestoreMigrationDecision::ALLOW, $result->decision);
        $this->assertNull($result->reason);
        $this->assertCount(1, $result->gaps);
        $this->assertSame(0, $result->gaps[0]->connectionIndex);
        $this->assertSame(32, $result->gaps[0]->archiveIndex);
    }

    public function testArchiveNewerThanCodeIsRefusedWithTheExactGap(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 44)], 40);

        $this->assertSame(RestoreMigrationDecision::REFUSE, $result->decision);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('archive at migration 44', $result->reason);
        $this->assertStringContainsString('code expects 40', $result->reason);
        $this->assertStringContainsString('4 ahead', $result->reason);
        $this->assertStringContainsString('no downgrade path', $result->reason);
    }

    public function testUnrecordedArchiveLevelIsAllowedAsAGapWithNoNumber(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', null)], 40);

        $this->assertSame(RestoreMigrationDecision::ALLOW, $result->decision);
        $this->assertCount(1, $result->gaps);
        $this->assertNull($result->gaps[0]->archiveIndex);
    }

    public function testOneConnectionAheadRefusesTheWholeRun(): void
    {
        $result = RestoreMigrationGuard::decide(
            [
                new BackupConnectionMeta(0, 'primary', 32),
                new BackupConnectionMeta(1, 'secondary', 44),
            ],
            40,
        );

        $this->assertSame(RestoreMigrationDecision::REFUSE, $result->decision);
        $this->assertStringContainsString('connection 1', (string)$result->reason);
        $this->assertStringNotContainsString(
            'connection 0',
            (string)$result->reason,
            'Only the connection that is ahead explains the refusal',
        );
        $this->assertCount(2, $result->gaps, 'Both mismatching connections stay reportable');
    }

    public function testNoMigrationsInTheCodeAllowsAndLeavesEveryConnectionUncompared(): void
    {
        $result = RestoreMigrationGuard::decide(
            [
                new BackupConnectionMeta(0, 'primary', 32),
                new BackupConnectionMeta(1, 'secondary', 44),
            ],
            null,
        );

        $this->assertSame(RestoreMigrationDecision::ALLOW, $result->decision);
        $this->assertNull($result->codeIndex);
        $this->assertCount(2, $result->gaps);
    }

    public function testAnArchiveWithNoConnectionsHasNothingToGate(): void
    {
        $result = RestoreMigrationGuard::decide([], 40);

        $this->assertSame(RestoreMigrationDecision::ALLOW, $result->decision);
        $this->assertSame([], $result->gaps);
    }
}
