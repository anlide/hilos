<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable per-batch summary of one archived log rotation folder (HIL-383).
 *
 * A read model projected by {@see LogStoreSnapshot::batches()} from a single log-store walk: the
 * rotation timestamp plus file counts and byte weights for each classified stream group (agent,
 * worker, worker-monopolistic, daemon), and the confirmation an operator left on the directory
 * (HIL-483). It is an internal read value-object, not a signal payload; pages 385/386/387 map it
 * into their own transport DTOs.
 *
 * {@see $takenAt} is the one field of it that is not measured but READ: it comes from a marker file
 * the owner of the directory wrote ({@see LogBatchTakeoutMarker}), so a batch keeps its
 * confirmation across restarts, rescans and a change of the retention rule.
 */
final class LogBatchSummary
{
    /**
     * @param int $timestamp Unix timestamp of the rotation folder (parsed from the timestamped folder name)
     * @param int $agentFileCount Number of `agent-*.log` files in the batch
     * @param int $agentBytes Summed size in bytes of the agent files
     * @param int $workerFileCount Number of `worker-*.log` files in the batch (excludes monopolistic)
     * @param int $workerBytes Summed size in bytes of the worker files
     * @param int $workerMonopolisticFileCount Number of `worker-monopolistic-*.log` files in the batch
     * @param int $workerMonopolisticBytes Summed size in bytes of the worker-monopolistic files
     * @param int $daemonFileCount Number of daemon files in the batch (`daemon.log` and `daemon-error.log` by default)
     * @param int $daemonBytes Summed size in bytes of the daemon files
     * @param ?int $takenAt Unix timestamp an operator confirmed carrying the batch off at, or null while none has
     */
    public function __construct(
        public readonly int $timestamp,
        public readonly int $agentFileCount,
        public readonly int $agentBytes,
        public readonly int $workerFileCount,
        public readonly int $workerBytes,
        public readonly int $workerMonopolisticFileCount,
        public readonly int $workerMonopolisticBytes,
        public readonly int $daemonFileCount,
        public readonly int $daemonBytes,
        public readonly ?int $takenAt = null,
    ) {
    }
}
