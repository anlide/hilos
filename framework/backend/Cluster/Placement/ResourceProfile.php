<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;

/**
 * The numeric resource demand an agent brings to placement (HIL-182): its hard minimums and
 * soft preferences over a node's declared {@see NodeCapacities}.
 *
 * This is the task side of the resource-aware model fixed by the placement spike: a boolean
 * capability tag list stays the hard gate (an agent keeps declaring it through
 * {@see AgentDaemonInterface::requiredCapabilities()}); this profile adds the numeric layer
 * on top:
 *
 * - `minimums` are hard floors — the leader refuses to place the agent on a node whose
 *   declared capacity for a key is below the minimum, exactly like a missing tag;
 * - `preferences` are soft weights — among the nodes that clear the hard gate, the best-fit
 *   policy prefers the one offering the most weighted capacity, so a heavy worker lands on a
 *   strong node.
 *
 * The default is {@see none()}: no minimums and no preferences, so an agent that declares
 * nothing runs anywhere and best-fit falls back to the strongest capable node. Real per-agent
 * demands are filled in by the workloads HIL-120 introduces; this seam carries them.
 */
final class ResourceProfile
{
    /**
     * @param array<string, float> $minimums Hard capacity floors keyed by resource name
     * @param array<string, float> $preferences Soft preference weights keyed by resource name
     */
    private function __construct(
        public readonly array $minimums,
        public readonly array $preferences,
    ) {
    }

    /**
     * The empty profile: no hard minimums and no soft preferences, so the agent runs anywhere.
     *
     * @return self Empty profile
     */
    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * Builds a profile from explicit hard minimums and soft preferences.
     *
     * @param array<string, float> $minimums Hard capacity floors keyed by resource name
     * @param array<string, float> $preferences Soft preference weights keyed by resource name
     * @return self Resource profile
     */
    public static function create(array $minimums = [], array $preferences = []): self
    {
        return new self($minimums, $preferences);
    }

    /**
     * Reports whether the profile carries no numeric demand at all.
     *
     * @return bool True when there are no minimums and no preferences
     */
    public function isEmpty(): bool
    {
        return $this->minimums === [] && $this->preferences === [];
    }
}
