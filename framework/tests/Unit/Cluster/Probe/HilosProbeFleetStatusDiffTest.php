<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Probe;

use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * The diff of a probe fleet status row, read on a node that did not write it.
 *
 * One member owns its row and every other node holds a replica, so a report arrives
 * as a diff carrying only the counters that moved. A key the diff does not carry
 * therefore means "unchanged", and reading it as absent would walk the replica's
 * counters back to zero between two reports - which is exactly what the acceptance
 * scenarios read these rows to rule out.
 */
final class HilosProbeFleetStatusDiffTest extends TestCase
{
    public function testADiffWithoutAKeyLeavesTheCounterAlone(): void
    {
        $status = HilosProbeFleetStatus::fromRow(self::row());

        $status->applyDiff([HilosProbeFleetStatus::updatedAt => 200]);

        $this->assertSame(4, $status->jobsDone);
        $this->assertSame(9, $status->rowsSeen);
        $this->assertSame(200, $status->updatedAt);
    }

    public function testADiffCarryingACounterAsTextIsRefused(): void
    {
        $status = HilosProbeFleetStatus::fromRow(self::row());

        $this->expectException(InvalidFormatException::class);
        $status->applyDiff([HilosProbeFleetStatus::jobsDone => '5']);
    }

    /**
     * @return array<string, mixed> Row a probe fleet status is built from
     */
    private static function row(): array
    {
        return [
            HilosProbeFleetStatus::workerIndex => '1',
            HilosProbeFleetStatus::jobsDone => 4,
            HilosProbeFleetStatus::rowsSeen => 9,
            HilosProbeFleetStatus::updatedAt => 100,
        ];
    }
}
