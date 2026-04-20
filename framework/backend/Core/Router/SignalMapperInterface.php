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
     * Expand a DB emit signal into WebSocket deliveries.
     *
     * @return list<EmitFanoutItem>
     */
    public function mapDbEmit(SignalDTO $emit): array;

    /**
     * Expand an RT emit signal into WebSocket deliveries.
     *
     * @return list<EmitFanoutItem>
     */
    public function mapRtEmit(SignalDTO $emit): array;
}
