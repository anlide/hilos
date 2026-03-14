<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\InvestigationTask;

/**
 * Interface for planning investigation tasks from goal and context.
 */
interface TaskPlannerInterface
{
    /**
     * Creates investigation plan from goal and context.
     *
     * @param string $goal Investigation goal
     * @param array<string, mixed> $context Context data
     * @return list<InvestigationTask> Ordered list of investigation tasks
     */
    public function plan(string $goal, array $context = []): array;
}
