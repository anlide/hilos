<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\Cli\CpuStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CPU stats parsing and delta-based usage calculation.
 */
final class CpuStatsTest extends TestCase
{
    /**
     * fromProcStat() maps the five leading columns to user/nice/system/idle/iowait.
     */
    public function testFromProcStatMapsColumns(): void
    {
        $stats = CpuStats::fromProcStat('cpu 100 5 50 200 10');

        $this->assertSame(100, $stats->user);
        $this->assertSame(5, $stats->nice);
        $this->assertSame(50, $stats->system);
        $this->assertSame(200, $stats->idle);
        $this->assertSame(10, $stats->iowait);
        $this->assertSame(210, $stats->getIdle());
        $this->assertSame(155, $stats->getNonIdle());
        $this->assertSame(365, $stats->getTotal());
    }

    /**
     * getUsagePercentage() returns the non-idle share of the delta between samples.
     *
     * @param CpuStats $previous Earlier sample
     * @param CpuStats $current Later sample
     * @param float $expected Expected usage percentage
     */
    #[DataProvider('usageProvider')]
    public function testGetUsagePercentage(CpuStats $previous, CpuStats $current, float $expected): void
    {
        $this->assertSame($expected, $current->getUsagePercentage($previous));
    }

    /**
     * getUsagePercentage() returns null when total time did not advance between samples.
     *
     * @param CpuStats $previous Earlier sample
     * @param CpuStats $current Later sample
     */
    #[DataProvider('noProgressProvider')]
    public function testGetUsagePercentageReturnsNullWithoutProgress(CpuStats $previous, CpuStats $current): void
    {
        $this->assertNull($current->getUsagePercentage($previous));
    }

    /**
     * Samples for the percentage arithmetic cases.
     *
     * @return array<string, array{CpuStats, CpuStats, float}> Previous, current, expected percentage
     */
    public static function usageProvider(): array
    {
        return [
            // Equal busy and idle ticks added: half of the new time is non-idle.
            'half load' => [
                new CpuStats(100, 0, 0, 100, 0),
                new CpuStats(200, 0, 0, 200, 0),
                50.0,
            ],
            // All of the new time is non-idle.
            'full load' => [
                new CpuStats(100, 0, 0, 100, 0),
                new CpuStats(300, 0, 0, 100, 0),
                100.0,
            ],
            // All of the new time is idle (idle + iowait).
            'idle' => [
                new CpuStats(100, 0, 0, 100, 0),
                new CpuStats(100, 0, 0, 250, 50),
                0.0,
            ],
        ];
    }

    /**
     * Samples where total time does not advance, so usage cannot be computed.
     *
     * @return array<string, array{CpuStats, CpuStats}> Previous and current samples
     */
    public static function noProgressProvider(): array
    {
        return [
            // Identical samples: zero total delta.
            'identical' => [
                new CpuStats(100, 0, 0, 100, 0),
                new CpuStats(100, 0, 0, 100, 0),
            ],
            // Counters went backwards (host reset): negative delta.
            'counter reset' => [
                new CpuStats(300, 0, 0, 300, 0),
                new CpuStats(100, 0, 0, 100, 0),
            ],
        ];
    }
}
