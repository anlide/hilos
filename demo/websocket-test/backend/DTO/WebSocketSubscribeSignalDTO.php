<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO;

use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO as FrameworkWebSocketSubscribeSignalDTO;

/**
 * WebSocketSubscribeSignalDTO - DTO for WebSocket subscribe signal
 *
 * Represents a subscription signal sent from WebSocket client to chat agent.
 * Extends framework WebSocketSubscribeSignalDTO for chat-specific functionality.
 */
class WebSocketSubscribeSignalDTO extends FrameworkWebSocketSubscribeSignalDTO implements ChatMessageDTOInterface
{
    // Inherits all functionality from framework DTO
}

