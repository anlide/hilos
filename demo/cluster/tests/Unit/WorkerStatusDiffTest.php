<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Runtime\State\Item\WorkerStatus;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * The diff of a fleet worker status row, read on a node that did not write it.
 *
 * One member owns its row and every other node holds a replica, so a report arrives
 * as a diff carrying only the counters that moved. A key the diff does not carry
 * therefore means "unchanged", and reading it as absent would walk the replica's
 * counters back to zero between two reports - which is exactly what the acceptance
 * scenarios read these rows to rule out.
 */
final class WorkerStatusDiffTest extends TestCase
{
    public function testADiffWithoutAKeyLeavesTheCounterAlone(): void
    {
        $status = WorkerStatus::fromRow(self::row());

        $status->applyDiff([WorkerStatus::updatedAt => 200]);

        $this->assertSame(4, $status->jobsDone);
        $this->assertSame(9, $status->rowsSeen);
        $this->assertSame(200, $status->updatedAt);
    }

    public function testADiffCarryingACounterAsTextIsRefused(): void
    {
        $status = WorkerStatus::fromRow(self::row());

        $this->expectException(InvalidFormatException::class);
        $status->applyDiff([WorkerStatus::jobsDone => '5']);
    }

    /**
     * @return array<string, mixed> Row a fleet worker status is built from
     */
    private static function row(): array
    {
        return [
            WorkerStatus::workerIndex => '1',
            WorkerStatus::jobsDone => 4,
            WorkerStatus::rowsSeen => 9,
            WorkerStatus::updatedAt => 100,
        ];
    }
}
