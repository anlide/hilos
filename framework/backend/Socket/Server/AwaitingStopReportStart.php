<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

/**
 * One agent whose next start waits for its earlier worker stop report.
 *
 * A closed worker link ends the wait just as a stop report does. No deadline is needed:
 * the old instance cannot report after its link has gone.
 */
final readonly class AwaitingStopReportStart
{
    /**
     * @param string $agentId Agent whose start is deferred
     * @param string $agentType Agent type to start after the report
     * @param ?string $agentIndex Agent index, or null for a singleton
     * @param bool $placedByLeader Whether placement sanctioned this start
     * @param ?int $preferredWorkerId First worker requested for the new instance, if any
     * @param int $awaitedWorkerId Worker whose earlier stop report is awaited
     */
    public function __construct(
        public string $agentId,
        public string $agentType,
        public ?string $agentIndex,
        public bool $placedByLeader,
        public ?int $preferredWorkerId,
        public int $awaitedWorkerId,
    ) {
    }
}
