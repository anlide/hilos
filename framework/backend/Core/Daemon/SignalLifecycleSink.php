<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Placement\PlacementRecovery;
use Closure;
use Hilos\Cluster\PlacementVerdictSink;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/** Synchronous lifecycle ports owned by one dispatch call, never by a deferred start or placement. */
final readonly class SignalLifecycleSink implements AgentStartSink, PlacementVerdictSink
{
    /**
     * @param Closure(string): void $started Completed-start notification
     * @param Closure(string): void $stopped Stop notification
     * @param Closure(string, string, ?PlacementRecovery): void $failed Failed-start notification
     * @param Closure(string, ?string, string, ?PlacementRecovery, ?Closure): void $notPlaced Negative placement notification
     */
    public function __construct(
        private Closure $started,
        private Closure $stopped,
        private Closure $failed,
        private Closure $notPlaced,
    ) {
    }

    /**
     * @param string $agentId Agent that started
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a restart deadline cannot be changed
     */
    public function onAgentStarted(string $agentId): void
    {
        ($this->started)($agentId);
    }

    /**
     * @param string $agentId Agent that stopped
     * @throws InvalidFormatException When a pending page recovery address is malformed
     * @throws EventLoopException When a node-report deadline cannot be changed
     */
    public function onAgentStopped(string $agentId): void
    {
        ($this->stopped)($agentId);
    }

    /**
     * @param string $agentId Agent whose start failed
     * @param string $reason Failure reported by the start
     * @param ?PlacementRecovery $recovery Live replay obligation that can outlast this refusal
     * @throws InvalidArgumentException When a held frame's refusal cannot be named
     * @throws InvalidFormatException When a tracked delivery address is malformed
     * @throws EventLoopException When a restart deadline cannot be changed
     */
    public function onAgentStartFailed(string $agentId, string $reason, ?PlacementRecovery $recovery = null): void
    {
        ($this->failed)($agentId, $reason, $recovery);
    }

    /**
     * @param string $agentType Agent that could not be placed
     * @param ?string $agentIndex Agent instance, or null for a singleton
     * @param string $reason Placement verdict
     * @param ?PlacementRecovery $recovery Live replay obligation that can outlast this refusal
     * @param ?Closure(): bool $current Whether the admitted placement decision can still take effect
     * @throws InvalidArgumentException When a held frame's refusal cannot be named
     * @throws InvalidFormatException When a tracked delivery address is malformed
     */
    public function onAgentNotPlaced(
        string $agentType,
        ?string $agentIndex,
        string $reason,
        ?PlacementRecovery $recovery = null,
        ?Closure $current = null,
    ): void
    {
        ($this->notPlaced)($agentType, $agentIndex, $reason, $recovery, $current);
    }
}
