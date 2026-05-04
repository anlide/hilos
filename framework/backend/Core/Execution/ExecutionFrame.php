<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

/**
 * Immutable execution metadata for the currently handled worker callback.
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

    public function withAgentId(?string $agentId): self
    {
        return new self($agentId, $this->acceptKey);
    }

    public function withAcceptKey(?string $acceptKey): self
    {
        return new self($this->agentId, $acceptKey);
    }

    private static function normalize(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
