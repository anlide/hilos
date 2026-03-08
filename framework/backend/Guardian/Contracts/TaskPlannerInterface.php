<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\InvestigationTask;

interface TaskPlannerInterface
{
    /**
     * @param array<string, mixed> $context
     * @return list<InvestigationTask>
     */
    public function plan(string $goal, array $context = []): array;
}
