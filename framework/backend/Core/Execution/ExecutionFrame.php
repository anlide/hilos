<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

/**
 * Immutable per-callback execution metadata: the agent currently running, the
 * WebSocket accept key it serves and the client-minted action it answers.
 *
 * Instances never mutate; derive a changed frame with withAgentId(),
 * withAcceptKey() or withRequestId(). Empty strings are normalized to null on
 * construction.
 */
final class ExecutionFrame
{
    public readonly ?string $agentId;

    public readonly ?string $acceptKey;

    public readonly ?string $requestId;

    /**
     * @param ?string $agentId Agent currently executing, or null outside an agent callback
     * @param ?string $acceptKey Current inbound WebSocket accept key, or null outside a WebSocket-scoped signal
     * @param ?string $requestId Client-minted request id of the action being answered, or null outside a tracked action
     */
    public function __construct(?string $agentId = null, ?string $acceptKey = null, ?string $requestId = null)
    {
        $this->agentId = self::normalize($agentId);
        $this->acceptKey = self::normalize($acceptKey);
        $this->requestId = self::normalize($requestId);
    }

    /**
     * Returns a copy of this frame with the agent id replaced.
     *
     * @param ?string $agentId Agent to scope to, or null outside an agent callback
     * @return self New frame carrying the given agent id and the rest of this frame unchanged
     */
    public function withAgentId(?string $agentId): self
    {
        return new self($agentId, $this->acceptKey, $this->requestId);
    }

    /**
     * Returns a copy of this frame with the WebSocket accept key replaced.
     *
     * @param ?string $acceptKey Accept key to scope to, or null outside a WebSocket-scoped signal
     * @return self New frame carrying the given accept key and the rest of this frame unchanged
     */
    public function withAcceptKey(?string $acceptKey): self
    {
        return new self($this->agentId, $acceptKey, $this->requestId);
    }

    /**
     * Returns a copy of this frame with the action request id replaced.
     *
     * @param ?string $requestId Request id to scope to, or null outside a tracked action
     * @return self New frame carrying the given request id and the rest of this frame unchanged
     */
    public function withRequestId(?string $requestId): self
    {
        return new self($this->agentId, $this->acceptKey, $requestId);
    }

    /**
     * Collapses an empty string to null so missing and blank values compare equal.
     *
     * @param ?string $value Raw agent id, accept key or request id
     * @return ?string Null when the value is null or empty, otherwise the value unchanged
     */
    private static function normalize(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
