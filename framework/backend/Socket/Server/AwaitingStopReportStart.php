<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Cluster\Placement\PlacementId;
use Hilos\Socket\Client\WorkerClient;

/**
 * One agent whose next start waits for its earlier worker stop report.
 *
 * A closed worker link ends the wait just as a stop report does. No deadline is needed:
 * the old instance cannot report after its link has gone. A protected-mode replay remains
 * one of the freeze's agents while waiting, so a refreeze inherits it for the next lift.
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
     * @param WorkerClient $awaitedWorker Exact connection that owes the report
     * @param bool $protectedModeReplay Request replays an agent stopped by the freeze, without proving it started
     * @param ?PlacementId $placementId Exact managed order, if the start has one
     */
    public function __construct(
        public string $agentId,
        public string $agentType,
        public ?string $agentIndex,
        public bool $placedByLeader,
        public ?int $preferredWorkerId,
        public int $awaitedWorkerId,
        public WorkerClient $awaitedWorker,
        public bool $protectedModeReplay = false,
        public ?PlacementId $placementId = null,
    ) {
    }
}
