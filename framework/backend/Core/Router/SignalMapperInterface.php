<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Router\DTO\EmitFanoutItem;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Maps EMIT_* daemon signals to concrete WebSocket fan-out items.
 */
interface SignalMapperInterface
{
    /**
     * Expands a DB emit signal into WebSocket deliveries.
     *
     * @param SignalDTO $emit Internal DB emit signal
     * @return list<EmitFanoutItem> Fan-out items queued for WebSocket delivery
     */
    public function mapDbEmit(SignalDTO $emit): array;

    /**
     * Expands an RT emit signal into WebSocket deliveries.
     *
     * @param SignalDTO $emit Internal runtime emit signal
     * @return list<EmitFanoutItem> Fan-out items queued for WebSocket delivery
     */
    public function mapRtEmit(SignalDTO $emit): array;
}
