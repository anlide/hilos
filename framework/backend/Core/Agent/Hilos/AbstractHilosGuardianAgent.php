<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * AbstractHilosGuardianAgent - Abstract agent for Hilos guardian page (project validation robots).
 *
 * Projects must extend this class to provide a concrete agent for the Hilos guardian page.
 * If not extended, the guardian page will not function.
 */
abstract class AbstractHilosGuardianAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_GUARDIAN;

    /**
     * @var array<string, GuardianRunState>
     */
    private array $guardianRunStates = [];

    /**
     * Get current guardian run statuses keyed by agent id.
     *
     * @return array<string, string> Status map keyed by agent id
     */
    public function getGuardianRunStatuses(): array
    {
        $this->initializeGuardianRunStates();

        return array_map(
            static fn (GuardianRunState $state): string => $state->status->value,
            $this->guardianRunStates,
        );
    }

    /**
     * Check whether a guardian agent id is supported by the current project.
     *
     * @param string $agentId Guardian agent identifier
     * @return bool True when the agent is registered
     */
    public function hasGuardianAgent(string $agentId): bool
    {
        $this->initializeGuardianRunStates();

        return isset($this->guardianRunStates[$agentId]);
    }

    /**
     * Start one guardian run stub and broadcast the updated status.
     *
     * @param string $agentId Guardian agent identifier
     * @return GuardianRunStatus Updated run status
     */
    public function startGuardianRun(string $agentId): GuardianRunStatus
    {
        $this->initializeGuardianRunStates();

        if (!isset($this->guardianRunStates[$agentId])) {
            return GuardianRunStatus::NOT_STARTED;
        }

        $state = $this->guardianRunStates[$agentId];
        $updated = new GuardianRunState(
            GuardianRunStatus::IN_PROGRESS,
            microtime(true) + 5.0,
            $state->generation + 1,
        );
        $this->guardianRunStates[$agentId] = $updated;

        $this->broadcastGuardianRunStatus($agentId, $updated->status);

        return $updated->status;
    }

    /**
     * Stop one guardian run stub and broadcast the updated status.
     *
     * @param string $agentId Guardian agent identifier
     * @return GuardianRunStatus Updated run status
     */
    public function stopGuardianRun(string $agentId): GuardianRunStatus
    {
        $this->initializeGuardianRunStates();

        if (!isset($this->guardianRunStates[$agentId])) {
            return GuardianRunStatus::NOT_STARTED;
        }

        $state = $this->guardianRunStates[$agentId];
        if ($state->status !== GuardianRunStatus::IN_PROGRESS) {
            return $state->status;
        }

        $updated = new GuardianRunState(
            GuardianRunStatus::STOPPED,
            null,
            $state->generation + 1,
        );
        $this->guardianRunStates[$agentId] = $updated;

        $this->broadcastGuardianRunStatus($agentId, $updated->status);

        return $updated->status;
    }

    /**
     * Finalize runs whose completion deadlines have expired.
     */
    protected function processPendingGuardianRuns(): void
    {
        if ($this->guardianRunStates === []) {
            return;
        }

        $now = microtime(true);

        foreach ($this->guardianRunStates as $agentId => $state) {
            if (
                $state->status !== GuardianRunStatus::IN_PROGRESS
                || $state->completeAt === null
                || $state->completeAt > $now
            ) {
                continue;
            }

            $nextStatus = RandomHelper::integer(0, 1) === 0 ? GuardianRunStatus::DONE : GuardianRunStatus::FAILED;
            $this->guardianRunStates[$agentId] = new GuardianRunState(
                $nextStatus,
                null,
                $state->generation,
            );

            $this->broadcastGuardianRunStatus($agentId, $nextStatus);
        }
    }

    /**
     * Reset in-memory guardian run state.
     */
    protected function resetGuardianRunStates(): void
    {
        $this->guardianRunStates = [];
    }

    /**
     * Get guardian agent ids supported by the project.
     *
     * @return list<string> Guardian agent identifiers
     */
    abstract protected function getKnownGuardianAgentIds(): array;

    /**
     * Initialize in-memory guardian run states.
     */
    private function initializeGuardianRunStates(): void
    {
        if ($this->guardianRunStates !== []) {
            return;
        }

        foreach ($this->getKnownGuardianAgentIds() as $agentId) {
            $this->guardianRunStates[$agentId] = new GuardianRunState(
                GuardianRunStatus::NOT_STARTED,
                null,
                0,
            );
        }
    }

    /**
     * Hook for projects that mirror guardian run statuses into runtime state.
     *
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Run status
     */
    protected function onGuardianRunStatusChanged(string $agentId, GuardianRunStatus $status): void
    {
    }

    /**
     * Emit one guardian run status change for daemon-side fan-out.
     *
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Run status
     */
    private function broadcastGuardianRunStatus(string $agentId, GuardianRunStatus $status): void
    {
        $this->onGuardianRunStatusChanged($agentId, $status);
    }
}
