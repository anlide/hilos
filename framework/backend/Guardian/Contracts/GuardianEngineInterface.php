<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianReport;

interface GuardianEngineInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function investigate(string $goal, array $context = []): GuardianReport;
}
