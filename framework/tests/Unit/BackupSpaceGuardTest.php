<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupSpaceGuard;
use Hilos\Backup\BackupSpacePolicy;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\State\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure free-space admit/refuse decision.
 *
 * The guard reads no disk and no clock: free bytes, index rows, scope, and policy in; a decision
 * out. Every case here pins one facet - the median arithmetic, the scope/status/dumpBytes filter,
 * the always-on floor, the no-estimate policy branches, and the reason text.
 */
final class BackupSpaceGuardTest extends TestCase
{
    public function testEstimateFromASingleRunIsDumpPlusArchiveTimesMargin(): void
    {
        // (dump 100 + archive 50) x 2.0 = 300 required; free at the edge is admitted.
        $rows = [$this->row(BackupScope::FULL, dumpBytes: 100, sizeBytes: 50)];
        $policy = new BackupSpacePolicy(spaceMargin: 2.0, minFreeBytes: 0, refuseWithoutEstimate: false);

        $atEdge = new BackupSpaceGuard()->decide(300, $rows, BackupScope::FULL, $policy);
        $this->assertTrue($atEdge->allowed);
        $this->assertSame(300, $atEdge->requiredBytes);

        $below = new BackupSpaceGuard()->decide(299, $rows, BackupScope::FULL, $policy);
        $this->assertFalse($below->allowed);
        $this->assertSame(299, $below->freeBytes);
    }

    public function testMedianOverTwoRunsAveragesTheMiddleValues(): void
    {
        // dumps {100,200} -> median 150; archives {40,60} -> median 50; x1.0 = 200.
        $rows = [
            $this->row(BackupScope::FULL, dumpBytes: 100, sizeBytes: 40),
            $this->row(BackupScope::FULL, dumpBytes: 200, sizeBytes: 60),
        ];
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 0, refuseWithoutEstimate: false);

        $decision = new BackupSpaceGuard()->decide(199, $rows, BackupScope::FULL, $policy);

