<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/lane-count.php';

/**
 * The lane count a full run settles on when nobody named one: a lane per two
 * cores, one lane left to the machine itself, never more lanes than the available
 * memory carries, and never fewer than one.
 *
 * The file under test is a plain script rather than a class, so it is required by
 * path — it is loaded the same way `scripts/run-test-suite.php` loads it, and the
 * runner itself is deliberately not loaded here, because requiring it would run a
 * whole test suite.
 */
final class LaneCountTest extends TestCase
{
    /**
     * @param int $cores Cores the machine reports; zero when it does not say.
     * @param float|null $availableGib Available memory in GiB; null when the machine does not report it.
     * @param int $lanes Lanes the run is expected to take.
     */
    #[DataProvider('machines')]
    public function testSizesTheRunFromTheMachine(int $cores, ?float $availableGib, int $lanes): void
    {
        $this->assertSame($lanes, adaptiveLaneCount($cores, $availableGib));
    }

    /**
     * @return array<string, array{int, float|null, int}> Cores, available GiB and the lanes they
     *     give, by what the machine is
     */
    public static function machines(): array
    {
        return [
            'the box of the line, where cores decide' => [16, 55.2, 7],
            'eight cores' => [8, 16.0, 3],
            'four cores, serial as before' => [4, 8.0, 1],
            'two cores, no lane to spare for the machine itself' => [2, 7.0, 1],
            'many cores held down by memory' => [16, 5.0, 2],
            'memory short of a single lane' => [16, 1.5, 1],
            'a machine that reports no cores' => [0, 55.2, 1],
            'a machine that reports no memory' => [16, null, 1],
        ];
    }
}
