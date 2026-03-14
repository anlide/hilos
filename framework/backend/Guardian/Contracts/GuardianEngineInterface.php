<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianReport;

interface GuardianEngineInterface
{
    /**
     * Runs guardian investigation for the given goal.
     *
     * @param string $goal Investigation goal
     * @param array<string, mixed> $context Context data for investigation
     * @return GuardianReport Report with findings and metadata
     */
    public function investigate(string $goal, array $context = []): GuardianReport;
}
