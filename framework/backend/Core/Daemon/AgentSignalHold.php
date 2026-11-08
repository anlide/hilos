<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Throwable;

/** One master-owned pending obligation, including its released and active portions. */
final class AgentSignalHold
{
    public ?ParkedAgentSignal $parked;

    public ?SignalDispatch $dispatch = null;

    public bool $released = false;

    /** Captured originating stop-prefix identity. */
    public ?int $stopPrefixId = null;

    /** Independent final-browser prefix membership. */
    public ?int $browserPrefixId = null;

    public readonly string $agentId;

    public readonly ?string $correlationId;

    public readonly ?string $acceptKey;

    public readonly ?string $page;

    public readonly bool $subscribe;

    /**
     * @param int $id Monotonic owner-local identity, never reused
     * @param ParkedAgentSignal $parked Newly blocked envelope
     */
    public function __construct(public readonly int $id, ParkedAgentSignal $parked)
    {
        $this->parked = $parked;
        $this->agentId = $parked->agentId;
        $payload = $parked->signal->data;
        $this->correlationId = $payload instanceof CommandRequestDTO || $payload instanceof HttpRequestDTO ? $payload->correlationId : null;
        $this->acceptKey = $payload instanceof WebSocketPageSubscribeSignalDTO || $payload instanceof WebSocketPageUpdateSubscriptionSignalDTO
            ? $payload->acceptKey : null;
        $this->page = $this->acceptKey !== null ? ($payload->page ?? $parked->signal->signalName->getName()) : null;
        $this->subscribe = $payload instanceof WebSocketPageSubscribeSignalDTO;
    }

    /** @return bool Whether the obligation is blocked, rather than released or executing */
    public function isParked(): bool
    {
        return $this->parked !== null && !$this->released && $this->dispatch === null;
    }

    /** @return ?SignalDTO Original envelope, absent once settled */
    public function getSignal(): ?SignalDTO
    {
        return $this->parked?->signal ?? $this->dispatch?->getSignal();
    }

    /**
     * Releases every strong payload reference, including a canceled active cursor.
     *
     * @throws Throwable When the cursor's finalizer fails, after the hold's independent references clear
     */
    public function cancel(): void
    {
        try {
            $this->dispatch?->cancel();
        } finally {
            $this->dispatch = null;
            $this->parked = null;
            $this->released = false;
        }
    }
}
