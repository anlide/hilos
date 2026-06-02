<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

/**
 * Immutable per-callback execution metadata: the agent currently running and the
 * WebSocket accept key it serves.
 *
 * Instances never mutate; derive a changed frame with withAgentId() or
 * withAcceptKey(). Empty strings are normalized to null on construction.
 */
final class ExecutionFrame
{
    public readonly ?string $agentId;

    public readonly ?string $acceptKey;

    /**
     * @param ?string $agentId Agent currently executing, or null outside an agent callback
     * @param ?string $acceptKey Current inbound WebSocket accept key, or null outside a WebSocket-scoped signal
     */
    public function __construct(?string $agentId = null, ?string $acceptKey = null)
    {
        $this->agentId = self::normalize($agentId);
        $this->acceptKey = self::normalize($acceptKey);
    }

    /**
     * Returns a copy of this frame with the agent id replaced.
     *
     * @param ?string $agentId Agent to scope to, or null outside an agent callback
     * @return self New frame carrying the given agent id and the current accept key
     */
    public function withAgentId(?string $agentId): self
    {
        return new self($agentId, $this->acceptKey);
    }

    /**
     * Returns a copy of this frame with the WebSocket accept key replaced.
     *
     * @param ?string $acceptKey Accept key to scope to, or null outside a WebSocket-scoped signal
     * @return self New frame carrying the given accept key and the current agent id
     */
    public function withAcceptKey(?string $acceptKey): self
    {
        return new self($this->agentId, $acceptKey);
    }

    /**
     * Collapses an empty string to null so missing and blank values compare equal.
     *
     * @param ?string $value Raw agent id or accept key
     * @return ?string Null when the value is null or empty, otherwise the value unchanged
     */
    private static function normalize(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
