<?php

declare(strict_types=1);

namespace Hilos\Guardian\Engine;

use Hilos\Guardian\Contracts\GuardianEngineInterface;
use Hilos\Guardian\Contracts\TaskPlannerInterface;
use Hilos\Guardian\DTO\GuardianReport;
use Hilos\Guardian\DTO\InvestigationTask;
use Hilos\Guardian\DTO\TaskResult;
use Hilos\Guardian\Enums\TaskStatus;
use Hilos\Guardian\Telemetry\GuardianTelemetry;

/**
 * Guardian engine that investigates goals via planner tasks and registered executors.
 */
final class GuardianEngine implements GuardianEngineInterface
{
    /** @var list<callable> Task executors (receive InvestigationTask, context; return TaskResult) */
    private array $executors = [];

    /**
     * Creates guardian engine with planner.
     *
     * @param TaskPlannerInterface $planner Task planner
     */
    public function __construct(
        private readonly TaskPlannerInterface $planner,
    ) {
    }

    /**
     * Registers executor for investigation tasks.
     *
     * @param callable $executor Task executor (receives InvestigationTask, context; returns TaskResult)
     */
    public function registerExecutor(callable $executor): void
    {
        $this->executors[] = $executor;
    }

    /**
     * Investigates goal and returns report.
     *
     * @param string $goal Investigation goal
     * @param array<string, mixed> $context Optional context
     * @return GuardianReport Report with task results
     */
    public function investigate(string $goal, array $context = []): GuardianReport
    {
        $tasks = $this->planner->plan($goal, $context);
        $summaryParts = [];

        foreach ($tasks as $task) {
            $result = $this->executeTask($task, $context);
            $summaryParts[] = "{$task->id}:{$result->status->value}";
        }

        GuardianTelemetry::event('investigation.finished', ['goal' => $goal, 'tasks' => count($tasks)]);

        return new GuardianReport(
            guardianType: 'engine',
            summary: implode('; ', $summaryParts),
            findings: [],
            meta: ['goal' => $goal],
        );
    }

    /**
     * Executes single task by delegating to registered executors.
     *
     * @param InvestigationTask $task Task to execute
     * @param array<string, mixed> $context Execution context
     * @return TaskResult Task result from first successful executor, or FAILED if none handled
     */
    private function executeTask(InvestigationTask $task, array $context): TaskResult
    {
        foreach ($this->executors as $executor) {
            $result = $executor($task, $context);
            if ($result->status !== TaskStatus::FAILED) {
                return $result;
            }
        }

        return new TaskResult(
            taskId: $task->id,
            status: TaskStatus::FAILED,
            error: 'No executor handled task',
        );
    }
}