        $this->assertFalse($decision->allowed);
        $this->assertSame(200, $decision->requiredBytes);
    }

    public function testMedianOverFiveRunsTakesTheMiddleValue(): void
    {
        // dumps {10,20,30,40,50} -> median 30; archives all 0 -> median 0; x1.0 = 30.
        $rows = [];
        foreach ([10, 20, 30, 40, 50] as $dump) {
            $rows[] = $this->row(BackupScope::FULL, dumpBytes: $dump, sizeBytes: 0);
        }
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 0, refuseWithoutEstimate: false);

        $this->assertSame(
            30,
            new BackupSpaceGuard()->decide(30, $rows, BackupScope::FULL, $policy)->requiredBytes,
        );
    }

    public function testOnlyTheNewestFiveSizedRunsCount(): void
    {
        // A sixth, older, huge run must not skew the median: only the newest five are sampled.
        $rows = [];
        foreach ([10, 20, 30, 40, 50] as $index => $dump) {
            $rows[] = $this->row(BackupScope::FULL, dumpBytes: $dump, createdAt: sprintf('2026-07-2%dT00:00:00+00:00', $index + 1));
        }
        $rows[] = $this->row(BackupScope::FULL, dumpBytes: 1_000_000, createdAt: '2026-07-10T00:00:00+00:00');
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 0, refuseWithoutEstimate: false);

        // Median of the newest five {10,20,30,40,50} is still 30, not lifted by the old giant.
        $this->assertSame(
            30,
            new BackupSpaceGuard()->decide(0, $rows, BackupScope::FULL, $policy)->requiredBytes,
        );
    }

    public function testRunsOfOtherScopesAndNonSuccessDoNotCount(): void
    {
        // Only success rows of the SAME scope carrying a dump size feed the estimate; everything
        // else here is noise, leaving no estimate and the proceed-by-default no-estimate branch.
        $rows = [
            $this->row(BackupScope::SCHEMA_ONLY, dumpBytes: 5_000, sizeBytes: 5_000),
            $this->row(BackupScope::FULL, dumpBytes: 5_000, status: BackupStatus::ERROR),
        ];
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 100, refuseWithoutEstimate: false);

        $decision = new BackupSpaceGuard()->decide(500, $rows, BackupScope::FULL, $policy);

        // Only the floor gates: 500 >= 100, so it is admitted despite the unrelated big rows.
        $this->assertTrue($decision->allowed);
        $this->assertSame(100, $decision->requiredBytes);
    }

    public function testAZeroDumpSizeCountsAsNoEstimate(): void
    {
        // A legacy sidecar or the in-archive stub carries dumpBytes 0: it must not seed an estimate.
        $rows = [$this->row(BackupScope::FULL, dumpBytes: 0, sizeBytes: 9_000)];
        $refuse = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 0, refuseWithoutEstimate: true);

        $decision = new BackupSpaceGuard()->decide(1_000_000, $rows, BackupScope::FULL, $refuse);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('no prior successful run', (string)$decision->reason);
    }

    public function testFloorAppliesEvenWhenTheEstimateIsSmall(): void
    {
        // Estimate (10) is dwarfed by the floor (1000): the floor is the binding requirement.
        $rows = [$this->row(BackupScope::FULL, dumpBytes: 10, sizeBytes: 0)];
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 1000, refuseWithoutEstimate: false);

        $refused = new BackupSpaceGuard()->decide(500, $rows, BackupScope::FULL, $policy);
        $this->assertFalse($refused->allowed);
        $this->assertSame(1000, $refused->requiredBytes);
        $this->assertStringContainsString('minimum free-space floor', (string)$refused->reason);

        $this->assertTrue(new BackupSpaceGuard()->decide(1000, $rows, BackupScope::FULL, $policy)->allowed);
    }

    public function testNoEstimateProceedsByDefaultButStillHonoursTheFloor(): void
    {
        $policy = new BackupSpacePolicy(spaceMargin: 1.5, minFreeBytes: 1000, refuseWithoutEstimate: false);

        // No history at all: proceed is the default, but the floor still gates.
        $this->assertTrue(new BackupSpaceGuard()->decide(1000, [], BackupScope::FULL, $policy)->allowed);

        $belowFloor = new BackupSpaceGuard()->decide(999, [], BackupScope::FULL, $policy);
        $this->assertFalse($belowFloor->allowed);
        $this->assertStringContainsString('minimum free-space floor', (string)$belowFloor->reason);
    }

    public function testNoEstimateRefusesWhenThePolicyDemandsIt(): void
    {
        $policy = new BackupSpacePolicy(spaceMargin: 1.0, minFreeBytes: 0, refuseWithoutEstimate: true);

        $decision = new BackupSpaceGuard()->decide(1_000_000_000, [], BackupScope::FULL, $policy);

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('BACKUP_REFUSE_WITHOUT_ESTIMATE', (string)$decision->reason);
    }

    public function testTheRefusalReasonNamesTheNeedTheFreeSpaceAndTheScope(): void
    {
        $rows = [$this->row(BackupScope::SCHEMA_SEED, dumpBytes: 400, sizeBytes: 100)];
        $policy = new BackupSpacePolicy(spaceMargin: 2.0, minFreeBytes: 0, refuseWithoutEstimate: false);

        // (400 + 100) x 2.0 = 1000 needed; 200 free.
        $reason = (string)new BackupSpaceGuard()->decide(200, $rows, BackupScope::SCHEMA_SEED, $policy)->reason;

        $this->assertStringContainsString('Insufficient free space', $reason);
        $this->assertStringContainsString('schema-seed', $reason);
        $this->assertStringContainsString('1000 bytes', $reason);
        $this->assertStringContainsString('200 bytes free', $reason);
    }

    /**
     * @param BackupScope $scope Scope of the run
     * @param int $dumpBytes Uncompressed dump volume
     * @param int $sizeBytes Archive size
     * @param BackupStatus $status Terminal status
     * @param string $createdAt ISO-8601 creation timestamp
     * @return BackupHistory Index row for the guard
     */
    private function row(
        BackupScope $scope,
        int $dumpBytes = 0,
        int $sizeBytes = 0,
        BackupStatus $status = BackupStatus::SUCCESS,
        string $createdAt = '2026-07-20T00:00:00+00:00',
    ): BackupHistory {
        return BackupHistory::fromMetadata(new BackupMetadata(
            id: $scope->value . '-' . $createdAt . '-' . $dumpBytes,
            createdAt: $createdAt,
            env: 'test',
            scope: $scope,
            connections: [],
            sizeBytes: $sizeBytes,
            durationSeconds: 0,
            keep: false,
            status: $status,
            dumpBytes: $dumpBytes,
        ));
    }
}
