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
 * Only {@see RestoreMigrationGuard::decide()} and the words its verdict carries are covered
 * here: the decision is a pure function, and keeping the disk read in its own method is what
 * makes that possible. The companion codeMigrationIndex() reads migration files and is
 * exercised through the CLI and engine suites, which have a fixture tree to read.
 *
 * The wording is asserted literally rather than by fragment, because the CLI preflight and the
 * backup page show the operator the same sentences and a drift between them would read as two
 * different verdicts.
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

    public function testAnArchiveBehindTheCodeIsWordedWithTheMigrationsItWillApply(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 32)], 40);

        $this->assertSame(
            [
                'connection 0: archive at migration 32, code expects 40;'
                . ' 8 migration(s) will be applied after the import',
            ],
            $result->describeGaps(),
        );
    }

    public function testAnUnrecordedLevelIsWordedAsAMissingCheckRatherThanAProblem(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', null)], 40);

        $this->assertSame(
            [
                'connection 0: archive records no migration level (sidecar predates the field);'
                . ' restoring without the compatibility check',
            ],
            $result->describeGaps(),
        );
    }

    public function testAnInstallationWithNoMigrationsSaysSoRatherThanBlamingTheArchive(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 32)], null);

        $this->assertSame(
            [
                'connection 0: archive at migration 32, this installation lists no migrations;'
                . ' restoring without the compatibility check',
            ],
            $result->describeGaps(),
        );
    }

    public function testMatchingConnectionsAreWordedNotAtAll(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 40)], 40);

        $this->assertSame([], $result->describeGaps());
    }

    public function testTheReportedLagIsTheFurthestConnectionBehindNotTheirSum(): void
    {
        $result = RestoreMigrationGuard::decide(
            [
                new BackupConnectionMeta(0, 'primary', 32),
                new BackupConnectionMeta(1, 'secondary', 36),
            ],
            40,
        );

        $this->assertSame(8, $result->migrationsBehind());
    }

    public function testConnectionsWithNothingToCompareDoNotCountAsLagging(): void
    {
        $result = RestoreMigrationGuard::decide(
            [
                new BackupConnectionMeta(0, 'primary', null),
                new BackupConnectionMeta(1, 'secondary', 36),
            ],
            40,
        );

        $this->assertSame(4, $result->migrationsBehind(), 'The unrecorded connection is not a lag of its own');
    }

    public function testAnArchiveThatIsLevelWithTheCodeLagsByNothing(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 40)], 40);

        $this->assertNull($result->migrationsBehind());
    }

    public function testAnUncomparableArchiveLagsByNothingRatherThanByZero(): void
    {
        // Zero would read as "nothing to apply", which is a claim; null is the absence of one, and
        // the row's badge shows a number only when there is one to show.
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', null)], null);

        $this->assertNull($result->migrationsBehind());
    }

    public function testARefusedArchiveReportsNoLagFromTheConnectionThatIsAhead(): void
    {
        $result = RestoreMigrationGuard::decide([new BackupConnectionMeta(0, 'db', 44)], 40);

        $this->assertNull($result->migrationsBehind(), 'An archive ahead of the code is not behind it');
    }
}
