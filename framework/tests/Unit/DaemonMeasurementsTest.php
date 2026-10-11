<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/** Measurement source labels and nullable values must agree before they reach a DTO. */
final class DaemonMeasurementsTest extends TestCase
{
    public function testRejectsAValueWithoutItsSourceLabel(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->measurement(cores: 1.0, coresBound: null);
    }

    public function testRejectsMasterLoadAboveDaemonLoad(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->measurement(daemonCores: 0.1, masterCores: 0.2);
    }

    public function testRejectsWorkerCountWithUnknownMemory(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->measurement(workersMeasured: 1);
    }

    private function measurement(
        ?float $cores = null,
        ?string $coresBound = null,
        ?float $daemonCores = null,
        ?float $masterCores = null,
        int $workersMeasured = 0,
    ): DaemonMeasurements {
        return new DaemonMeasurements(
            startedAt: 1000,
            cores: $cores,
            coresBound: $coresBound,
            daemonCores: $daemonCores,
            masterCores: $masterCores,
            machineLoad: null,
            masterMemory: null,
            workersMemory: null,
            workersMeasured: $workersMeasured,
            memoryCeiling: null,
            memoryCeilingBound: null,
            phpMemoryLimit: null,
        );
    }
}
