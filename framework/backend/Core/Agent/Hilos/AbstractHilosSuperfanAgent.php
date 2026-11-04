<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use LogicException;

/**
 * AbstractHilosSuperfanAgent - Abstract agent for Hilos superfans page (project validation robots).
 *
 * Projects must extend this class to provide a concrete agent for the Hilos superfans page.
 * If not extended, the superfans page will not function.
 */
abstract class AbstractHilosSuperfanAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SUPERFANS;

    /**
     * @var array<string, SuperfanRunState>
     */
    private array $superfanRunStates = [];

    /**
     * Get current superfan run statuses keyed by agent id.
     *
     * @return array<string, string> Status map keyed by agent id
     */
    public function getSuperfanRunStatuses(): array
    {
        $this->initializeSuperfanRunStates();

        return array_map(
            static fn (SuperfanRunState $state): string => $state->status->value,
            $this->superfanRunStates,
        );
    }

    /**
     * Check whether a superfan agent id is supported by the current project.
     *
     * @param string $agentId Superfan agent identifier
     * @return bool True when the agent is registered
     */
    public function hasSuperfanAgent(string $agentId): bool
    {
        $this->initializeSuperfanRunStates();

        return isset($this->superfanRunStates[$agentId]);
    }

    /**
     * Start one superfan run stub and broadcast the updated status.
     *
     * @param string $agentId Superfan agent identifier
     * @return SuperfanRunStatus Updated run status
     * @throws HilosException When the framework operation fails
     * @throws LogicException When a project runtime item factory rejects a status row
     */
    public function startSuperfanRun(string $agentId): SuperfanRunStatus
    {
        $this->initializeSuperfanRunStates();

        if (!isset($this->superfanRunStates[$agentId])) {
            return SuperfanRunStatus::NOT_STARTED;
        }

        $state = $this->superfanRunStates[$agentId];
        $updated = new SuperfanRunState(
            SuperfanRunStatus::IN_PROGRESS,
            microtime(true) + 5.0,
            $state->generation + 1,
        );
        $this->superfanRunStates[$agentId] = $updated;

        $this->broadcastSuperfanRunStatus($agentId, $updated->status);

        return $updated->status;
    }

    /**
     * Stop one superfan run stub and broadcast the updated status.
     *
     * @param string $agentId Superfan agent identifier
     * @return SuperfanRunStatus Updated run status
     * @throws HilosException When the framework operation fails
     * @throws LogicException When a project runtime item factory rejects a status row
     */
    public function stopSuperfanRun(string $agentId): SuperfanRunStatus
    {
        $this->initializeSuperfanRunStates();

        if (!isset($this->superfanRunStates[$agentId])) {
            return SuperfanRunStatus::NOT_STARTED;
        }

        $state = $this->superfanRunStates[$agentId];
        if ($state->status !== SuperfanRunStatus::IN_PROGRESS) {
            return $state->status;
        }

        $updated = new SuperfanRunState(
            SuperfanRunStatus::STOPPED,
            null,
            $state->generation + 1,
        );
        $this->superfanRunStates[$agentId] = $updated;

        $this->broadcastSuperfanRunStatus($agentId, $updated->status);

        return $updated->status;
    }

    /**
     * Finalize runs whose completion deadlines have expired.
     *
     * @throws HilosException When the framework operation fails
     * @throws LogicException When a project runtime item factory rejects a status row
     */
    protected function processPendingSuperfanRuns(): void
    {
        if ($this->superfanRunStates === []) {
            return;
        }

        $now = microtime(true);

        foreach ($this->superfanRunStates as $agentId => $state) {
            if (
                $state->status !== SuperfanRunStatus::IN_PROGRESS
                || $state->completeAt === null
                || $state->completeAt > $now
            ) {
                continue;
            }

            $nextStatus = RandomHelper::integer(0, 1) === 0 ? SuperfanRunStatus::DONE : SuperfanRunStatus::FAILED;
            $this->superfanRunStates[$agentId] = new SuperfanRunState(
                $nextStatus,
                null,
                $state->generation,
            );

            $this->broadcastSuperfanRunStatus($agentId, $nextStatus);
        }
    }

    /**
     * Reset in-memory superfan run state.
     */
    protected function resetSuperfanRunStates(): void
    {
        $this->superfanRunStates = [];
    }

    /**
     * Hook for projects that mirror superfan run statuses into runtime state.
     *
     * @param string $agentId Superfan agent identifier
     * @param SuperfanRunStatus $status Run status
     * @throws HilosException When a project status mirror cannot be updated
     * @throws LogicException When a project runtime item factory rejects a status row
     */
    protected function onSuperfanRunStatusChanged(string $agentId, SuperfanRunStatus $status): void
    {
    }

    /**
     * Get superfan agent ids supported by the project.
     *
     * @return list<string> Superfan agent identifiers
     */
    abstract protected function getKnownSuperfanAgentIds(): array;

    /**
     * Initialize in-memory superfan run states.
     */
    private function initializeSuperfanRunStates(): void
    {
        if ($this->superfanRunStates !== []) {
            return;
        }

        foreach ($this->getKnownSuperfanAgentIds() as $agentId) {
            $this->superfanRunStates[$agentId] = new SuperfanRunState(
                SuperfanRunStatus::NOT_STARTED,
                null,
                0,
            );
        }
    }

    /**
     * Emit one superfan run status change for daemon-side fan-out.
     *
     * @param string $agentId Superfan agent identifier
     * @param SuperfanRunStatus $status Run status
     */
    private function broadcastSuperfanRunStatus(string $agentId, SuperfanRunStatus $status): void
    {
        $this->onSuperfanRunStatusChanged($agentId, $status);
    }
}
