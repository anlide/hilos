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

    public function testTheArchivesOwnMarkerIsTheLevel(): void
    {
        $result = RestoreMigrationGuard::resolveLevel(32, 32, null, 40, 0);

        $this->assertSame(32, $result->level);
        $this->assertNull($result->reason);
    }

    public function testAnOperatorSuppliesTheLevelAnArchiveDoesNotDeclare(): void
    {
        $result = RestoreMigrationGuard::resolveLevel(null, null, 32, 40, 0);

        $this->assertSame(32, $result->level);
        $this->assertNull($result->reason);
    }

    public function testAnOperatorRepeatingTheMarkerIsANoOp(): void
    {
        $result = RestoreMigrationGuard::resolveLevel(32, null, 32, 40, 0);

        $this->assertSame(32, $result->level);
        $this->assertNull($result->reason);
    }

    public function testAnOperatorMayNotOverruleTheArchivesOwnLevel(): void
    {
        // Lowering it would bring back exactly the replay over a finished schema this marker
        // exists to stop, and raising it would skip migrations nobody applied.
        $result = RestoreMigrationGuard::resolveLevel(32, null, 30, 40, 0);

        $this->assertNull($result->level);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('the archive records migration 32', $result->reason);
        $this->assertStringContainsString('--migration-index says 30', $result->reason);
    }

    public function testADumpContradictingItsSidecarIsRefused(): void
    {
        // Both numbers are written in one run from one reading, so this is not two versions of
        // the truth: one of the two files is not the one it claims to be.
        $result = RestoreMigrationGuard::resolveLevel(32, 31, null, 40, 0);

        $this->assertNull($result->level);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('the archive contradicts its sidecar', $result->reason);
        $this->assertStringContainsString('dump 32, sidecar 31', $result->reason);
    }

    public function testAnArchiveDeclaringNothingIsRefusedWithTheRecipe(): void
    {
        // The refusal has to be actionable: an operator holding an archive written before the
        // marker existed can still restore it, but only by naming the level themselves.
        $result = RestoreMigrationGuard::resolveLevel(null, null, null, 40, 2);

        $this->assertNull($result->level);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('connection 2:', $result->reason);
        $this->assertStringContainsString('records no migration level', $result->reason);
        $this->assertStringContainsString('--migration-index=<N>', $result->reason);
        $this->assertStringContainsString('highest numeric prefix', $result->reason);
    }

    public function testALevelAheadOfTheCodeIsRefusedInTheGatesOwnWords(): void
    {
        $result = RestoreMigrationGuard::resolveLevel(null, null, 44, 40, 0);

        $this->assertNull($result->level);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('archive at migration 44', $result->reason);
        $this->assertStringContainsString('code expects 40', $result->reason);
        $this->assertStringContainsString('4 ahead', $result->reason);
        $this->assertStringContainsString('no downgrade path', $result->reason);
    }

    public function testASidecarThatRecordedNoLevelContradictsNothing(): void
    {
        // Written before the field existed: "not recorded" is not a second opinion.
        $result = RestoreMigrationGuard::resolveLevel(32, null, null, 40, 0);

        $this->assertSame(32, $result->level);
        $this->assertNull($result->reason);
    }

    public function testATreeListingNoMigrationsAcceptsTheLevelAsGiven(): void
    {
        // Nothing to compare against is not a reason to refuse, on the same precedent decide()
        // allows an uncomparable archive by.
        $result = RestoreMigrationGuard::resolveLevel(null, null, 44, null, 0);

        $this->assertSame(44, $result->level);
        $this->assertNull($result->reason);
    }

    public function testLevelZeroIsALevelAndNotAMissingOne(): void
    {
        $result = RestoreMigrationGuard::resolveLevel(0, 0, null, 40, 0);

        $this->assertSame(0, $result->level);
        $this->assertNull($result->reason);
    }
}
