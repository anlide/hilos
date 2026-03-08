<?php

declare(strict_types=1);

namespace Hilos\Guardian\Policies;

final class GuardianPolicy
{
    /**
     * @param list<string> $enabledCapabilities
     */
    public function __construct(
        public readonly string $name,
        public readonly int $maxSteps = 8,
        public readonly int $stepTimeoutMs = 5000,
        public readonly array $enabledCapabilities = [],
    ) {
    }
}
