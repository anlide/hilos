<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Core\Page\PendingFrame;

/**
 * One facet count the test-only table lag is holding back (HIL-1020).
 *
 * What is held is the request to count, not a counted frame: the counts are made when the lag
 * lets them go, over the window the connection holds at that moment. A frame counted on arrival
 * and sent late would land after the next window with the numbers of the filter before it, which
 * is a screen nothing but the lag could ever produce.
 *
 * A record and not a closure, for the same reason as {@see PendingFrame}: only
 * the names are kept, so the release reads the window, the table and the guards afresh, exactly
 * as a count that was never held reads them.
 */
final class HeldFacetCounts
{
    /**
     * @param string $agentId Agent under which the counts were asked for, and under which they are released
     * @param string $page Page the table belongs to
     * @param string $acceptKey Connection the counts are for
     * @param string $tableKey Browser table key whose window describes the counted set
     * @param ?list<string> $only Filters to count, or null for every filter the connection declared
     * @param float $heldAt Unix seconds, with microseconds, when the counts were asked for and held
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $page,
        public readonly string $acceptKey,
        public readonly string $tableKey,
        public readonly ?array $only,
        public readonly float $heldAt,
    ) {
    }
}
