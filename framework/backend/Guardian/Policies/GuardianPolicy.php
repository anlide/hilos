<?php

declare(strict_types=1);

namespace Hilos\Guardian\Policies;

/**
 * Guardian policy configuration (name, limits, enabled capabilities).
 */
final class GuardianPolicy
{
    /**
     * Creates guardian policy.
     *
     * @param string $name Policy name
     * @param int $maxSteps Max investigation steps (default 8)
     * @param int $stepTimeoutMs Step timeout in ms (default 5000)
     * @param list<string> $enabledCapabilities Capability names to enable
     */
    public function __construct(
        public readonly string $name,
        public readonly int $maxSteps = 8,
        public readonly int $stepTimeoutMs = 5000,
        public readonly array $enabledCapabilities = [],
    ) {
    }
}
