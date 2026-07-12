<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

/**
 * A named, fully-resolved recipe for one LLM conversation role.
 *
 * The unit of routing: callers ask the {@see LlmRouter} for a profile by key and
 * receive this immutable configuration. `placement` is a reserved extension
 * point for resource-aware placement (daemon-cluster) and is null today.
 */
final class LlmProfile
{
    /**
     * @param string $key Stable profile key owned by the caller's domain
     * @param LlmProvider $provider Explicit provider, not inferred from the key presence
     * @param string $url Endpoint base URL
     * @param string $model Model name
     * @param ?string $apiKey Credential (null for keyless local; required by external)
     * @param float $timeoutSec Request timeout in seconds
     * @param ?string $placement Reserved node/worker placement hint; null today
     */
    public function __construct(
        public readonly string $key,
        public readonly LlmProvider $provider,
        public readonly string $url,
        public readonly string $model,
        public readonly ?string $apiKey,
        public readonly float $timeoutSec,
        public readonly ?string $placement = null,
    ) {
    }
}
